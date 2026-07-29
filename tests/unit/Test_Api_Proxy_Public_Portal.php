<?php
/**
 * Tests the guest (unauthenticated) path of Api_Proxy::check_public_permission.
 *
 * The portal is an authenticated console: anonymous visitors get the sign-in
 * gate client-side and NO data server-side. The only guest read allowed is
 * demo mode, which serves synthetic fixtures (no real org data). Writes are
 * never allowed for guests. Distinct from Test_Api_Proxy_Csrf.php, which covers
 * the logged-in nonce branch.
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

class Test_Api_Proxy_Public_Portal extends TestCase {

	private Api_Proxy $proxy;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		// Guest visitor: no WordPress caps and not logged in.
		af_test_set_caps( array() );

		$auth        = new Api_Auth();
		$this->proxy = new Api_Proxy( new Api_Client( $auth ), new Api_Cache() );
	}

	private function request( string $method, array $headers = array() ): WP_REST_Request {
		$req = new WP_REST_Request( $method, '/almost-famous/v1/campaigns' );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return $req;
	}

	// -----------------------------------------------------------------------
	// Guest reads are rejected unless demo mode is on.
	// -----------------------------------------------------------------------

	public function test_anonymous_read_requires_sign_in(): void {
		$result = $this->proxy->check_public_permission( $this->request( 'GET' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_auth_required', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_anonymous_read_is_rejected_even_with_a_stale_portal_token_header(): void {
		// The portal token was removed; a leftover/forged header must not grant
		// any access — the caller is still anonymous.
		$result = $this->proxy->check_public_permission(
			$this->request(
				'GET',
				array(
					'X-AF-Portal-Token' => 'anything.deadbeef',
					'Origin'            => 'https://example.test',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_auth_required', $result->get_error_code() );
	}

	public function test_demo_mode_allows_anonymous_read(): void {
		// Demo mode serves only synthetic fixtures, so it is safe to preview
		// without a login.
		update_option( 'af_public_portal_demo_mode', true );

		$result = $this->proxy->check_public_permission( $this->request( 'GET' ) );

		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// Guest writes are always rejected — before any other consideration.
	// -----------------------------------------------------------------------

	public function test_guest_post_is_write_forbidden(): void {
		$result = $this->proxy->check_public_permission( $this->request( 'POST' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_write_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_guest_patch_is_write_forbidden(): void {
		$result = $this->proxy->check_public_permission( $this->request( 'PATCH' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_write_forbidden', $result->get_error_code() );
	}

	public function test_guest_delete_is_write_forbidden(): void {
		$result = $this->proxy->check_public_permission( $this->request( 'DELETE' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_write_forbidden', $result->get_error_code() );
	}

	public function test_demo_mode_does_not_allow_guest_writes(): void {
		update_option( 'af_public_portal_demo_mode', true );

		$result = $this->proxy->check_public_permission( $this->request( 'POST' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'af_portal_write_forbidden', $result->get_error_code() );
	}
}
