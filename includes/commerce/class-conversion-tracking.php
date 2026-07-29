<?php
/**
 * Meta CAPI server-side conversion event bridge.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Consent\Consent_Integration;
use AlmostFamous\Plugin;

/**
 * Meta CAPI server-side conversion event bridge.
 *
 * Hooks into WooCommerce order completion to send server-side conversion
 * events to the Bushido API, which forwards them to Meta CAPI for
 * accurate attribution without client-side pixel dependencies.
 *
 * Events are POSTed to `/pixels/{pixelId}/events`. The pixel id is
 * resolved from `/pixels` and cached locally; the first Meta pixel in
 * the org is used by default, overridable via the
 * `almost_famous/default_pixel_id` filter or the `af_default_pixel_id`
 * option.
 */
class Conversion_Tracking {

	/**
	 * Order meta key for stored conversion event ID (dedup).
	 *
	 * @var string
	 */
	private const EVENT_ID_META_KEY = 'af_conversion_event_id';

	/**
	 * Order meta key for pending/sent delivery state.
	 *
	 * @var string
	 */
	private const EVENT_STATUS_META_KEY = 'af_conversion_event_status';

	/**
	 * Conversion delivery status values.
	 */
	private const EVENT_STATUS_PENDING = 'pending';
	private const EVENT_STATUS_SENT    = 'sent';

	/**
	 * Retry metadata and bounded retry schedule.
	 */
	private const RETRY_ATTEMPTS_META_KEY = 'af_conversion_retry_attempts';
	private const RETRY_HOOK              = 'almost_famous_retry_conversion';
	private const MAX_RETRY_ATTEMPTS      = 4;
	private const RETRY_BASE_DELAY        = 60;

	/**
	 * Lock option prefix and stale-lock timeout.
	 */
	private const LOCK_OPTION_PREFIX = 'af_conversion_lock_';
	private const LOCK_TTL_SECONDS   = 300;

	/**
	 * Option key for the manually-pinned default pixel ID.
	 *
	 * @var string
	 */
	private const DEFAULT_PIXEL_OPTION = 'af_default_pixel_id';

	/**
	 * Transient key for the auto-resolved pixel ID cache.
	 *
	 * @var string
	 */
	private const PIXEL_CACHE_TRANSIENT = 'af_default_pixel_id_cache';

