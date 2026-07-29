<?php
/**
 * Campaign state change and cache invalidation webhook handlers.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Webhooks;

use AlmostFamous\Api\Api_Cache;

/**
 * Campaign state change and cache invalidation webhook handlers.
 *
 * Dispatches incoming webhook events to the appropriate handler method.
 * Each handler invalidates relevant caches and fires WordPress action hooks
 * for extensibility.
 */
class Webhook_Handlers {

	/**
	 * API cache instance.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $cache;

	/**
	 * Map of event types to handler method names.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_MAP = array(
		'campaign.updated'          => 'handle_campaign_updated',
		'platform.status_changed'   => 'handle_platform_status_changed',
		'billing.changed'           => 'handle_billing_changed',
		'platform.degraded'         => 'handle_platform_degraded',
		'organic.traction_detected' => 'handle_organic_traction_detected',
	);

	/**
	 * Constructor.
	 *
	 * @param Api_Cache $cache The transient cache manager.
	 */
	public function __construct( Api_Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Dispatch a webhook event to the appropriate handler.
	 *
	 * @param string $event_type The webhook event type.
	 * @param array  $payload    The full webhook payload.
	 * @return void
	 */
	public function dispatch( string $event_type, array $payload ): void {
		$method = self::EVENT_MAP[ $event_type ] ?? null;

		if ( null !== $method && method_exists( $this, $method ) ) {
			$this->{$method}( $payload );
		}

		/**
		 * Fires for any webhook event, allowing custom handling.
		 *
		 * @param string $event_type The webhook event type.
		 * @param array  $payload    The full webhook payload.
		 */
		do_action( 'almost_famous/webhook/event', $event_type, $payload );
	}

	/**
	 * Handle campaign.updated event.
	 *
	 * Clears the campaign cache for the affected account.
	 *
	 * @param array $payload The webhook payload.
	 * @return void
	 */
	public function handle_campaign_updated( array $payload ): void {
		$account_id  = sanitize_text_field( $payload['account_id'] ?? '' );
		$campaign_id = sanitize_text_field( $payload['campaign_id'] ?? '' );

		if ( ! empty( $account_id ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $account_id ) );
			$this->cache->delete_by_prefix( 'campaigns_' . $account_id );
		}

		if ( ! empty( $campaign_id ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $campaign_id ) );
		}

		/**
		 * Fires when a campaign is updated via webhook.
		 *
		 * @param array $payload The webhook payload.
		 */
		do_action( 'almost_famous/campaign/updated', $payload );
	}

	/**
	 * Handle platform.status_changed event.
	 *
	 * Clears the platform status cache.
	 *
	 * @param array $payload The webhook payload.
	 * @return void
	 */
	public function handle_platform_status_changed( array $payload ): void {
		$this->cache->delete( $this->cache->build_key( 'platform_status' ) );
		$this->cache->delete_by_prefix( 'platform_status' );

		/**
		 * Fires when platform status changes via webhook.
		 *
		 * @param array $payload The webhook payload.
		 */
		do_action( 'almost_famous/platform/status_changed', $payload );
	}

	/**
	 * Handle billing.changed event.
	 *
	 * @param array $payload The webhook payload.
	 * @return void
	 */
	public function handle_billing_changed( array $payload ): void {
		/**
		 * Fires when billing changes via webhook.
		 *
		 * @param array $payload The webhook payload.
		 */
		do_action( 'almost_famous/billing/changed', $payload );
	}

	/**
	 * Handle platform.degraded event.
	 *
	 * Surfaces a dashboard notification and clears the affected campaign's
	 * caches so the next render reflects the backend's own remediation.
	 * (Budget redistribution happens backend-side; there is no public
	 * redistribute endpoint for the plugin to call.)
	 *
	 * @param array $payload The webhook payload.
	 * @return void
	 */
	public function handle_platform_degraded( array $payload ): void {
		$campaign_id = sanitize_text_field( $payload['campaign_id'] ?? '' );
		$platform    = sanitize_text_field( $payload['platform'] ?? '' );

		if ( empty( $campaign_id ) ) {
			return;
		}

		$notification = array(
			'campaign_id' => $campaign_id,
			'platform'    => $platform,
			'details'     => is_array( $payload['details'] ?? null ) ? $payload['details'] : array(),
			'timestamp'   => gmdate( 'c' ),
		);

		set_transient( 'af_redistribution_notice_' . $campaign_id, $notification, DAY_IN_SECONDS );

		// Clear campaign caches so the dashboard refetches fresh state.
		$this->cache->delete( $this->cache->build_key( 'campaigns', $campaign_id ) );
		$this->cache->delete_by_prefix( 'campaigns_' );

		/**
		 * Fires when the backend reports a degraded platform for a campaign.
		 *
		 * @param array $payload The original webhook payload.
		 */
		do_action( 'almost_famous/campaign/platform_degraded', $payload );
	}

	/**
	 * Handle organic.traction_detected event.
	 *
	 * Stores a dashboard notification so the site owner can act on the
	 * traction signal (e.g. duplicate a campaign toward that audience).
	 * Automatic retargeting-campaign creation is a backend concern; the
	 * public API has no retarget endpoint.
	 *
	 * @param array $payload The webhook payload.
	 * @return void
	 */
	public function handle_organic_traction_detected( array $payload ): void {
		$platform   = sanitize_text_field( $payload['platform'] ?? '' );
		$content_id = sanitize_text_field( $payload['content_id'] ?? '' );

		if ( empty( $platform ) || empty( $content_id ) ) {
			return;
		}

		$notification = array(
			'source_platform' => $platform,
			'content_id'      => $content_id,
			'content_type'    => sanitize_text_field( $payload['content_type'] ?? '' ),
			'metrics'         => is_array( $payload['metrics'] ?? null ) ? $payload['metrics'] : array(),
			'timestamp'       => gmdate( 'c' ),
		);

		set_transient( 'af_retarget_notice_' . $content_id, $notification, DAY_IN_SECONDS );

		/**
		 * Fires when organic traction is detected for promoted content.
		 *
		 * @param array $payload The original webhook payload.
		 */
		do_action( 'almost_famous/campaign/organic_traction', $payload );
	}
}
