<?php
/**
 * Tests for the multisite Network_Admin handler.
 *
 * Covers network-capability gating, encrypted key round-trips, and the
 * per-site override toggle. Avoids duplicating coverage already provided
 * by Test_Site_Manager_Network_Key.php.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Multisite\Network_Admin;
use PHPUnit\Framework\TestCase;

class Test_Network_Admin extends TestCase {

	private Api_Auth $auth;
	private Network_Admin $network_admin;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		af_test_set_multisite( true );

		$this->auth          = new Api_Auth();
		$this->network_admin = new Network_Admin( $this->auth );
	}

	public function test_register_network_page_requires_manage_network_options_capability(): void {
		$this->network_admin->register_network_page();

		$pages = af_test_get_menu_pages();
		$this->assertNotEmpty( $pages, 'Submenu page should be registered.' );

		$page = array_values(
			array_filter(
				$pages,
				static fn( array $p ) => Network_Admin::PAGE_SLUG === $p['menu_slug']
			)
		)[0] ?? null;

		$this->assertNotNull( $page );
		$this->assertSame( 'manage_network_options', $page['capability'] );
		$this->assertSame( 'settings.php', $page['parent_slug'] );
		$this->assertSame( 'sub', $page['type'] );
	}

	public function test_has_network_api_key_returns_false_when_unset(): void {
		$this->assertFalse( $this->network_admin->has_network_api_key() );
		$this->assertSame( '', $this->network_admin->get_network_api_key() );
	}

	public function test_network_key_round_trip_via_save_and_decrypt(): void {
		// Simulate handle_save_settings by storing the encrypted key the same way.
		$plaintext = 'bsh_network_roundtrip_xyz';
		update_site_option(
			Network_Admin::NETWORK_API_KEY_OPTION,
			$this->auth->encrypt_api_key( $plaintext )
		);

		$this->assertTrue( $this->network_admin->has_network_api_key() );
		$this->assertSame( $plaintext, $this->network_admin->get_network_api_key() );
	}

	public function test_failed_legacy_network_migration_preserves_readable_ciphertext(): void {
		$legacy = $this->encrypt_legacy( 'bsh_network_legacy' );
		update_site_option( Network_Admin::NETWORK_API_KEY_OPTION, $legacy );
		af_test_fail_site_option_updates( Network_Admin::NETWORK_API_KEY_OPTION );

		$this->assertSame( 'bsh_network_legacy', $this->network_admin->get_network_api_key() );
		$this->assertSame(
			$legacy,
			get_site_option( Network_Admin::NETWORK_API_KEY_OPTION, '' )
		);
	}

	public function test_failed_encryption_preserves_existing_network_key(): void {
		$previous = $this->auth->encrypt_api_key( 'bsh_existing_network_key' );
		update_site_option( Network_Admin::NETWORK_API_KEY_OPTION, $previous );

		$failing_auth = new class() extends Api_Auth {
			public function encrypt_api_key( string $key ): string {
				unset( $key );
				return '';
			}
		};
		$network_admin = new Network_Admin( $failing_auth );
		$method        = new ReflectionMethod( $network_admin, 'store_network_api_key' );

		$this->assertFalse( $method->invoke( $network_admin, 'bsh_replacement' ) );
		$this->assertSame(
			$previous,
			get_site_option( Network_Admin::NETWORK_API_KEY_OPTION, '' )
		);
		$this->assertSame( 'bsh_existing_network_key', $this->network_admin->get_network_api_key() );
	}

	public function test_delete_network_api_key_clears_storage(): void {
		update_site_option(
			Network_Admin::NETWORK_API_KEY_OPTION,
			$this->auth->encrypt_api_key( 'bsh_delete_me' )
		);

		$this->assertTrue( $this->network_admin->has_network_api_key() );
		$this->assertTrue( $this->network_admin->delete_network_api_key() );
		$this->assertFalse( $this->network_admin->has_network_api_key() );
	}

	public function test_allows_site_override_defaults_to_true(): void {
		// No option set — default is '1'.
		$this->assertTrue( $this->network_admin->allows_site_override() );
	}

	public function test_allows_site_override_respects_disabled_value(): void {
		update_site_option( 'af_allow_site_override', '0' );
		$this->assertFalse( $this->network_admin->allows_site_override() );

		update_site_option( 'af_allow_site_override', '1' );
		$this->assertTrue( $this->network_admin->allows_site_override() );
	}

	public function test_init_skips_hook_registration_when_not_multisite(): void {
		af_test_set_multisite( false );
		$this->network_admin->init();

		// No exception, no menu pages registered.
		$this->assertEmpty( af_test_get_menu_pages() );
	}

	private function encrypt_legacy( string $plaintext ): string {
		$iv         = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', AUTH_KEY, OPENSSL_RAW_DATA, $iv );

		$this->assertIsString( $ciphertext );
		return base64_encode( $iv . $ciphertext );
	}

	public function test_handle_save_settings_blocks_users_lacking_manage_network_options(): void {
		af_test_set_caps( array() ); // strip caps including manage_options
		$_POST = array(
			'af_network_nonce'    => 'test-nonce',
			'af_network_api_key'  => 'bsh_attempt',
		);

		$this->expectException( RuntimeException::class );
		$this->network_admin->handle_save_settings();
	}
}