	/**
	 * How long to cache the resolved pixel ID before re-querying /pixels.
	 *
	 * @var int
	 */
	private const PIXEL_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_completed', array( $this, 'send_conversion' ), 10, 1 );
		add_action( self::RETRY_HOOK, array( $this, 'send_conversion' ), 10, 1 );
	}

	/**
	 * Send a conversion event for a completed WooCommerce order.
	 *
	 * @param int $order_id The completed order ID.
	 * @return void
	 */
	public function send_conversion( int $order_id ): void {
		$lock_token = $this->acquire_lock( $order_id );
		if ( '' === $lock_token ) {
			$this->schedule_safety_retry( $order_id );
			return;
		}

		try {
			// Load only after acquiring the lock. A WC_Order fetched before a
			// concurrent sender finishes can carry stale event status/meta and
			// cause a duplicate delivery even when lock acquisition succeeds.
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
			$this->send_locked( $order_id, $order, $lock_token );
		} finally {
			$this->release_lock( $order_id, $lock_token );
		}
	}

	/**
	 * Execute conversion delivery while holding the per-order lock.
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order instance.
	 * @param string    $lock_token Current lease ownership token.
	 * @return void
	 */
	private function send_locked( int $order_id, \WC_Order $order, string $lock_token ): void {
		$event_id     = (string) $order->get_meta( self::EVENT_ID_META_KEY );
		$event_status = (string) $order->get_meta( self::EVENT_STATUS_META_KEY );

		// Legacy rows predate explicit delivery state. Their event ID was only
		// written after a successful call, so an empty status means sent.
		if (
			'' !== $event_id
			&& ( '' === $event_status || self::EVENT_STATUS_SENT === $event_status )
		) {
			return;
		}

		// Honour the buyer's consent posture. Order completion usually runs in
		// admin/webhook/cron context where the BUYER's CMP cookie does not
		// exist, so a live evaluation here would read the wrong request's
		// cookies. The verdict captured at order-creation time (in the buyer's
		// own request, where the CMP cookie IS present) is stored as order
		// meta by Woo_Integration and wins; only legacy orders without a
		// stored verdict fall back to a live check, keeping the
		// deny-by-default posture.
		$stored_consent = (string) $order->get_meta( Woo_Integration::CONSENT_META_KEY );

		if ( Woo_Integration::CONSENT_DENIED === $stored_consent ) {
			/**
			 * Fires when a conversion event is suppressed by the consent gate.
			 *
			 * @since 1.0.0
			 * @param int       $order_id Order being skipped.
			 * @param \WC_Order $order    Order instance.
			 */
			do_action( 'almost_famous/conversion/skipped_consent', $order_id, $order );
			return;
		}

		if ( Woo_Integration::CONSENT_GRANTED !== $stored_consent ) {
			// Legacy order — no verdict was captured at checkout time.
			// Consent detection is local-only (no API client needed).
			$consent = class_exists( Consent_Integration::class )
				? new Consent_Integration()
				: null;
			if ( $consent && ! $consent->has_consent() ) {
				/** This action is documented in includes/commerce/class-conversion-tracking.php */
				do_action( 'almost_famous/conversion/skipped_consent', $order_id, $order );
				return;
			}
		}

		if ( '' === $event_id ) {
			$event_id = wp_generate_uuid4();
			if ( '' === $event_id ) {
				return;
			}
			$order->update_meta_data( self::EVENT_ID_META_KEY, $event_id );
			$order->update_meta_data( self::EVENT_STATUS_META_KEY, self::EVENT_STATUS_PENDING );
			// Persist the stable identity before pixel discovery or event
			// delivery can perform network I/O.
			$order->save();
			// Create recovery work before any external I/O. If this PHP worker
			// dies or loses an HTTP response, the stable event is retried with
			// the same idempotency key.
			$this->schedule_safety_retry( $order_id );
		}

		if ( ! $this->renew_lock( $order_id, $lock_token ) ) {
			return;
		}

		$client   = Plugin::get_instance()->api_client();
		$pixel_id = $this->resolve_default_pixel_id( $client );

		if ( '' === $pixel_id ) {
			/**
			 * Fires when conversion tracking fires but no pixel is configured.
			 *
			 * Listeners can surface an admin notice prompting the user to
			 * create a pixel from the Pixels admin page.
			 *
			 * @since 1.0.0
			 * @param int $order_id Order that couldn't be tracked.
			 */
			do_action( 'almost_famous/conversion/no_pixel', $order_id );
			$this->schedule_retry( $order_id, $order );
			return;
		}

		$attribution = $order->get_meta( 'af_attribution' );
		if ( ! is_array( $attribution ) ) {
			$attribution = array();
		}

		$payload = $this->build_event_payload( $order, $attribution );

		if ( ! $this->renew_lock( $order_id, $lock_token ) ) {
			return;
		}

		$result = $client->post(
			'/pixels/' . rawurlencode( $pixel_id ) . '/events',
			$payload,
			$event_id
		);

		// A network call may outlive the renewable lease. A stale worker must
		// never overwrite the state written by a successor.
		if ( ! $this->renew_lock( $order_id, $lock_token ) ) {
			return;
		}

		$status = (int) ( $result['status'] ?? 0 );
		if ( ! isset( $result['error'] ) && $status >= 200 && $status < 300 ) {
			$order->update_meta_data( self::EVENT_STATUS_META_KEY, self::EVENT_STATUS_SENT );
			$order->update_meta_data( self::RETRY_ATTEMPTS_META_KEY, 0 );
			$order->save();
			wp_clear_scheduled_hook( self::RETRY_HOOK, array( $order_id ) );

			/**
			 * Fires after a conversion event is successfully sent.
			 *
			 * @param int    $order_id The WooCommerce order ID.
			 * @param string $event_id The conversion event ID.
			 * @param array  $payload  The conversion payload sent.
			 */
			do_action( 'almost_famous/conversion/sent', $order_id, $event_id, $payload );
		} else {
			$this->schedule_retry( $order_id, $order );
			/**
			 * Fires when a conversion event fails to send.
			 *
			 * @param int   $order_id The WooCommerce order ID.
			 * @param array $result   The API error response.
			 */
			do_action( 'almost_famous/conversion/failed', $order_id, $result );
		}
	}

	/**
	 * Atomically acquire a per-order conversion lock.
	 *
	 * @param int $order_id Order ID.
	 * @return string Ownership token, or empty string when another sender owns it.
	 */
	private function acquire_lock( int $order_id ): string {
		$key   = self::LOCK_OPTION_PREFIX . $order_id;
		$token = wp_generate_uuid4();
		$value = ( time() + self::LOCK_TTL_SECONDS ) . ':' . $token;

		if ( add_option( $key, $value, '', 'no' ) ) {
			return $token;
		}

		$existing   = (string) get_option( $key, '' );
		$expires_at = (int) strtok( $existing, ':' );
		if (
			$expires_at > 0
			&& $expires_at <= time()
			&& $this->compare_and_swap_lock( $key, $existing, $value )
		) {
			return $token;
		}

		return '';
	}

	/**
	 * Renew a lock lease only while this request still owns it.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $token    Ownership token.
	 * @return bool True when the lease is renewed.
	 */
	private function renew_lock( int $order_id, string $token ): bool {
		$key      = self::LOCK_OPTION_PREFIX . $order_id;
		$existing = (string) get_option( $key, '' );
		if ( ! $this->lock_value_is_owned_by( $existing, $token ) ) {
			return false;
		}
		$renewed = ( time() + self::LOCK_TTL_SECONDS ) . ':' . $token;
		return $existing === $renewed
			|| $this->compare_and_swap_lock( $key, $existing, $renewed );
	}

	/**
	 * Release a lock only when this request still owns it.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $token    Ownership token.
	 * @return void
	 */
	private function release_lock( int $order_id, string $token ): void {
		$key      = self::LOCK_OPTION_PREFIX . $order_id;
		$existing = (string) get_option( $key, '' );
		if ( $this->lock_value_is_owned_by( $existing, $token ) ) {
			$this->delete_lock_if_value( $key, $existing );
		}
	}

	/**
	 * Determine whether a serialized lease belongs to a token.
	 *
	 * @param string $value Serialized expiry and owner token.
	 * @param string $token Expected ownership token.
	 * @return bool True when owned by the token.
	 */
	private function lock_value_is_owned_by( string $value, string $token ): bool {
		$parts = explode( ':', $value, 2 );
		return 2 === count( $parts )
			&& '' !== $parts[1]
			&& hash_equals( $token, $parts[1] );
	}

	/**
	 * Atomically replace one exact lock value when the WordPress DB is
	 * available. The fallback is used by the isolated unit-test store.
	 *
	 * @param string $key         Option key.
	 * @param string $expected    Exact existing lease.
	 * @param string $replacement Exact replacement lease.
	 * @return bool True when swapped.
	 */
	private function compare_and_swap_lock( string $key, string $expected, string $replacement ): bool {
		global $wpdb;
		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic lease CAS requires matching the exact owned option value.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$replacement,
					$key,
					$expected
				)
			);
			if ( 1 === $updated ) {
				wp_cache_delete( $key, 'options' );
				return true;
			}
			return false;
		}

		if ( (string) get_option( $key, '' ) !== $expected ) {
			return false;
		}
		if ( ! delete_option( $key ) ) {
			return false;
		}
		return add_option( $key, $replacement, '', 'no' );
	}

	/**
	 * Delete an exact owned lease without deleting a successor's value.
	 *
	 * @param string $key      Option key.
	 * @param string $expected Exact owned lease.
	 * @return bool True when deleted or already replaced.
	 */
	private function delete_lock_if_value( string $key, string $expected ): bool {
		global $wpdb;
		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic owned release must not delete a successor's lease.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$key,
					$expected
				)
			);
			wp_cache_delete( $key, 'options' );
			return 1 === $deleted;
		}

		if ( (string) get_option( $key, '' ) !== $expected ) {
			return true;
		}
		return delete_option( $key );
	}

	/**
	 * Schedule a bounded idempotent retry after a failed delivery.
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order instance.
	 * @return void
	 */
	private function schedule_retry( int $order_id, \WC_Order $order ): void {
		$attempt = (int) $order->get_meta( self::RETRY_ATTEMPTS_META_KEY ) + 1;
		$order->update_meta_data( self::RETRY_ATTEMPTS_META_KEY, $attempt );
		$order->update_meta_data( self::EVENT_STATUS_META_KEY, self::EVENT_STATUS_PENDING );
		$order->save();

		if ( $attempt > self::MAX_RETRY_ATTEMPTS ) {
			return;
		}
		$args = array( $order_id );
		if ( false !== wp_next_scheduled( self::RETRY_HOOK, $args ) ) {
			return;
		}
		$delay = self::RETRY_BASE_DELAY * ( 2 ** ( $attempt - 1 ) );
		wp_schedule_single_event( time() + $delay, self::RETRY_HOOK, $args );
	}

	/**
	 * Ensure crash recovery exists without consuming a retry attempt.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function schedule_safety_retry( int $order_id ): void {
		$args = array( $order_id );
		if ( false === wp_next_scheduled( self::RETRY_HOOK, $args ) ) {
			wp_schedule_single_event(
				time() + self::RETRY_BASE_DELAY,
				self::RETRY_HOOK,
				$args
			);
		}
	}

	/**
	 * Resolve the pixel id to send events against.
	 *
	 * Order of preference:
	 *   1. `almost_famous/default_pixel_id` filter (lets site code pick).
	 *   2. `af_default_pixel_id` option (user-pinned in Settings).
	 *   3. First Meta pixel returned by `/pixels` (auto-discovered, cached).
	 *
	 * @param \AlmostFamous\Api\Api_Client $client API client.
	 * @return string Pixel CUID or empty string if none configured.
	 */
	private function resolve_default_pixel_id( \AlmostFamous\Api\Api_Client $client ): string {
		$pinned = (string) get_option( self::DEFAULT_PIXEL_OPTION, '' );

		/**
		 * Filter the default pixel ID used for CAPI conversion events.
		 *
		 * @param string $pixel_id Currently-pinned pixel id (empty if none).
		 */
		$filtered = (string) apply_filters( 'almost_famous/default_pixel_id', $pinned );

		if ( '' !== $filtered ) {
			return $filtered;
		}

		$cached = get_transient( self::PIXEL_CACHE_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$pixels = $client->get_pixels();
		foreach ( $pixels as $pixel ) {
			$platform = isset( $pixel['platform'] ) ? strtolower( (string) $pixel['platform'] ) : '';
			$id       = isset( $pixel['id'] ) ? (string) $pixel['id'] : '';
			if ( 'meta' === $platform && '' !== $id ) {
				set_transient( self::PIXEL_CACHE_TRANSIENT, $id, self::PIXEL_CACHE_TTL );
				return $id;
			}
		}

		return '';
	}

	/**
	 * Build the pixel event payload (matches af-contracts CreatePixelEventSchema).
	 *
	 * @param \WC_Order $order       The WooCommerce order.
	 * @param array     $attribution Attribution data from order meta.
	 * @return array Payload conforming to {eventName, eventTime, userData?, customData?}.
	 */
	private function build_event_payload( \WC_Order $order, array $attribution ): array {
		$product_ids = array();

		foreach ( $order->get_items() as $item ) {
			if ( method_exists( $item, 'get_product_id' ) ) {
				$product_ids[] = $item->get_product_id();
			}
		}

		$email        = strtolower( trim( (string) $order->get_billing_email() ) );
		$hashed_email = '' !== $email ? hash( 'sha256', $email ) : '';
		$user_agent   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		$user_data = array();
		if ( '' !== $hashed_email ) {
			$user_data['em'] = array( $hashed_email );
		}
		if ( '' !== $user_agent ) {
			$user_data['client_user_agent'] = $user_agent;
		}

		$custom_data = array(
			'currency'     => $order->get_currency(),
			'value'        => (float) $order->get_total(),
			'order_id'     => (string) $order->get_id(),
			'num_items'    => count( $product_ids ),
			'content_ids'  => array_values( array_map( 'strval', $product_ids ) ),
			'content_type' => 'product',
			'source_url'   => $order->get_checkout_order_received_url(),
			// Per-install attribution — backend groups events by source_site
			// so multi-site operators can see which install drove which sale.
			'source_site'  => home_url(),
			'attribution'  => array(
				'platform'    => (string) ( $attribution['platform'] ?? '' ),
				'campaign_id' => (string) ( $attribution['campaign_id'] ?? '' ),
				'campaign'    => (string) ( $attribution['campaign'] ?? '' ),
				'source'      => (string) ( $attribution['source'] ?? '' ),
				'medium'      => (string) ( $attribution['medium'] ?? '' ),
				'click_id'    => (string) ( $attribution['click_id'] ?? '' ),
			),
		);

		$payload = array(
			'eventName'  => 'Purchase',
			'eventTime'  => time(),
			'customData' => $custom_data,
		);
		if ( array() !== $user_data ) {
			$payload['userData'] = $user_data;
		}

		return $payload;
	}
}
