<?php
/**
 * Tests Api_Proxy WRITE endpoints (create/update/launch/pause/resume/archive
 * /duplicate/refund/audience CRUD). Asserts input forwarding, success-response
 * cache invalidation, and {error, meta} envelope. CSRF / nonce gating already
 * lives in Test_Api_Proxy_Csrf.php — not duplicated here.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Api_Proxy;
use PHPUnit\Framework\TestCase;

/**
 * Minimal $wpdb fake that records LIKE patterns and returns no rows so
 * delete_by_prefix() can run without touching a real database.
 */
class Test_Api_Proxy_Write_FakeWpdb {

	public string $options = 'wp_options';

	/**
	 * @var array<int,string>
	 */
	public array $like_patterns = array();

	public function esc_like( string $value ): string {
		return $value;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		if ( isset( $args[0] ) && is_string( $args[0] ) ) {
			$this->like_patterns[] = $args[0];
		}
		return $sql;
	}

	/**
	 * @return array<int,string>
	 */
	public function get_col( mixed $query ): array {
		unset( $query );
		return array();
	}
}

class Test_Api_Proxy_Write extends TestCase {

	private Api_Proxy $proxy;
	private Api_Cache $cache;
	private Test_Api_Proxy_Write_FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test_proxy_write' ) );

		$this->cache = new Api_Cache();
		$this->proxy = new Api_Proxy( new Api_Client( $auth ), $this->cache );

		// Install fake $wpdb for cache prefix invalidation.
		$this->wpdb = new Test_Api_Proxy_Write_FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function request_with_json( string $method, array $body, array $params = array() ): WP_REST_Request {
		$req = new WP_REST_Request( $method, '/almost-famous/v1/' );
		$req->set_body_params( $body );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function last_request_args(): array {
		$reqs = af_test_get_http_requests();
		return $reqs[ array_key_last( $reqs ) ]['args'] ?? array();
	}

	private function last_request_url(): string {
		$reqs = af_test_get_http_requests();
		return (string) ( $reqs[ array_key_last( $reqs ) ]['url'] ?? '' );
	}

	// -----------------------------------------------------------------------
	// create_campaign
	// -----------------------------------------------------------------------

	public function test_create_campaign_posts_to_backend_and_invalidates_list_cache(): void {
		// Pre-seed a "campaigns_list" cache entry so we can observe invalidation
		// happen via the fake $wpdb LIKE pattern recording.
		$this->cache->set( 'af_campaigns_listkey', array( 'id' => 'cmp_seed' ), 'campaigns' );

		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_new' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array( 'name' => 'Spring Drop', 'budgetDaily' => 25 ) );
		$resp = $this->proxy->create_campaign( $req );

		$this->assertSame( 201, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( array( 'id' => 'cmp_new' ), $body['data'] );

		$args = $this->last_request_args();
		$this->assertSame( 'POST', $args['method'] );
		$decoded = json_decode( (string) $args['body'], true );
		$this->assertSame( 'Spring Drop', $decoded['name'] );
		$this->assertSame( 25, $decoded['budgetDaily'] );

		// Cache invalidation kicked off a LIKE query for 'campaigns_'.
		$this->assertNotEmpty( $this->wpdb->like_patterns );
		$this->assertStringContainsString( 'campaigns_', $this->wpdb->like_patterns[0] );
	}

	public function test_body_sanitization_strips_identity_fields_but_keeps_credential_id(): void {
		// orgId/apiKey/stripeCustomerId are key-derived identities the caller
		// must never override; credentialId is a legitimate required body field
		// on several backend contracts (audience create, per-platform asset
		// upload) whose org ownership the backend validates itself. Regression:
		// an earlier hardening pass stripped credentialId too, making those
		// contracts unsatisfiable through the proxy.
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_new' ) ) ),
				'headers'  => array(),
			)
		);

		$req = $this->request_with_json(
			'POST',
			array(
				'name'             => 'Identity Test',
				'orgId'            => 'org_evil',
				'organizationId'   => 'org_evil2',
				'apiKey'           => 'af_live_stolen',
				'stripeCustomerId' => 'cus_evil',
				'credentialId'     => 'cred_legit',
			)
		);
		$this->proxy->create_campaign( $req );

		$decoded = json_decode( (string) $this->last_request_args()['body'], true );
		$this->assertSame( 'cred_legit', $decoded['credentialId'] );
		$this->assertArrayNotHasKey( 'orgId', $decoded );
		$this->assertArrayNotHasKey( 'organizationId', $decoded );
		$this->assertArrayNotHasKey( 'apiKey', $decoded );
		$this->assertArrayNotHasKey( 'stripeCustomerId', $decoded );
	}

