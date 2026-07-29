<?php
/**
 * End-to-end tests for the Api_Client HTTP wrapper:
 * URL construction, method/headers, idempotency, ETag round-trip, error
 * handling, demo mode short-circuit, and the top-level (non-/public/) helpers.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Api_Error;
use PHPUnit\Framework\TestCase;

class Test_Api_Client extends TestCase {

	private const TEST_KEY = 'bsh_test_key_client_suite';

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		// Make sure no demo flag is set between tests.
		unset( $_SERVER['HTTP_X_AF_DEMO_MODE'] );

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( self::TEST_KEY ) );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AF_DEMO_MODE'] );
		parent::tearDown();
	}

	private function client(): Api_Client {
		return new Api_Client( new Api_Auth() );
	}

	private function last_request(): array {
		$requests = af_test_get_http_requests();
		return $requests[ array_key_last( $requests ) ] ?? array();
	}

	// -----------------------------------------------------------------------
	// Happy paths: GET / POST / PATCH / DELETE
	// -----------------------------------------------------------------------

	public function test_get_builds_public_url_and_sends_required_headers(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'ok' => true ) ) ),
				'headers'  => array(),
			)
		);

		$result = $this->client()->get( '/campaigns' );

		$req  = $this->last_request();
		$args = $req['args'] ?? array();

		$this->assertSame( 'GET', $args['method'] );
		$this->assertStringContainsString( '/api/v1/public/campaigns', (string) $req['url'] );
		$this->assertSame( 15, $args['timeout'] );
		$this->assertTrue( $args['sslverify'] );

		$headers = $args['headers'];
		$this->assertSame( self::TEST_KEY, $headers['X-API-Key'] );
		$this->assertSame( ALMOST_FAMOUS_VERSION, $headers['X-Bushido-Plugin-Version'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertArrayNotHasKey( 'X-Idempotency-Key', $headers, 'GET requests must not carry an idempotency key.' );
		$this->assertArrayNotHasKey( 'body', $args );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'ok' => true ), $result['data'] );
	}

	public function test_get_appends_query_parameters(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$this->client()->get( '/campaigns', array( 'page' => 2, 'limit' => 5 ) );

		$url = (string) $this->last_request()['url'];
		$this->assertStringContainsString( 'page=2', $url );
		$this->assertStringContainsString( 'limit=5', $url );
	}

	public function test_post_sends_json_body_and_idempotency_key(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_1' ) ) ),
				'headers'  => array(),
			)
		);

		$result = $this->client()->post( '/campaigns', array( 'name' => 'Launch' ) );

		$args = $this->last_request()['args'];
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( wp_json_encode( array( 'name' => 'Launch' ) ), $args['body'] );
		$this->assertArrayHasKey( 'X-Idempotency-Key', $args['headers'] );
		$this->assertNotEmpty( $args['headers']['X-Idempotency-Key'] );

		$this->assertSame( 201, $result['status'] );
		$this->assertSame( array( 'id' => 'cmp_1' ), $result['data'] );
	}

	public function test_patch_includes_idempotency_key_and_json_body(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'cmp_1' ) ) ),
				'headers'  => array(),
			)
		);

		$this->client()->patch( '/campaigns/cmp_1', array( 'status' => 'ACTIVE' ) );

		$args = $this->last_request()['args'];
		$this->assertSame( 'PATCH', $args['method'] );
		$this->assertSame( wp_json_encode( array( 'status' => 'ACTIVE' ) ), $args['body'] );
		$this->assertNotEmpty( $args['headers']['X-Idempotency-Key'] );
	}

	public function test_delete_includes_idempotency_key_and_no_body(): void {
		af_test_register_http_response(
			'/public/campaigns/cmp_1',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'archived' => true ) ) ),
				'headers'  => array(),
			)
		);

		$this->client()->delete( '/campaigns/cmp_1' );

		$args = $this->last_request()['args'];
		$this->assertSame( 'DELETE', $args['method'] );
		$this->assertArrayNotHasKey( 'body', $args );
		$this->assertNotEmpty( $args['headers']['X-Idempotency-Key'] );
	}

	public function test_idempotency_key_is_unique_per_request(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$client = $this->client();
		$client->post( '/campaigns', array( 'n' => 1 ) );
		$client->post( '/campaigns', array( 'n' => 2 ) );

		$requests = af_test_get_http_requests();
		$first    = $requests[0]['args']['headers']['X-Idempotency-Key'];
		$second   = $requests[1]['args']['headers']['X-Idempotency-Key'];

		$this->assertNotSame( $first, $second );
	}

	public function test_post_preserves_caller_supplied_idempotency_key(): void {
		af_test_register_http_response(
			'/public/pixels/px_1/events',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$this->client()->post(
			'/pixels/px_1/events',
			array( 'eventName' => 'Purchase' ),
			'stable-event-id'
		);

		$headers = $this->last_request()['args']['headers'];
		$this->assertSame( 'stable-event-id', $headers['X-Idempotency-Key'] );
	}

	// -----------------------------------------------------------------------
	// ETag conditional round-trip
	// -----------------------------------------------------------------------

	public function test_get_with_etag_passes_if_none_match_header(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array( 'etag' => '"abc"' ),
			)
		);

		$this->client()->get( '/campaigns', array(), '"abc"' );

		$headers = $this->last_request()['args']['headers'];
		$this->assertSame( '"abc"', $headers['If-None-Match'] );
	}

	public function test_304_response_returns_not_modified_envelope(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 304 ),
				'body'     => '',
				'headers'  => array( 'etag' => '"abc"' ),
			)
		);

		$result = $this->client()->get( '/campaigns', array(), '"abc"' );

		$this->assertSame( 304, $result['status'] );
		$this->assertTrue( $result['not_modified'] );
		$this->assertNull( $result['data'] );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	// -----------------------------------------------------------------------
	// Error handling
	// -----------------------------------------------------------------------

	public function test_4xx_backend_body_wraps_into_api_error(): void {
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 422 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'validation_error',
							'message' => 'Daily budget too low',
							'detail'  => 'budgetDaily must be >= 5',
						),
					)
				),
				'headers'  => array(),
			)
		);

		$result = $this->client()->post( '/campaigns', array( 'budgetDaily' => 1 ) );

		$this->assertSame( 422, $result['status'] );
		$this->assertInstanceOf( Api_Error::class, $result['error'] );
		$this->assertSame( 'validation_error', $result['error']->code );
		$this->assertSame( 'Daily budget too low', $result['error']->message );
		$this->assertSame( 'budgetDaily must be >= 5', $result['error']->detail );
		$this->assertSame( 422, $result['error']->http_status );
	}

	public function test_426_status_persists_upgrade_required_transient(): void {
		$body = array(
			'error' => array(
				'code'    => 'upgrade_required',
				'message' => 'Plugin too old',
			),
		);
		af_test_register_http_response(
			'/public/campaigns',
			array(
				'response' => array( 'code' => 426 ),
				'body'     => wp_json_encode( $body ),
				'headers'  => array(),
			)
		);

		$result = $this->client()->get( '/campaigns' );

		$this->assertSame( 426, $result['status'] );
		$this->assertInstanceOf( Api_Error::class, $result['error'] );

		$stored = get_transient( 'af_upgrade_required' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'upgrade_required', $stored['error']['code'] );
	}

	public function test_wp_error_wraps_into_api_unreachable(): void {
		af_test_register_http_response(
			'/public/campaigns',
			new WP_Error( 'http_request_failed', 'Connection timed out', null )
		);

		$result = $this->client()->get( '/campaigns' );

		$this->assertSame( 503, $result['status'] );
		$this->assertInstanceOf( Api_Error::class, $result['error'] );
		$this->assertSame( 'api_unreachable', $result['error']->code );
		$this->assertSame( 'Connection timed out', $result['error']->detail );
	}

	public function test_missing_api_key_returns_403_api_error_without_calling_backend(): void {
		// Wipe the configured key so decrypt_api_key returns ''.
		delete_option( 'af_api_key' );

		$result = $this->client()->get( '/campaigns' );

		$this->assertSame( 403, $result['status'] );
		$this->assertInstanceOf( Api_Error::class, $result['error'] );
		$this->assertSame( 'api_unreachable', $result['error']->code );
		$this->assertSame( array(), af_test_get_http_requests(), 'Must short-circuit before sending the request.' );
	}

	// -----------------------------------------------------------------------
	// Demo mode short-circuit
	// -----------------------------------------------------------------------

	public function test_demo_mode_via_header_short_circuits_for_admins(): void {
		// Default test caps include manage_options.
		$_SERVER['HTTP_X_AF_DEMO_MODE'] = '1';

		$result = $this->client()->get( '/campaigns' );

		$this->assertSame( 200, $result['status'] );
		$this->assertIsArray( $result['data'] );
		$this->assertArrayHasKey( 'data', $result['data'] );
		$this->assertNotEmpty( $result['data']['data'] );
		$this->assertSame( 'cmp_demo_001', $result['data']['data'][0]['id'] );
		$this->assertSame(
			array(),
			af_test_get_http_requests(),
			'Demo mode must not hit the real backend.'
		);
	}

	public function test_demo_mode_header_is_ignored_for_non_admins(): void {
		// Anonymous visitor: no manage_options. The client-supplied header
		// must not be honored — otherwise anyone could poison the shared
		// cache with fabricated fixtures.
		af_test_set_caps( array() );
		$_SERVER['HTTP_X_AF_DEMO_MODE'] = '1';
		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$result = $this->client()->get( '/campaigns' );

		$this->assertSame( 200, $result['status'] );
		// A real backend request fired — demo fixtures were NOT used.
		$this->assertNotSame( array(), af_test_get_http_requests() );
	}

	public function test_demo_mode_unimplemented_endpoint_returns_error_not_fatal(): void {
		// Regression: the demo-response fallback referenced an undefined
		// Error_Code constant (UNKNOWN_ERROR) and fatally errored for any
		// endpoint the demo generator did not implement, e.g. /assets — which
		// took down the Creatives admin page whenever demo mode was on.
		$_SERVER['HTTP_X_AF_DEMO_MODE'] = '1';

		$result = $this->client()->get( '/assets' );

		$this->assertSame( 404, $result['status'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertInstanceOf( \AlmostFamous\Api\Api_Error::class, $result['error'] );
		$this->assertSame( 'unknown_error', $result['error']->code );
		$this->assertSame(
			array(),
			af_test_get_http_requests(),
			'Demo mode must not hit the real backend.'
		);
	}

	public function test_demo_mode_via_filter_short_circuits(): void {
		add_filter(
			'almost_famous/api/mock_response',
			static function ( mixed $previous, string $method, string $url ): array {
				return array(
					'data'   => array( 'mocked' => $method . ' ' . $url ),
					'status' => 200,
				);
			},
			10,
			3
		);

		$result = $this->client()->get( '/campaigns' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'GET', explode( ' ', $result['data']['mocked'] )[0] );
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	// -----------------------------------------------------------------------
	// Top-level (no /public/ prefix) helpers
	// -----------------------------------------------------------------------

	public function test_get_top_targets_top_level_path_with_no_public_segment(): void {
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'valid' => true ) ),
				'headers'  => array(),
			)
		);

		$this->client()->get_top( '/auth/validate' );

		$url = (string) $this->last_request()['url'];
		$this->assertStringNotContainsString( '/public/', $url );
		$this->assertStringEndsWith( '/api/v1/auth/validate', $url );
	}

	public function test_get_top_appends_query_parameters_without_public_segment(): void {
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'valid' => true ) ),
				'headers'  => array(),
			)
		);

		$this->client()->get_top( '/auth/validate', array( 'scope' => 'org' ) );

		$url = (string) $this->last_request()['url'];
		$this->assertStringNotContainsString( '/public/', $url );
		$this->assertStringContainsString( 'scope=org', $url );
	}

}
