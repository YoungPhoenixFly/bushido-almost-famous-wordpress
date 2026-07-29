<?php
/**
 * Tests for the backend-bounce OAuth Connect/Callback/Disconnect controller.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Oauth_Controller;
use PHPUnit\Framework\TestCase;

/**
 * Covers the three REST endpoints exposed by Oauth_Controller plus
 * the failure modes (bad nonce, missing state, backend error).
 */
class Test_Oauth_Controller extends TestCase {

	private Oauth_Controller $oauth;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test_key_unit' ) );
		$this->oauth = new Oauth_Controller( new Api_Client( $auth ) );
	}

	public function test_start_returns_authorization_url_and_persists_state(): void {
		af_test_register_http_response(
			'/auth/connect/META',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'authorizationUrl' => 'https://facebook.com/dialog/oauth?x=1' ) ),
				'headers'  => array(),
			)
		);

		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/start' );
		$req->set_param( 'platform', 'meta' );

		$resp = $this->oauth->start( $req );

		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertStringStartsWith( 'https://facebook.com/dialog/oauth', $data['authorizationUrl'] );
		$this->assertStringContainsString( 'client_state=', $data['authorizationUrl'] );

		$stored = get_transient( 'af_oauth_state_1' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'meta', $stored['platform'] );
		$this->assertNotEmpty( $stored['state'] );
	}

	public function test_start_rejects_unknown_platform(): void {
		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/start' );
		$req->set_param( 'platform', 'myspace' );

		$resp = $this->oauth->start( $req );

		$this->assertSame( 400, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'invalid_platform', $body['error']['code'] );
	}

	public function test_start_surfaces_backend_error(): void {
		af_test_register_http_response(
			'/auth/connect/META',
			array(
				'response' => array( 'code' => 502 ),
				'body'     => wp_json_encode( array( 'code' => 'platform_degraded', 'message' => 'platform offline' ) ),
				'headers'  => array(),
			)
		);

		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/start' );
		$req->set_param( 'platform', 'meta' );

		$resp = $this->oauth->start( $req );

		$this->assertGreaterThanOrEqual( 400, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertNotEmpty( $body['error']['code'] );
	}

	public function test_start_surfaces_missing_authorization_url(): void {
		af_test_register_http_response(
			'/auth/connect/GOOGLE',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
				'headers'  => array(),
			)
		);

		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/start' );
		$req->set_param( 'platform', 'google' );

		$resp = $this->oauth->start( $req );
		$this->assertSame( 502, $resp->get_status() );
		$this->assertSame( 'no_auth_url', $resp->get_data()['error']['code'] );
	}

	public function test_callback_persists_credential_on_valid_state(): void {
		set_transient( 'af_oauth_state_1', array( 'state' => 'nonce-abc', 'platform' => 'meta' ), 600 );
		// The callback resolves the credential from the connections LIST —
		// there is no per-id route on the backend.
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'credentials' => array(
						array(
							'id'          => 'cred-789',
							'platform'    => 'META',
							'accountId'   => 'act_42',
							'accountName' => 'My Page',
							'status'      => 'active',
							'source'      => 'user',
							'createdAt'   => '2026-04-01T00:00:00Z',
							'updatedAt'   => '2026-04-01T00:00:00Z',
						),
					),
				) ),
				'headers'  => array(),
			)
		);

		$req = new WP_REST_Request( 'GET', '/almost-famous/v1/oauth/callback' );
		$req->set_param( 'status', 'success' );
		$req->set_param( 'platform', 'meta' );
		$req->set_param( 'credentialId', 'cred-789' );
		$req->set_param( 'client_state', 'nonce-abc' );

		$resp = $this->oauth->callback( $req );

		$this->assertSame( 302, $resp->get_status() );
		$location = $resp->get_headers()['Location'] ?? '';
		$this->assertStringContainsString( 'af_connected=meta', $location );

		$accounts = get_option( 'af_accounts', array() );
		$this->assertArrayHasKey( 'meta', $accounts );
		$this->assertSame( 'cred-789', $accounts['meta']['credentialId'] );
		$this->assertSame( 'act_42', $accounts['meta']['accountId'] );

		$this->assertFalse(
			get_transient( 'af_oauth_state_1' ),
			'State must be consumed on use to prevent replay.'
		);
	}

	public function test_callback_rejects_invalid_state(): void {
		set_transient( 'af_oauth_state_1', array( 'state' => 'real', 'platform' => 'meta' ), 600 );

		$req = new WP_REST_Request( 'GET', '/almost-famous/v1/oauth/callback' );
		$req->set_param( 'status', 'success' );
		$req->set_param( 'credentialId', 'cred-789' );
		$req->set_param( 'client_state', 'forged' );

		$resp = $this->oauth->callback( $req );

		$this->assertSame( 302, $resp->get_status() );
		$this->assertStringContainsString( 'af_connect_error=invalid_state', $resp->get_headers()['Location'] );
		$this->assertSame( array(), get_option( 'af_accounts', array() ) );
	}

	public function test_callback_rejects_missing_state(): void {
		$req = new WP_REST_Request( 'GET', '/almost-famous/v1/oauth/callback' );
		$req->set_param( 'status', 'success' );
		$req->set_param( 'client_state', 'anything' );

		$resp = $this->oauth->callback( $req );

		$this->assertSame( 302, $resp->get_status() );
		$this->assertStringContainsString( 'af_connect_error=missing_state', $resp->get_headers()['Location'] );
	}

	public function test_callback_redirects_with_error_on_status_error(): void {
		set_transient( 'af_oauth_state_1', array( 'state' => 'n', 'platform' => 'meta' ), 600 );

		$req = new WP_REST_Request( 'GET', '/almost-famous/v1/oauth/callback' );
		$req->set_param( 'status', 'error' );
		$req->set_param( 'client_state', 'n' );
		$req->set_param( 'error', 'user_denied' );

		$resp = $this->oauth->callback( $req );

		$this->assertSame( 302, $resp->get_status() );
		$this->assertStringContainsString( 'af_connect_error=user_denied', $resp->get_headers()['Location'] );
	}

	public function test_disconnect_calls_backend_and_removes_local_record(): void {
		update_option(
			'af_accounts',
			array(
				'meta' => array( 'credentialId' => 'cred-789', 'accountId' => 'act_42' ),
			)
		);
		af_test_register_http_response(
			'/auth/connections/cred-789',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'message' => 'disconnected' ) ),
				'headers'  => array(),
			)
		);

		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/disconnect' );
		$req->set_param( 'platform', 'meta' );

		$resp = $this->oauth->disconnect( $req );

		$this->assertSame( 200, $resp->get_status() );
		$accounts = get_option( 'af_accounts', null );
		$this->assertNotNull( $accounts );
		$this->assertArrayNotHasKey( 'meta', $accounts );

		$requests = af_test_get_http_requests();
		$last     = end( $requests );
		$this->assertSame( 'DELETE', $last['args']['method'] );
	}

	public function test_disconnect_surfaces_backend_error_without_fatal(): void {
		update_option(
			'af_accounts',
			array( 'meta' => array( 'credentialId' => 'cred-789', 'accountId' => 'act_42' ) )
		);
		af_test_register_http_response(
			'/auth/connections/cred-789',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'error' => array( 'code' => 'backend_boom', 'message' => 'downstream failed' ) ) ),
				'headers'  => array(),
			)
		);

		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/disconnect' );
		$req->set_param( 'platform', 'meta' );

		$resp = $this->oauth->disconnect( $req );

		// Must return a structured error (not fatal on Api_Error's props).
		$this->assertSame( 500, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertSame( 'backend_boom', $data['error']['code'] );
		$this->assertSame( 'downstream failed', $data['error']['message'] );

		// The local record is preserved when the backend delete fails.
		$accounts = get_option( 'af_accounts', array() );
		$this->assertArrayHasKey( 'meta', $accounts );
	}

	public function test_disconnect_is_idempotent_when_platform_unknown(): void {
		update_option( 'af_accounts', array() );

		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/oauth/disconnect' );
		$req->set_param( 'platform', 'tiktok' );

		$resp = $this->oauth->disconnect( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( 'ok' => true ), $resp->get_data() );
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	public function test_register_routes_creates_three_endpoints(): void {
		$this->oauth->register_routes();

		$routes = af_test_get_rest_routes();
		$this->assertArrayHasKey( 'almost-famous/v1/oauth/start', $routes );
		$this->assertArrayHasKey( 'almost-famous/v1/oauth/callback', $routes );
		$this->assertArrayHasKey( 'almost-famous/v1/oauth/disconnect', $routes );

		$this->assertSame( 'POST', $routes['almost-famous/v1/oauth/start']['methods'] );
		$this->assertSame( 'GET', $routes['almost-famous/v1/oauth/callback']['methods'] );
		$this->assertSame( 'POST', $routes['almost-famous/v1/oauth/disconnect']['methods'] );
	}
}
