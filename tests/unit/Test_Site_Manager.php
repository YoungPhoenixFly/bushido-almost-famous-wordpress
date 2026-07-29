<?php
/**
 * Tests for Site_Manager beyond the network-key client signing covered by
 * Test_Site_Manager_Network_Key.php. Focuses on key-source resolution, the
 * site-scoped campaigns query, the context shape, and the per-site override
 * gate that controls whether a site can manage its own key.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Multisite\Network_Admin;
use AlmostFamous\Multisite\Site_Manager;
use PHPUnit\Framework\TestCase;

class Test_Site_Manager extends TestCase {

	private Api_Auth $auth;
	private Network_Admin $network_admin;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$this->auth          = new Api_Auth();
		$this->network_admin = new Network_Admin( $this->auth );
	}

	private function manager(): Site_Manager {
		return new Site_Manager( $this->auth, $this->network_admin );
	}

	// -----------------------------------------------------------------------
	// get_api_key_source()
	// -----------------------------------------------------------------------

	public function test_source_is_none_when_no_keys_configured_single_site(): void {
		af_test_set_multisite( false );
		$this->assertSame( 'none', $this->manager()->get_api_key_source() );
	}

	public function test_source_is_site_on_single_site_when_site_key_present(): void {
		af_test_set_multisite( false );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_site_solo' ) );

		$this->assertSame( 'site', $this->manager()->get_api_key_source() );
	}

	public function test_source_is_site_on_multisite_when_override_allowed_and_site_key_present(): void {
		af_test_set_multisite( true );
		update_site_option( 'af_allow_site_override', '1' );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_site_ms' ) );
		update_site_option(
			Network_Admin::NETWORK_API_KEY_OPTION,
			$this->auth->encrypt_api_key( 'bsh_network_ms' )
		);

		$this->assertSame( 'site', $this->manager()->get_api_key_source() );
	}

	public function test_source_is_network_when_override_disabled_even_with_site_key(): void {
		af_test_set_multisite( true );
		update_site_option( 'af_allow_site_override', '0' );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_site_ignored' ) );
		update_site_option(
			Network_Admin::NETWORK_API_KEY_OPTION,
			$this->auth->encrypt_api_key( 'bsh_network_pref' )
		);

		$this->assertSame( 'network', $this->manager()->get_api_key_source() );
	}

	public function test_source_falls_back_to_site_when_override_disabled_but_network_missing(): void {
		af_test_set_multisite( true );
		update_site_option( 'af_allow_site_override', '0' );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_site_only' ) );

		$this->assertSame( 'site', $this->manager()->get_api_key_source() );
	}

	public function test_source_is_none_when_multisite_and_no_keys_anywhere(): void {
		af_test_set_multisite( true );
		update_site_option( 'af_allow_site_override', '1' );

		$this->assertSame( 'none', $this->manager()->get_api_key_source() );
	}

	// -----------------------------------------------------------------------
	// get_site_campaigns()
	// -----------------------------------------------------------------------

	public function test_get_site_campaigns_adds_site_url_and_site_id_params(): void {
		af_test_set_multisite( true );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_for_campaigns' ) );

		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							array( 'id' => 'c1', 'name' => 'A' ),
							array( 'id' => 'c2', 'name' => 'B' ),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$client = new Api_Client( $this->auth );
		$result = $this->manager()->get_site_campaigns( $client, array( 'extra' => 'kept' ) );

		$this->assertCount( 2, $result );

		$requests = af_test_get_http_requests();
		$this->assertNotEmpty( $requests );
		$last_url = $requests[ array_key_last( $requests ) ]['url'];

		$this->assertStringContainsString( 'site_url=', $last_url );
		$this->assertStringContainsString( rawurlencode( 'https://example.test' ), rawurlencode( 'https://example.test' ) );
		// Site id forwarded as string and extra arg preserved.
		$this->assertStringContainsString( 'site_id=1', $last_url );
		$this->assertStringContainsString( 'extra=kept', $last_url );
	}

	public function test_get_site_campaigns_returns_empty_array_when_response_has_error(): void {
		af_test_set_multisite( true );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_err' ) );

		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'error' => 'boom' ) ),
				'headers'  => array(),
			)
		);

		$client = new Api_Client( $this->auth );
		$this->assertSame( array(), $this->manager()->get_site_campaigns( $client ) );
	}

	public function test_get_site_campaigns_returns_empty_when_api_returns_503_network_error(): void {
		// When the HTTP layer returns a WP_Error, Api_Client populates
		// $response['error'] which Site_Manager then short-circuits to [].
		af_test_set_multisite( true );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_dead' ) );

		af_test_register_http_response(
			'/campaigns',
			new WP_Error( 'http_request_failed', 'Connection refused' )
		);

		$client = new Api_Client( $this->auth );
		$this->assertSame( array(), $this->manager()->get_site_campaigns( $client ) );
	}

	// -----------------------------------------------------------------------
	// get_site_context()
	// -----------------------------------------------------------------------

	public function test_get_site_context_shape(): void {
		$ctx = $this->manager()->get_site_context();

		$this->assertSame(
			array( 'site_id', 'site_url', 'blog_id' ),
			array_keys( $ctx )
		);
		$this->assertSame( '1', $ctx['site_id'] );
		$this->assertSame( 1, $ctx['blog_id'] );
		$this->assertSame( 'https://example.test', $ctx['site_url'] );
	}

	// -----------------------------------------------------------------------
	// can_manage_site_key()
	// -----------------------------------------------------------------------

	public function test_can_manage_site_key_is_true_on_single_site(): void {
		af_test_set_multisite( false );
		$this->assertTrue( $this->manager()->can_manage_site_key() );
	}

	public function test_can_manage_site_key_follows_override_flag_in_multisite(): void {
		af_test_set_multisite( true );

		update_site_option( 'af_allow_site_override', '1' );
		$this->assertTrue( $this->manager()->can_manage_site_key() );

		update_site_option( 'af_allow_site_override', '0' );
		$this->assertFalse( $this->manager()->can_manage_site_key() );
	}
}
