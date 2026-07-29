<?php
/**
 * Tests Site_Manager builds an Api_Client signed with the network key when
 * the per-site override is disabled, and with the site key otherwise.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Multisite\Network_Admin;
use AlmostFamous\Multisite\Site_Manager;
use PHPUnit\Framework\TestCase;

class Test_Site_Manager_Network_Key extends TestCase {

	private const SITE_KEY    = 'bsh_site_localkey';
	private const NETWORK_KEY = 'bsh_network_globalkey';

	private Api_Auth $auth;
	private Site_Manager $manager;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		af_test_set_multisite( true );

		$this->auth = new Api_Auth();

		// Site-level key.
		update_option( 'af_api_key', $this->auth->encrypt_api_key( self::SITE_KEY ) );

		// Network-level key.
		update_site_option(
			Network_Admin::NETWORK_API_KEY_OPTION,
			$this->auth->encrypt_api_key( self::NETWORK_KEY )
		);

		$this->manager = new Site_Manager( $this->auth, new Network_Admin( $this->auth ) );
	}

	private function captured_auth_header( string $url_pattern ): string {
		foreach ( af_test_get_http_requests() as $r ) {
			if ( str_contains( $r['url'], $url_pattern ) ) {
				return (string) ( $r['args']['headers']['X-API-Key'] ?? '' );
			}
		}
		return '';
	}

	public function test_network_key_used_when_override_disabled(): void {
		update_site_option( 'af_allow_site_override', '0' );
		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$client = $this->manager->get_api_client();
		$this->assertNotNull( $client );

		$client->get( '/campaigns' );

		$this->assertSame( self::NETWORK_KEY, $this->captured_auth_header( '/campaigns' ) );
	}

	public function test_site_key_used_when_override_enabled(): void {
		update_site_option( 'af_allow_site_override', '1' );
		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$client = $this->manager->get_api_client();
		$this->assertNotNull( $client );

		$client->get( '/campaigns' );

		$this->assertSame( self::SITE_KEY, $this->captured_auth_header( '/campaigns' ) );
	}

	public function test_falls_back_to_network_when_site_missing(): void {
		update_site_option( 'af_allow_site_override', '1' );
		delete_option( 'af_api_key' );
		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$client = $this->manager->get_api_client();
		$this->assertNotNull( $client );

		$client->get( '/campaigns' );

		$this->assertSame( self::NETWORK_KEY, $this->captured_auth_header( '/campaigns' ) );
	}
}
