<?php
/**
 * Tests that public portal write routes require X-WP-Nonce when the caller is
 * already logged in, so an admin's browser cookies alone cannot mutate state.
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

class Test_Api_Proxy_Csrf extends TestCase {

	private Api_Proxy $proxy;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$auth        = new Api_Auth();
		$this->proxy = new Api_Proxy(
			new Api_Client( $auth ),
			new Api_Cache()
		);
	}

	private function build_request( string $method, array $headers = array() ): WP_REST_Request {
		$req = new WP_REST_Request( $method, '/almost-famous/v1/campaigns' );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return $req;
	}

	public function test_logged_in_admin_get_passes_without_nonce(): void {
		af_test_set_caps( array( 'manage_options' => true ) );
		$req = $this->build_request( 'GET' );
		$this->assertTrue( $this->proxy->check_public_permission( $req ) );
	}

	public function test_logged_in_admin_post_without_nonce_is_rejected(): void {
		af_test_set_caps( array( 'manage_options' => true ) );
		$req    = $this->build_request( 'POST' );
		$result = $this->proxy->check_public_permission( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_csrf_missing', $result->get_error_code() );
	}

	public function test_logged_in_admin_post_with_valid_nonce_passes(): void {
		af_test_set_caps( array( 'manage_options' => true ) );
		$req = $this->build_request( 'POST', array( 'X-WP-Nonce' => 'test-nonce' ) );

		$this->assertTrue( $this->proxy->check_public_permission( $req ) );
	}

	public function test_logged_in_admin_patch_with_invalid_nonce_is_rejected(): void {
		af_test_set_caps( array( 'manage_options' => true ) );
		$req    = $this->build_request( 'PATCH', array( 'X-WP-Nonce' => 'nope' ) );
		$result = $this->proxy->check_public_permission( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_csrf_invalid', $result->get_error_code() );
	}

	public function test_anonymous_post_is_write_forbidden(): void {
		af_test_set_caps( array() );
		$req    = $this->build_request( 'POST' );
		$result = $this->proxy->check_public_permission( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_write_forbidden', $result->get_error_code() );
	}

	public function test_read_only_user_post_is_forbidden_even_with_nonce(): void {
		// A user with view-but-not-manage capability cannot write.
		af_test_set_caps( array( 'af_view_campaigns' => true ) );
		$req    = $this->build_request( 'POST', array( 'X-WP-Nonce' => 'test-nonce' ) );
		$result = $this->proxy->check_public_permission( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_forbidden', $result->get_error_code() );
	}

	public function test_read_only_user_get_passes(): void {
		af_test_set_caps( array( 'af_view_campaigns' => true ) );
		$req = $this->build_request( 'GET' );
		$this->assertTrue( $this->proxy->check_public_permission( $req ) );
	}
}
