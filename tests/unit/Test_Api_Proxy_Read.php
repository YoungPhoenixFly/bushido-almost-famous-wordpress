<?php
/**
 * Tests Api_Proxy READ endpoints — route registration, backend URL targeting,
 * cache hit / miss behavior, error envelope, and check_read_permission gating.
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

if ( ! class_exists( 'WP_REST_Server' ) ) {
	// Minimal stand-in matching the constants Api_Proxy reads at registration time.
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const EDITABLE  = 'PATCH';
		public const DELETABLE = 'DELETE';
	}
}

class Test_Api_Proxy_Read extends TestCase {

	private Api_Proxy $proxy;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test_proxy_read' ) );

		$this->proxy = new Api_Proxy(
			new Api_Client( $auth ),
			new Api_Cache()
		);
	}

	private function request( string $method = 'GET' ): WP_REST_Request {
		return new WP_REST_Request( $method, '/almost-famous/v1/' );
	}

	private function last_request_url(): string {
		$reqs = af_test_get_http_requests();
		return (string) ( $reqs[ array_key_last( $reqs ) ]['url'] ?? '' );
	}

	// -----------------------------------------------------------------------
	// Route registration
	// -----------------------------------------------------------------------

	public function test_register_routes_creates_expected_endpoints(): void {
		$this->proxy->register_routes();

		$routes = af_test_get_rest_routes();

		$expected = array(
			'almost-famous/v1/campaigns',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/checkout',
			'almost-famous/v1/payments',
			'almost-famous/v1/payments/(?P<id>[\\w-]+)',
			'almost-famous/v1/payments/(?P<id>[\\w-]+)/refund',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/launch',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/pause',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/resume',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/archive',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/duplicate',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/platforms',
			'almost-famous/v1/campaigns/(?P<id>[\\w-]+)/analytics',
			'almost-famous/v1/platforms',
			'almost-famous/v1/platforms/status',
			'almost-famous/v1/audiences',
			'almost-famous/v1/creatives',
			'almost-famous/v1/creatives/(?P<id>[\\w-]+)',
			'almost-famous/v1/creatives/(?P<id>[\\w-]+)/approval',
			'almost-famous/v1/creatives/(?P<id>[\\w-]+)/regenerate',
			'almost-famous/v1/audiences/(?P<id>[\\w-]+)',
			'almost-famous/v1/audiences/estimate',
		);

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Missing REST route: {$route}" );
		}
	}

	// -----------------------------------------------------------------------
	// check_read_permission gating
	// -----------------------------------------------------------------------

	public function test_check_read_permission_true_with_view_campaigns_cap(): void {
		af_test_set_caps( array( 'af_view_campaigns' => true ) );
		$this->assertTrue( $this->proxy->check_read_permission() );
	}

	public function test_check_read_permission_true_with_manage_options_cap(): void {
		af_test_set_caps( array( 'manage_options' => true ) );
		$this->assertTrue( $this->proxy->check_read_permission() );
	}

	public function test_check_read_permission_false_without_any_cap(): void {
		af_test_set_caps( array() );
		$this->assertFalse( $this->proxy->check_read_permission() );
	}

	// -----------------------------------------------------------------------
	// get_campaigns — caching & URL
	// -----------------------------------------------------------------------

	public function test_get_campaigns_builds_correct_backend_url_and_caches(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( array( 'id' => 'cmp_1' ) ) ) ),
				'headers'  => array( 'etag' => '"v1"' ),
			)
		);

		$req = $this->request( 'GET' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );

		$first  = $this->proxy->get_campaigns( $req );
		$second = $this->proxy->get_campaigns( $req );

		$this->assertSame( 200, $first->get_status() );
		$this->assertStringContainsString( '/public/campaigns', $this->last_request_url() );

		$first_body  = $first->get_data();
		$second_body = $second->get_data();

		$this->assertSame( array( array( 'id' => 'cmp_1' ) ), $first_body['data'] );
		$this->assertSame( array( array( 'id' => 'cmp_1' ) ), $second_body['data'] );

		$this->assertFalse( $first_body['meta']['cached'], 'First call must be a cache miss.' );
		$this->assertTrue( $second_body['meta']['cached'], 'Second call must hit the cache.' );

		// Only one HTTP request despite two proxy calls.
		$this->assertCount( 1, af_test_get_http_requests() );
	}

	public function test_get_campaigns_surfaces_api_error_in_envelope(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 502 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'platform_degraded',
							'message' => 'Backend offline',
							'detail'  => 'meta-api',
						),
					)
				),
				'headers'  => array(),
			)
		);

		$resp = $this->proxy->get_campaigns( $this->request() );

		$this->assertSame( 502, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'platform_degraded', $body['error']['code'] );
		$this->assertSame( 'Backend offline', $body['error']['message'] );
		$this->assertFalse( $body['meta']['cached'] );
	}

	// -----------------------------------------------------------------------
	// get_campaign / get_campaign_platforms
	// -----------------------------------------------------------------------

	public function test_get_campaign_hits_id_scoped_url_and_caches(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_42',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_42' ) ) ),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'id', 'cmp_42' );

		$first  = $this->proxy->get_campaign( $req );
		$second = $this->proxy->get_campaign( $req );

		$this->assertSame( 200, $first->get_status() );
		$this->assertStringContainsString( '/public/campaigns/cmp_42', $this->last_request_url() );

		$this->assertFalse( $first->get_data()['meta']['cached'] );
		$this->assertTrue( $second->get_data()['meta']['cached'] );
		$this->assertCount( 1, af_test_get_http_requests() );
	}

	public function test_get_campaign_platforms_hits_correct_url(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_42/platforms',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( array( 'platform' => 'META' ) ) ) ),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'id', 'cmp_42' );

		$resp = $this->proxy->get_campaign_platforms( $req );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertStringContainsString( '/public/campaigns/cmp_42/platforms', $this->last_request_url() );
		$this->assertSame( array( array( 'platform' => 'META' ) ), $resp->get_data()['data'] );
	}

	public function test_get_campaign_platforms_surfaces_api_error(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_42/platforms',
			array(
				'response' => array( 'code' => 503 ),
				'body'     => wp_json_encode( array() ),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'id', 'cmp_42' );

		$resp = $this->proxy->get_campaign_platforms( $req );

		$this->assertSame( 503, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'api_unreachable', $body['error']['code'] );
	}

	// -----------------------------------------------------------------------
	// get_payments / get_payment
	// -----------------------------------------------------------------------

	public function test_get_payments_passes_through_campaign_filter(): void {
		af_test_register_http_response(
			'/public/payments',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'campaignId', 'cmp_42' );

		$resp = $this->proxy->get_payments( $req );

		$this->assertSame( 200, $resp->get_status() );
		$url = $this->last_request_url();
		$this->assertStringContainsString( '/public/payments', $url );
		$this->assertStringContainsString( 'campaignId=cmp_42', $url );
	}

	public function test_get_payment_targets_id_url_and_propagates_error(): void {
		af_test_register_http_response(
			'/public/payments/pay_404',
			array(
				'response' => array( 'code' => 404 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'not_found',
							'message' => 'Payment missing',
							'detail'  => '',
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'id', 'pay_404' );

		$resp = $this->proxy->get_payment( $req );

		$this->assertSame( 404, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'not_found', $body['error']['code'] );
	}

	// -----------------------------------------------------------------------
	// get_audiences — caching scope
	// -----------------------------------------------------------------------

	public function test_get_audiences_caches_and_returns_cached_payload(): void {
		af_test_register_http_response(
			'/public/audiences',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( array( 'id' => 'aud_1' ) ) ) ),
				'headers'  => array(),
			)
		);

		$first  = $this->proxy->get_audiences( $this->request() );
		$second = $this->proxy->get_audiences( $this->request() );

		$this->assertSame( 200, $first->get_status() );
		$this->assertFalse( $first->get_data()['meta']['cached'] );
		$this->assertTrue( $second->get_data()['meta']['cached'] );
		$this->assertCount( 1, af_test_get_http_requests() );
	}

	// -----------------------------------------------------------------------
	// get_platforms / get_platform_status — non-cached read paths
	// -----------------------------------------------------------------------

	public function test_get_platforms_returns_static_catalog_when_backend_healthy(): void {
		af_test_register_http_response(
			'/public/platforms/insights/summary',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'aggregate' => array() ) ) ),
				'headers'  => array(),
			)
		);

		$resp = $this->proxy->get_platforms( $this->request() );

		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data()['data'];
		$this->assertIsArray( $data );
		$ids = array_column( $data, 'id' );
		$this->assertContains( 'meta', $ids );
		$this->assertContains( 'google', $ids );
		$this->assertContains( 'spotify', $ids );
		$this->assertContains( 'tiktok', $ids );
	}

	public function test_get_platform_status_passes_through_backend_payload(): void {
		af_test_register_http_response(
			'/public/platforms/insights/summary',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'aggregate' => array( 'totalSpend' => 1 ) ) ) ),
				'headers'  => array(),
			)
		);

		$resp = $this->proxy->get_platform_status( $this->request() );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( 'aggregate' => array( 'totalSpend' => 1 ) ), $resp->get_data()['data'] );
	}

	// -----------------------------------------------------------------------
	// get_campaign_analytics — composes metrics + comparison
	// -----------------------------------------------------------------------

	public function test_get_campaign_analytics_summarizes_metrics_and_normalizes_platforms(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/metrics/comparison',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'META' => array( 'totals' => array( 'impressions' => 1000, 'clicks' => 50 ) ),
						),
					)
				),
				'headers'  => array(),
			)
		);
		af_test_register_http_response(
			'/public/campaigns/cmp_1/metrics',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							array(
								'date'        => '2026-05-01',
								'impressions' => 100,
								'clicks'      => 5,
								'spend'       => 10.0,
								'conversions' => 1,
								'reach'       => 80,
								'videoViews'  => 30,
							),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'id', 'cmp_1' );

		$resp = $this->proxy->get_campaign_analytics( $req );

		$this->assertSame( 200, $resp->get_status() );
		$payload = $resp->get_data()['data'];

		$this->assertSame( 100, $payload['summary']['impressions'] );
		$this->assertSame( 5, $payload['summary']['clicks'] );
		$this->assertCount( 1, $payload['dataPoints'] );
		$this->assertSame( 'META', $payload['platforms'][0]['platform'] );
	}

	public function test_get_campaign_analytics_surfaces_metrics_error(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1/metrics',
			array(
				'response' => array( 'code' => 504 ),
				'body'     => wp_json_encode( array() ),
				'headers'  => array(),
			)
		);

		$req = $this->request();
		$req->set_param( 'id', 'cmp_1' );

		$resp = $this->proxy->get_campaign_analytics( $req );

		$this->assertSame( 504, $resp->get_status() );
		$this->assertSame( 'api_timeout', $resp->get_data()['error']['code'] );
	}
}
