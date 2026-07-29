<?php
/**
 * Tests for Meta CAPI server-side conversion tracking.
 *
 * Verifies that a completed WooCommerce order is forwarded to the Bushido
 * `/pixels/{pixelId}/events` endpoint with PII hashed (SHA-256, lowercase,
 * trimmed), is deduplicated via af_conversion_event_id order meta, and
 * includes attribution context plus checkout/user-agent metadata in the
 * pixel-event payload shape.
 *
 * Also verifies the consent gate: the af_marketing_consent verdict stored
 * on the order at checkout time (buyer's request context) wins over a live
 * consent evaluation at send time (admin/webhook/cron context); legacy
 * orders without a stored verdict fall back to the live check.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Commerce\Conversion_Tracking;
use AlmostFamous\Plugin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs/WC_Order.php';

global $af_test_orders, $af_test_wc_get_order_calls;
$af_test_orders             = array();
$af_test_wc_get_order_calls = array();

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $id ) {
		global $af_test_orders, $af_test_wc_get_order_calls;
		$af_test_wc_get_order_calls[] = $id;
		return $af_test_orders[ $id ] ?? null;
	}
}

class Test_Conversion_Tracking extends TestCase {

	private Conversion_Tracking $tracking;

	/**
	 * The pixel CUID returned from /pixels for tests in this file.
	 */
	private const PIXEL_ID = 'px_test_meta_1';

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		global $af_test_orders, $af_test_wc_get_order_calls;
		$af_test_orders             = array();
		$af_test_wc_get_order_calls = array();

		// Default-deny consent now gates Meta CAPI posting; grant consent for
		// the happy-path tests in this file.
		add_filter( 'almost_famous_default_consent', '__return_true' );

		// Bring up the Plugin singleton once — Conversion_Tracking pulls the
		// shared Api_Client through Plugin::get_instance()->api_client().
		Plugin::get_instance();

		$this->tracking = new Conversion_Tracking();

		// Reset captured-request store.
		$this->latest_post = null;
	}

	private ?array $latest_post = null;

	/**
	 * Mock the API so /pixels returns one meta pixel and /pixels/.../events
	 * returns whatever response the test wants. The events POST is captured
	 * into $this->latest_post for assertions.
	 *
	 * @param array $event_response Response envelope for the events POST.
	 */
	private function register_api_mock( array $event_response ): void {
		add_filter(
			'almost_famous/api/mock_response',
			function ( $existing, string $method, string $url, array $data, array $headers ) use ( $event_response ) {
				// /pixels list: short-circuit with a single meta pixel.
				if ( 'GET' === $method && false !== strpos( $url, '/pixels' ) && false === strpos( $url, '/events' ) ) {
					return array(
						'data'   => array(
							array(
								'id'       => self::PIXEL_ID,
								'platform' => 'meta',
								'name'     => 'Test Pixel',
								'status'   => 'active',
							),
						),
						'status' => 200,
					);
				}

				// /pixels/{id}/events POST: capture and return canned response.
				if ( 'POST' === $method && false !== strpos( $url, '/events' ) ) {
					$this->latest_post = array(
						'method' => $method,
						'url'    => $url,
						'data'   => $data,
						'headers' => $headers,
					);
					return $event_response;
				}

				return $existing;
			},
			10,
			5
		);
	}

	private function make_order( int $id = 100, string $email = 'Buyer@Example.COM  ', float $total = 49.99 ): \WC_Order {
		$order               = new \WC_Order();
		$order->id           = $id;
		$order->billing_email = $email;
		$order->total        = $total;
		$order->currency     = 'USD';
		$order->meta         = array(
			'af_attribution' => array(
				'platform'    => 'meta',
				'campaign_id' => 'cmp_42',
				'campaign'    => 'Spring',
				'source'      => 'facebook',
				'medium'      => 'cpc',
				'click_id'    => 'fbclid_xyz',
			),
		);

		$item = new class {
			public function get_product_id(): int {
				return 7;
			}
		};
		$order->items = array( $item );

		global $af_test_orders;
		$af_test_orders[ $id ] = $order;

		return $order;
	}

	public function test_payload_hashes_email_lowercased_and_trimmed(): void {
		$this->register_api_mock(
			array(
				'data'   => array( 'eventId' => 'evt_remote_1' ),
				'status' => 200,
			)
		);

		$order = $this->make_order( 100, '  Buyer@Example.COM  ' );

		$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/Test';
		$this->tracking->send_conversion( 100 );

		$this->assertNotNull( $this->latest_post );
		$this->assertSame( 'POST', $this->latest_post['method'] );
		$this->assertStringContainsString( '/pixels/' . self::PIXEL_ID . '/events', $this->latest_post['url'] );

		$expected_hash = hash( 'sha256', 'buyer@example.com' );
		$this->assertSame( array( $expected_hash ), $this->latest_post['data']['userData']['em'] );

		$this->assertNotSame( 'evt_remote_1', $order->meta['af_conversion_event_id'] );
		$this->assertSame(
			$order->meta['af_conversion_event_id'],
			$this->latest_post['headers']['X-Idempotency-Key']
		);
		$this->assertSame( 'sent', $order->meta['af_conversion_event_status'] );
		$this->assertTrue( $order->saved );
	}

	public function test_payload_includes_attribution_user_agent_and_checkout_url(): void {
		$this->register_api_mock(
			array(
				'data'   => array( 'eventId' => 'evt_remote_2' ),
				'status' => 200,
			)
		);

		$this->make_order( 101 );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (UnitTest)';
		$this->tracking->send_conversion( 101 );

		$payload = $this->latest_post['data'];
		$this->assertSame( 'Purchase', $payload['eventName'] );
		$this->assertSame( '101', $payload['customData']['order_id'] );
		$this->assertSame( 'meta', $payload['customData']['attribution']['platform'] );
		$this->assertSame( 'cmp_42', $payload['customData']['attribution']['campaign_id'] );
		$this->assertSame( 'facebook', $payload['customData']['attribution']['source'] );
		$this->assertSame( 'cpc', $payload['customData']['attribution']['medium'] );
		$this->assertSame( 'fbclid_xyz', $payload['customData']['attribution']['click_id'] );
		$this->assertSame( 'Mozilla/5.0 (UnitTest)', $payload['userData']['client_user_agent'] );
		$this->assertStringContainsString( '/checkout/order-received/101', $payload['customData']['source_url'] );
		$this->assertSame( array( '7' ), $payload['customData']['content_ids'] );
		$this->assertSame( 1, $payload['customData']['num_items'] );
	}

	public function test_dedupes_via_existing_event_id_meta(): void {
		$this->register_api_mock(
			array(
				'data'   => array( 'eventId' => 'evt_remote_3' ),
				'status' => 200,
			)
		);

		$order                                  = $this->make_order( 102 );
		$order->meta['af_conversion_event_id'] = 'evt_already_sent';

		$this->tracking->send_conversion( 102 );

		// Filter should never have been called.
		$this->assertNull( $this->latest_post );
		// And the existing event id is untouched.
		$this->assertSame( 'evt_already_sent', $order->meta['af_conversion_event_id'] );
	}

	public function test_event_id_falls_back_to_generated_uuid_when_api_omits_one(): void {
		$this->register_api_mock(
			array(
				'data'   => array(), // No event_id in response.
				'status' => 200,
			)
		);

		$order = $this->make_order( 103 );

		$this->tracking->send_conversion( 103 );

		$this->assertArrayHasKey( 'af_conversion_event_id', $order->meta );
		$this->assertNotEmpty( $order->meta['af_conversion_event_id'] );
	}

	public function test_api_error_keeps_stable_pending_event_for_retry(): void {
		$this->register_api_mock(
			array(
				'error'  => new AlmostFamous\Api\Api_Error( 'unknown_error', 'oops', '', 500 ),
				'status' => 500,
			)
		);

		$order = $this->make_order( 104 );

		$this->tracking->send_conversion( 104 );

		$this->assertArrayHasKey( 'af_conversion_event_id', $order->meta );
		$this->assertSame( 'pending', $order->meta['af_conversion_event_status'] );
		$this->assertSame(
			$order->meta['af_conversion_event_id'],
			$this->latest_post['headers']['X-Idempotency-Key']
		);
		$this->assertTrue( $order->saved );
		$this->assertSame( 1, $order->meta['af_conversion_retry_attempts'] );
		global $af_test_scheduled_events;
		$this->assertSame( array( 104 ), $af_test_scheduled_events['almost_famous_retry_conversion']['args'] );
	}

	public function test_non_success_status_without_error_envelope_remains_pending(): void {
		$this->register_api_mock(
			array(
				'data'   => array(),
				'status' => 302,
			)
		);

		$order = $this->make_order( 115 );
		$this->tracking->send_conversion( 115 );

		$this->assertSame( 'pending', $order->meta['af_conversion_event_status'] );
		$this->assertSame( 1, $order->meta['af_conversion_retry_attempts'] );
		$this->assertNotFalse(
			wp_next_scheduled( 'almost_famous_retry_conversion', array( 115 ) )
		);
	}

	public function test_event_identity_is_persisted_before_pixel_discovery_network_io(): void {
		$order                = $this->make_order( 110 );
		$persisted_before_get = false;
		add_filter(
			'almost_famous/api/mock_response',
			function ( $existing, string $method, string $url ) use ( $order, &$persisted_before_get ) {
				if ( 'GET' === $method && false !== strpos( $url, '/pixels' ) ) {
					$persisted_before_get = ! empty( $order->meta['af_conversion_event_id'] )
						&& 'pending' === ( $order->meta['af_conversion_event_status'] ?? '' )
						&& $order->saved;
					return array(
						'data'   => array( array( 'id' => self::PIXEL_ID, 'platform' => 'meta' ) ),
						'status' => 200,
					);
				}
				if ( 'POST' === $method && false !== strpos( $url, '/events' ) ) {
					return array( 'data' => array(), 'status' => 200 );
				}
				return $existing;
			},
			10,
			3
		);

		$this->tracking->send_conversion( 110 );

		$this->assertTrue( $persisted_before_get );
	}

	public function test_retry_reuses_the_same_event_and_idempotency_id(): void {
		$event_headers = array();
		$attempt       = 0;
		add_filter(
			'almost_famous/api/mock_response',
			function ( $existing, string $method, string $url, array $data, array $headers ) use ( &$event_headers, &$attempt ) {
				unset( $data );
				if ( 'GET' === $method && false !== strpos( $url, '/pixels' ) && false === strpos( $url, '/events' ) ) {
					return array(
						'data'   => array( array( 'id' => self::PIXEL_ID, 'platform' => 'meta' ) ),
						'status' => 200,
					);
				}
				if ( 'POST' === $method && false !== strpos( $url, '/events' ) ) {
					$event_headers[] = $headers['X-Idempotency-Key'] ?? '';
					++$attempt;
					return 1 === $attempt
						? array(
							'error'  => new AlmostFamous\Api\Api_Error( 'unknown_error', 'retry', '', 503 ),
							'status' => 503,
						)
						: array( 'data' => array(), 'status' => 200 );
				}
				return $existing;
			},
			10,
			5
		);

		$order = $this->make_order( 108 );
		$this->tracking->send_conversion( 108 );
		$pending_id = $order->meta['af_conversion_event_id'];
		$this->tracking->send_conversion( 108 );

		$this->assertCount( 2, $event_headers );
		$this->assertSame( $event_headers[0], $event_headers[1] );
		$this->assertSame( $pending_id, $event_headers[0] );
		$this->assertSame( 'sent', $order->meta['af_conversion_event_status'] );
	}

	public function test_active_order_lock_prevents_concurrent_send(): void {
		$this->register_api_mock( array( 'data' => array(), 'status' => 200 ) );
		$this->make_order( 109 );
		$lock = ( time() + 300 ) . ':other-request';
		update_option( 'af_conversion_lock_109', $lock );

		$this->tracking->send_conversion( 109 );

		global $af_test_wc_get_order_calls;
		$this->assertNull( $this->latest_post );
		$this->assertSame( $lock, get_option( 'af_conversion_lock_109' ) );
		$this->assertSame(
			array(),
			$af_test_wc_get_order_calls,
			'An order must not be loaded until this request owns its delivery lock.'
		);
		$this->assertNotFalse(
			wp_next_scheduled( 'almost_famous_retry_conversion', array( 109 ) ),
			'A lock contender must leave crash-recovery work behind.'
		);
	}

	public function test_missing_pixel_keeps_bounded_pending_retry(): void {
		$this->register_api_mock( array( 'data' => array(), 'status' => 200 ) );
		add_filter(
			'almost_famous/api/mock_response',
			static function ( $existing, string $method, string $url ) {
				if ( 'GET' === $method && false !== strpos( $url, '/pixels' ) ) {
					return array( 'data' => array(), 'status' => 200 );
				}
				return $existing;
			},
			20,
			3
		);
		$order = $this->make_order( 113 );

		$this->tracking->send_conversion( 113 );

		$this->assertSame( 'pending', $order->meta['af_conversion_event_status'] );
		$this->assertSame( 1, $order->meta['af_conversion_retry_attempts'] );
		$this->assertNotFalse(
			wp_next_scheduled( 'almost_famous_retry_conversion', array( 113 ) )
		);
	}

	public function test_lost_lease_is_not_released_and_stops_before_event_delivery(): void {
		$order = $this->make_order( 111 );
		add_filter(
			'almost_famous/api/mock_response',
			function ( $existing, string $method, string $url ) {
				if ( 'GET' === $method && false !== strpos( $url, '/pixels' ) ) {
					update_option( 'af_conversion_lock_111', ( time() + 300 ) . ':successor' );
					return array(
						'data'   => array( array( 'id' => self::PIXEL_ID, 'platform' => 'meta' ) ),
						'status' => 200,
					);
				}
				if ( 'POST' === $method && false !== strpos( $url, '/events' ) ) {
					$this->fail( 'A request that lost its lease must not deliver.' );
				}
				return $existing;
			},
			10,
			3
		);

		$this->tracking->send_conversion( 111 );

		$this->assertNotEmpty( $order->meta['af_conversion_event_id'] );
		$this->assertStringEndsWith( ':successor', (string) get_option( 'af_conversion_lock_111' ) );
		$this->assertNull( $this->latest_post );
	}

	public function test_worker_that_loses_lease_during_post_cannot_mark_event_sent(): void {
		$order = $this->make_order( 114 );
		add_filter(
			'almost_famous/api/mock_response',
			function ( $existing, string $method, string $url ) {
				if ( 'GET' === $method && false !== strpos( $url, '/pixels' ) ) {
					return array(
						'data'   => array( array( 'id' => self::PIXEL_ID, 'platform' => 'meta' ) ),
						'status' => 200,
					);
				}
				if ( 'POST' === $method && false !== strpos( $url, '/events' ) ) {
					update_option( 'af_conversion_lock_114', ( time() + 300 ) . ':successor' );
					return array( 'data' => array( 'eventId' => 'remote' ), 'status' => 200 );
				}
				return $existing;
			},
			10,
			3
		);

		$this->tracking->send_conversion( 114 );

		$this->assertSame( 'pending', $order->meta['af_conversion_event_status'] );
		$this->assertStringEndsWith( ':successor', (string) get_option( 'af_conversion_lock_114' ) );
		$this->assertNotFalse(
			wp_next_scheduled( 'almost_famous_retry_conversion', array( 114 ) )
		);
	}

	public function test_failed_delivery_retries_are_bounded(): void {
		$this->register_api_mock(
			array(
				'error'  => new AlmostFamous\Api\Api_Error( 'unknown_error', 'retry', '', 503 ),
				'status' => 503,
			)
		);
		$order = $this->make_order( 112 );

		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			$this->tracking->send_conversion( 112 );
			if ( $attempt <= 4 ) {
				$this->assertNotFalse( wp_next_scheduled( 'almost_famous_retry_conversion', array( 112 ) ) );
				wp_clear_scheduled_hook( 'almost_famous_retry_conversion', array( 112 ) );
			}
		}

		$this->assertSame( 5, $order->meta['af_conversion_retry_attempts'] );
		$this->assertFalse( wp_next_scheduled( 'almost_famous_retry_conversion', array( 112 ) ) );
		$this->assertSame( 'pending', $order->meta['af_conversion_event_status'] );
	}

	public function test_unknown_order_id_is_a_noop(): void {
		$this->register_api_mock( array( 'data' => array(), 'status' => 200 ) );

		$this->tracking->send_conversion( 9999 );

		$this->assertNull( $this->latest_post );
	}

	public function test_stored_consent_granted_bypasses_live_consent_check(): void {
		$this->register_api_mock(
			array(
				'data'   => array( 'eventId' => 'evt_remote_5' ),
				'status' => 200,
			)
		);

		// Live consent denies at send time (order completion runs in
		// admin/webhook/cron context where the buyer's CMP cookie is absent)…
		add_filter( 'almost_famous_default_consent', '__return_false' );

		// …but the verdict captured in the buyer's own checkout request says granted.
		$order                                = $this->make_order( 105 );
		$order->meta['af_marketing_consent'] = 'granted';

		$this->tracking->send_conversion( 105 );

		$this->assertNotNull( $this->latest_post );
		$this->assertNotEmpty( $order->meta['af_conversion_event_id'] );
		$this->assertSame( 'sent', $order->meta['af_conversion_event_status'] );
		$this->assertSame( 0, did_action( 'almost_famous/conversion/skipped_consent' ) );
	}

	public function test_stored_consent_denied_suppresses_send(): void {
		$this->register_api_mock(
			array(
				'data'   => array( 'eventId' => 'evt_remote_6' ),
				'status' => 200,
			)
		);

		// Live consent grants (setUp filter), but the buyer said no at checkout.
		$order                                = $this->make_order( 106 );
		$order->meta['af_marketing_consent'] = 'denied';

		$this->tracking->send_conversion( 106 );

		$this->assertNull( $this->latest_post );
		$this->assertArrayNotHasKey( 'af_conversion_event_id', $order->meta );
		$this->assertSame( 1, did_action( 'almost_famous/conversion/skipped_consent' ) );
	}

	public function test_legacy_order_without_stored_verdict_falls_back_to_live_check(): void {
		$this->register_api_mock(
			array(
				'data'   => array( 'eventId' => 'evt_remote_7' ),
				'status' => 200,
			)
		);

		// No af_marketing_consent meta on the order and live consent denies.
		add_filter( 'almost_famous_default_consent', '__return_false' );

		$this->make_order( 107 );

		$this->tracking->send_conversion( 107 );

		$this->assertNull( $this->latest_post );
		$this->assertSame( 1, did_action( 'almost_famous/conversion/skipped_consent' ) );
	}
}
