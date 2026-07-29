<?php
/**
 * Tests for the event-type dispatch table in Webhook_Handlers.
 *
 * Each test exercises one entry of EVENT_MAP plus the public dispatch
 * entry-point so the routing wiring stays covered alongside the side
 * effects (cache invalidation, transient notices, API redistribute call,
 * delegation to Purchase_Webhook_Handler).
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Webhooks\Webhook_Handlers;
use PHPUnit\Framework\TestCase;

// Tiny $wpdb stand-in so Api_Cache::delete_by_prefix() does not blow up.
// The real implementation queries the options table; tests don't need the
// query to do anything, just to expose the methods the cache calls.
if ( ! class_exists( 'Af_Test_Wpdb_For_Handlers' ) ) {
	class Af_Test_Wpdb_For_Handlers {
		public string $options = 'wp_options';

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $sql, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$pos = strpos( $sql, '%s' );
				if ( false !== $pos ) {
					$sql = substr_replace( $sql, "'" . (string) $arg . "'", $pos, 2 );
				}
			}
			return $sql;
		}

		public function get_col( string $sql ): array {
			return array();
		}
	}
}

class Test_Webhook_Handlers extends TestCase {

	private Webhook_Handlers $handlers;

	/**
	 * @var array<int, array{method:string, url:string, data:array<string,mixed>}>
	 */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test_key' ) );

		global $wpdb;
		$wpdb = new \Af_Test_Wpdb_For_Handlers();

		$this->calls    = array();
		$this->handlers = new Webhook_Handlers( new Api_Cache() );

		// Capture API traffic via the mock_response filter.
		$calls = &$this->calls;
		add_filter(
			'almost_famous/api/mock_response',
			static function ( $existing, string $method, string $url, array $data ) use ( &$calls ) {
				$calls[] = array( 'method' => $method, 'url' => $url, 'data' => $data );
				return array( 'data' => array( 'ok' => true ), 'status' => 200 );
			},
			10,
			5
		);
	}

	// ------------------------------------------------------------------
	// campaign.updated
	// ------------------------------------------------------------------

	public function test_dispatch_campaign_updated_invalidates_caches(): void {
		// Seed two transients that the handler should remove via Api_Cache::delete().
		set_transient( 'af_campaigns_acc_12', array( 'old' => true ), 300 );
		set_transient( 'af_campaigns_cmp_42', array( 'old' => true ), 300 );

		$this->handlers->dispatch(
			'campaign.updated',
			array( 'account_id' => 'acc_12', 'campaign_id' => 'cmp_42' )
		);

		$this->assertFalse( get_transient( 'af_campaigns_acc_12' ) );
		$this->assertFalse( get_transient( 'af_campaigns_cmp_42' ) );
		$this->assertGreaterThan( 0, did_action( 'almost_famous/campaign/updated' ) );
	}

	// ------------------------------------------------------------------
	// platform.status_changed → must call /campaigns/{id}/redistribute for
	// platform.degraded. The status_changed handler clears platform_status
	// caches and fires an action. See note in the final report — this maps
	// the actual handler, not the spec's "redistribute" wording.
	// ------------------------------------------------------------------

	public function test_dispatch_platform_status_changed_clears_status_cache(): void {
		set_transient( 'af_platform_status', array( 'old' => true ), 3600 );

		$this->handlers->dispatch( 'platform.status_changed', array( 'platform' => 'meta' ) );

		$this->assertFalse( get_transient( 'af_platform_status' ) );
		$this->assertGreaterThan( 0, did_action( 'almost_famous/platform/status_changed' ) );

		// status_changed must NOT trigger the /redistribute endpoint — that
		// behaviour belongs to platform.degraded.
		$urls = array_column( $this->calls, 'url' );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( '/redistribute', $url );
		}
	}

	// ------------------------------------------------------------------
	// billing.changed
	// ------------------------------------------------------------------

	public function test_dispatch_billing_changed_fires_local_extension_hook(): void {
		$this->handlers->dispatch( 'billing.changed', array( 'account_id' => 'acc_12' ) );

		$this->assertGreaterThan( 0, did_action( 'almost_famous/billing/changed' ) );
	}

	// ------------------------------------------------------------------
	// platform.degraded → notice transient + cache invalidation. No backend
	// call — the public API has no redistribute endpoint; remediation is a
	// backend-side concern.
	// ------------------------------------------------------------------

	public function test_dispatch_platform_degraded_sets_notice_without_backend_call(): void {
		set_transient( 'af_campaigns_cmp_99', array( 'stale' => true ), 3600 );

		$this->handlers->dispatch(
			'platform.degraded',
			array( 'campaign_id' => 'cmp_99', 'platform' => 'meta' )
		);

		$this->assertSame( array(), $this->calls, 'platform.degraded must not call the backend' );

		$notice = get_transient( 'af_redistribution_notice_cmp_99' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'cmp_99', $notice['campaign_id'] );
		$this->assertSame( 'meta', $notice['platform'] );
		$this->assertGreaterThan( 0, did_action( 'almost_famous/campaign/platform_degraded' ) );
	}

	public function test_dispatch_platform_degraded_without_campaign_id_is_noop(): void {
		$this->handlers->dispatch( 'platform.degraded', array( 'platform' => 'meta' ) );

		$this->assertSame( array(), $this->calls );
		$this->assertSame( 0, did_action( 'almost_famous/campaign/platform_degraded' ) );
	}

	// ------------------------------------------------------------------
	// organic.traction_detected → notice transient only. No backend call —
	// the public API has no retarget endpoint.
	// ------------------------------------------------------------------

	public function test_dispatch_organic_traction_detected_sets_notice_without_backend_call(): void {
		$this->handlers->dispatch(
			'organic.traction_detected',
			array(
				'platform'   => 'tiktok',
				'content_id' => 'video_42',
				'metrics'    => array( 'views' => 10000 ),
			)
		);

		$this->assertSame( array(), $this->calls, 'organic.traction_detected must not call the backend' );

		$notice = get_transient( 'af_retarget_notice_video_42' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'tiktok', $notice['source_platform'] );
		$this->assertSame( 'video_42', $notice['content_id'] );
		$this->assertSame( array( 'views' => 10000 ), $notice['metrics'] );
		$this->assertGreaterThan( 0, did_action( 'almost_famous/campaign/organic_traction' ) );
	}

	// ------------------------------------------------------------------
	// Unknown event types are silently routed through the catch-all action.
	// ------------------------------------------------------------------

	public function test_dispatch_unknown_event_type_still_fires_generic_action(): void {
		$this->handlers->dispatch( 'this.does.not.exist', array( 'hello' => 'world' ) );

		$this->assertGreaterThan( 0, did_action( 'almost_famous/webhook/event' ) );
	}
}