	public function test_create_campaign_validation_error_envelope(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 400 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'validation_error',
							'message' => 'name is required',
							'detail'  => 'name',
						),
					)
				),
				'headers'  => array(),
			)
		);

		$resp = $this->proxy->create_campaign( $this->request_with_json( 'POST', array() ) );

		$this->assertSame( 400, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'validation_error', $body['error']['code'] );
		$this->assertSame( 'name', $body['error']['detail'] );
		$this->assertFalse( $body['meta']['cached'] );
	}

	public function test_create_campaign_skips_cache_invalidation_on_error(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array() ),
				'headers'  => array(),
			)
		);

		$this->proxy->create_campaign( $this->request_with_json( 'POST', array( 'name' => 'X' ) ) );

		$this->assertSame( array(), $this->wpdb->like_patterns, 'Failed POST must NOT invalidate cache.' );
	}

	// -----------------------------------------------------------------------
	// update_campaign
	// -----------------------------------------------------------------------

	public function test_update_campaign_patches_backend_and_invalidates_single_and_list_cache(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_42',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_42', 'status' => 'PAUSED' ) ) ),
				'headers'  => array(),
			)
		);

		// Pre-cache the single-campaign entry so we can verify it gets dropped.
		$this->cache->set( 'af_campaigns_cmp_42', array( 'id' => 'cmp_42' ), 'campaigns' );

		$req  = $this->request_with_json( 'PATCH', array( 'status' => 'PAUSED' ), array( 'id' => 'cmp_42' ) );
		$resp = $this->proxy->update_campaign( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'PATCH', $this->last_request_args()['method'] );
		$this->assertStringContainsString( '/public/campaigns/cmp_42', $this->last_request_url() );

		$this->assertNull( $this->cache->get( 'af_campaigns_cmp_42' ) );
		$this->assertNotEmpty( $this->wpdb->like_patterns );
	}

	public function test_update_campaign_budget_safety_blocks_when_over_limit(): void {
		update_option( 'af_daily_budget_limit', 50 );

		$req  = $this->request_with_json( 'PATCH', array( 'budgetDaily' => 75 ), array( 'id' => 'cmp_42' ) );
		$resp = $this->proxy->update_campaign( $req );

		$this->assertSame( 422, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertFalse( $body['success'] );
		$this->assertSame( array(), af_test_get_http_requests(), 'Budget guard must short-circuit before hitting backend.' );
	}

	// -----------------------------------------------------------------------
	// Status mutation endpoints (launch/pause/resume/archive/duplicate)
	// -----------------------------------------------------------------------

	public function test_pause_campaign_calls_pause_endpoint_and_invalidates_cache(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/pause',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_1', 'status' => 'PAUSED' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->pause_campaign( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertStringContainsString( '/public/campaigns/cmp_1/pause', $this->last_request_url() );
		$this->assertNotEmpty( $this->wpdb->like_patterns );
	}

	public function test_resume_campaign_calls_resume_endpoint(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/resume',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_1', 'status' => 'ACTIVE' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->resume_campaign( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertStringContainsString( '/public/campaigns/cmp_1/resume', $this->last_request_url() );
	}

	public function test_archive_campaign_sends_delete_to_backend(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_1', 'status' => 'ARCHIVED' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'DELETE', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->archive_campaign( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'DELETE', $this->last_request_args()['method'] );
	}

	public function test_duplicate_campaign_invalidates_list_cache(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/duplicate',
			array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_2' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->duplicate_campaign( $req );

		$this->assertSame( 201, $resp->get_status() );
		$this->assertNotEmpty( $this->wpdb->like_patterns );
	}

	public function test_pause_campaign_surfaces_error_envelope(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/pause',
			array(
				'response' => array( 'code' => 403 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'permission_denied',
							'message' => 'No access',
							'detail'  => '',
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->pause_campaign( $req );

		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'permission_denied', $resp->get_data()['error']['code'] );
		$this->assertSame( array(), $this->wpdb->like_patterns, 'Error must not invalidate cache.' );
	}

	public function test_launch_campaign_backfills_platform_allocations_from_backend(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/launch',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_1', 'status' => 'ACTIVE' ) ) ),
				'headers'  => array(),
			)
		);
		// Backend GET so launch_campaign can backfill platform allocations
		// when the inbound body omits them.
		af_test_register_http_response(
			'/public/campaigns/cmp_1',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'campaignPlatforms' => array(
								array( 'platform' => 'meta', 'budgetAllocation' => 60 ),
								array( 'platform' => 'tiktok', 'budgetAllocation' => 40 ),
							),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->launch_campaign( $req );

		$this->assertSame( 200, $resp->get_status() );

		// Find the LAUNCH request (last one ending with /launch).
		$launch_args = null;
		foreach ( af_test_get_http_requests() as $rq ) {
			if ( str_ends_with( (string) $rq['url'], '/launch' ) ) {
				$launch_args = $rq['args'];
				break;
			}
		}
		$this->assertNotNull( $launch_args );
		$decoded = json_decode( (string) $launch_args['body'], true );
		$this->assertEquals( array( 'META' => 60, 'TIKTOK' => 40 ), $decoded['platformAllocations'] );
	}

	public function test_launch_campaign_rejects_allocation_below_platform_floor(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_floor/launch',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_floor' ) ) ),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/campaigns/cmp_floor',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'id'                => 'cmp_floor',
							'budgetDaily'       => 20,
							'campaignPlatforms' => array(
								array( 'platform' => 'meta', 'budgetAllocation' => 50 ),
								array( 'platform' => 'tiktok', 'budgetAllocation' => 50 ),
							),
						),
					)
				),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/config',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'platformMinimumDailyBudget' => array(
								'spotify'   => 10,
								'youtube'   => 10,
								'instagram' => 5,
								'google'    => 10,
								'facebook'  => 5,
								'tiktok'    => 50,
							),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_floor' ) );
		$resp = $this->proxy->launch_campaign( $req );

		// TikTok gets $10/day but the floor is $50/day.
		$this->assertSame( 422, $resp->get_status() );
		$this->assertStringContainsString( 'Tiktok', $resp->get_data()['error'] );

		foreach ( af_test_get_http_requests() as $rq ) {
			$this->assertStringNotContainsString(
				'/launch',
				(string) $rq['url'],
				'Launch must not reach the backend when a floor is violated.'
			);
		}
	}

	public function test_launch_campaign_passes_floor_check_when_budgets_clear(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_ok/launch',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_ok', 'status' => 'ACTIVE' ) ) ),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/campaigns/cmp_ok',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'id'                => 'cmp_ok',
							'budgetDaily'       => 200,
							'campaignPlatforms' => array(
								array( 'platform' => 'meta', 'budgetAllocation' => 50 ),
								array( 'platform' => 'tiktok', 'budgetAllocation' => 50 ),
							),
						),
					)
				),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/config',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'platformMinimumDailyBudget' => array(
								'spotify'   => 10,
								'youtube'   => 10,
								'instagram' => 5,
								'facebook'  => 5,
								'google'    => 10,
								'tiktok'    => 50,
							),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_ok' ) );
		$resp = $this->proxy->launch_campaign( $req );

		$this->assertSame( 200, $resp->get_status() );
	}

	public function test_launch_campaign_skips_floor_check_when_config_unavailable(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_nocfg/launch',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_nocfg', 'status' => 'ACTIVE' ) ) ),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/campaigns/cmp_nocfg',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'id'                => 'cmp_nocfg',
							'budgetDaily'       => 1,
							'campaignPlatforms' => array(
								array( 'platform' => 'tiktok', 'budgetAllocation' => 100 ),
							),
						),
					)
				),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/config',
			array(
				'response' => array( 'code' => 503 ),
				'body'     => wp_json_encode( array( 'error' => array( 'code' => 'down', 'message' => 'x' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_nocfg' ) );
		$resp = $this->proxy->launch_campaign( $req );

		// Config unavailable → backend stays authoritative; launch goes out.
		$this->assertSame( 200, $resp->get_status() );
	}

	// -----------------------------------------------------------------------
	// refresh_campaign_metrics
	// -----------------------------------------------------------------------

	public function test_refresh_campaign_metrics_posts_and_invalidates_cache(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/metrics/refresh',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'upsertedCount'      => 12,
							'platformsRefreshed' => 2,
							'platformsTotal'     => 2,
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array(), array( 'id' => 'cmp_1' ) );
		$resp = $this->proxy->refresh_campaign_metrics( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 12, $resp->get_data()['data']['upsertedCount'] );
		$this->assertSame( 'POST', $this->last_request_args()['method'] );
		$this->assertStringContainsString( '/public/campaigns/cmp_1/metrics/refresh', $this->last_request_url() );
	}

	// -----------------------------------------------------------------------
	// refund_payment
	// -----------------------------------------------------------------------

	public function test_refund_payment_posts_to_refund_endpoint(): void {
		af_test_register_http_response(
			'/public/payments/pay_1/refund',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'pay_1', 'status' => 'REFUNDED' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array( 'reason' => 'duplicate' ), array( 'id' => 'pay_1' ) );
		$resp = $this->proxy->refund_payment( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'POST', $this->last_request_args()['method'] );
		$this->assertStringContainsString( '/public/payments/pay_1/refund', $this->last_request_url() );
		$decoded = json_decode( (string) $this->last_request_args()['body'], true );
		$this->assertSame( 'duplicate', $decoded['reason'] );
	}

	// -----------------------------------------------------------------------
	// create_audience / update_audience / estimate_audience
	// -----------------------------------------------------------------------

	public function test_create_audience_invalidates_audience_cache_on_success(): void {
		af_test_register_http_response(
			'/public/audiences',
			array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'aud_1' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array( 'name' => 'My Audience' ) );
		$resp = $this->proxy->create_audience( $req );

		$this->assertSame( 201, $resp->get_status() );
		$this->assertNotEmpty( $this->wpdb->like_patterns );
		$this->assertStringContainsString( 'audiences', $this->wpdb->like_patterns[0] );
	}

	public function test_update_audience_returns_405_without_backend_call(): void {
		$req  = $this->request_with_json( 'PATCH', array( 'name' => 'Renamed' ), array( 'id' => 'aud_1' ) );
		$resp = $this->proxy->update_audience( $req );

		$this->assertSame( 405, $resp->get_status() );
		$this->assertFalse( $resp->get_data()['success'] );
		$this->assertSame( array(), af_test_get_http_requests(), 'The backend has no audience-update route.' );
	}

	public function test_delete_audience_forwards_delete_and_invalidates_cache(): void {
		set_transient( 'af_audiences_list', array( 'stale' => true ), 3600 );
		af_test_register_http_response(
			'/public/audiences/aud_1',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'message' => 'deleted' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'DELETE', array(), array( 'id' => 'aud_1' ) );
		$resp = $this->proxy->delete_audience( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'DELETE', $this->last_request_args()['method'] );
		$this->assertStringContainsString( '/public/audiences/aud_1', $this->last_request_url() );
	}

	public function test_estimate_audience_translates_form_to_reach_estimation_contract(): void {
		// Credential resolution: one active own connection + one system
		// credential for a platform the org hasn't connected.
		af_test_register_http_response(
			'/public/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'credentials' => array(
							array(
								'id'        => 'cred_meta',
								'platform'  => 'META',
								'status'    => 'active',
								'source'    => 'user',
								'createdAt' => '2026-04-01T00:00:00Z',
								'updatedAt' => '2026-04-01T00:00:00Z',
							),
						),
					)
				),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/auth/credentials/TIKTOK',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'credentials'       => array(),
						'systemCredentials' => array(
							array(
								'id'       => 'sys_tiktok',
								'platform' => 'TIKTOK',
								'status'   => 'active',
							),
						),
					)
				),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/auth/credentials/',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'credentials' => array() ) ),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/reach-estimation/all',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							array(
								'platform'            => 'META',
								'estimatedReachLower' => 10000,
								'estimatedReachUpper' => 20000,
								'estimateReady'       => true,
							),
							array(
								'platform'            => 'GOOGLE',
								'estimatedReachLower' => 4000,
								'estimatedReachUpper' => 6000,
								'estimateReady'       => true,
							),
							array(
								'platform'      => 'TIKTOK',
								'estimateReady' => false,
							),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json(
			'POST',
			array(
				'countries' => 'us, gb',
				'age_min'   => '18',
				'age_max'   => '34',
			)
		);
		$resp = $this->proxy->estimate_audience( $req );

		$this->assertSame( 200, $resp->get_status() );

		// META midpoint 15000 + GOOGLE midpoint 5000 (folded to youtube);
		// TIKTOK not ready → excluded.
		$data = $resp->get_data()['data'];
		$this->assertSame( 20000, $data['total'] );
		$this->assertSame( 15000, $data['by_platform']['meta'] );
		$this->assertSame( 5000, $data['by_platform']['youtube'] );
		$this->assertArrayNotHasKey( 'tiktok', $data['by_platform'] );

		// The outgoing estimation body must match the strict backend schema.
		$estimation_calls = array_values(
			array_filter(
				af_test_get_http_requests(),
				static fn ( array $call ): bool => str_contains( $call['url'], '/reach-estimation/all' )
			)
		);
		$this->assertCount( 1, $estimation_calls );
		$body = json_decode( (string) $estimation_calls[0]['args']['body'], true );
		$this->assertSame( array( 'US', 'GB' ), $body['targeting']['countries'] );
		// Estimation adapters consume the canonical snake_case targeting keys;
		// camelCase variants pass the loose contract but are silently ignored.
		$this->assertSame( 18, $body['targeting']['age_min'] );
		$this->assertSame( 34, $body['targeting']['age_max'] );
		$this->assertArrayNotHasKey( 'ageMin', $body['targeting'] );
		$this->assertArrayNotHasKey( 'interests', $body['targeting'] );
		$this->assertContains(
			array( 'platform' => 'META', 'credentialId' => 'cred_meta' ),
			$body['credentials']
		);
		$this->assertContains(
			array( 'platform' => 'TIKTOK' ),
			$body['credentials']
		);
	}

	public function test_estimate_audience_returns_zeros_when_no_credentials(): void {
		af_test_register_http_response(
			'/public/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'credentials' => array() ) ),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/auth/credentials/',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'credentials' => array() ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array( 'countries' => 'US' ) );
		$resp = $this->proxy->estimate_audience( $req );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertFalse( $resp->get_data()['success'] );
		$this->assertStringContainsString( 'No advertising credential', $resp->get_data()['error'] );

		$estimation_calls = array_filter(
			af_test_get_http_requests(),
			static fn ( array $call ): bool => str_contains( $call['url'], '/reach-estimation' )
		);
		$this->assertSame( array(), $estimation_calls, 'No estimation call without credentials.' );
	}

	public function test_estimate_audience_does_not_invalidate_cache(): void {
		af_test_register_http_response(
			'/public/reach-estimation/all',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$this->proxy->estimate_audience( $this->request_with_json( 'POST', array() ) );

		$this->assertSame( array(), $this->wpdb->like_patterns, 'Estimate is a read-shaped action and must not bust caches.' );
	}

	// -----------------------------------------------------------------------
	// create_checkout
	// -----------------------------------------------------------------------

	public function test_create_checkout_injects_campaign_id_into_body(): void {
		af_test_register_http_response(
			'/public/payments/checkout',
			array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'checkoutUrl' => 'https://stripe.test/sess' ) ) ),
				'headers'  => array(),
			)
		);

		$req  = $this->request_with_json( 'POST', array( 'successUrl' => 'https://x' ), array( 'id' => 'cmp_99' ) );
		$resp = $this->proxy->create_checkout( $req );

		$this->assertSame( 201, $resp->get_status() );
		$decoded = json_decode( (string) $this->last_request_args()['body'], true );
		$this->assertSame( 'cmp_99', $decoded['campaignId'] );
		$this->assertSame( 'https://x', $decoded['successUrl'] );
	}
}
