<?php
/**
 * Tests for the Audiences admin controller.
 *
 * Covers:
 *   - fetch_audiences data envelope + error handling
 *   - resolve_platform_credentials (active connections win, system
 *     credentials fill the gaps — mirrors the proxy's estimation resolver)
 *   - get_platform_choices disables platforms without a credential and
 *     maps the plugin's YouTube case to the backend's "google" wire value
 *   - get_type_choices matches the backend contract's creatable types
 *   - enqueue_page_data localizes the credential map on the audiences
 *     page only
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Audiences;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Af_Audiences_Wpdb' ) ) {
	final class Af_Audiences_Wpdb {
		public string $options = 'wp_options';

		public function esc_like( string $text ): string {
			return $text;
		}

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}

		public function get_col( string $query ): array {
			return array();
		}
	}
}

class Test_Audiences extends TestCase {

	private Audiences $audiences;
	private Api_Client $client;
	private Api_Cache $cache;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		global $wpdb;
		$wpdb = new Af_Audiences_Wpdb();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test' ) );
		$this->client    = new Api_Client( $auth );
		$this->cache     = new Api_Cache();
		$this->audiences = new Audiences( $this->client, $this->cache );
	}

	private function mock_ok( string $needle, mixed $data = array() ): void {
		af_test_register_http_response(
			$needle,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => $data ) ),
				'headers'  => array(),
			)
		);
	}

	private function mock_err( string $needle, int $code = 500 ): void {
		af_test_register_http_response(
			$needle,
			array(
				'response' => array( 'code' => $code ),
				'body'     => wp_json_encode( array(
					'error' => array(
						'code'    => 'api_unreachable',
						'message' => 'down',
					),
				) ),
				'headers'  => array(),
			)
		);
	}

	/**
	 * Register connection + system-credential fixtures.
	 *
	 * Active META connection, expired TIKTOK connection, and a GOOGLE
	 * system credential; META also has a system credential that must NOT
	 * override the org's own connection.
	 */
	private function mock_credential_fixtures(): void {
		$this->mock_ok(
			'/auth/connections',
			array(
				'credentials' => array(
					array(
						'id'       => 'cred_meta',
						'platform' => 'META',
						'status'   => 'active',
					),
					array(
						'id'       => 'cred_tiktok_expired',
						'platform' => 'TIKTOK',
						'status'   => 'expired',
					),
				),
			)
		);

		$this->mock_ok(
			'/auth/credentials/META',
			array(
				'systemCredentials' => array(
					array(
						'id'       => 'sys_meta',
						'platform' => 'META',
					),
				),
			)
		);

		$this->mock_ok(
			'/auth/credentials/GOOGLE',
			array(
				'systemCredentials' => array(
					array(
						'id'       => 'sys_google',
						'platform' => 'GOOGLE',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------

	public function test_register_submenu_uses_view_capability(): void {
		$this->audiences->register_submenu();
		$pages = af_test_get_menu_pages();
		$this->assertCount( 1, $pages );
		$this->assertSame( 'af_view_campaigns', $pages[0]['capability'] );
		$this->assertSame( 'af-audiences', $pages[0]['menu_slug'] );
	}

	// -------------------------------------------------------------------
	// fetch_audiences
	// -------------------------------------------------------------------

	public function test_fetch_audiences_returns_data_envelope(): void {
		$this->mock_ok( '/audiences', array( array( 'id' => 'aud_1' ) ) );

		$result = $this->audiences->fetch_audiences();

		$this->assertCount( 1, $result );
		$this->assertSame( 'aud_1', $result[0]['id'] );
	}

	public function test_fetch_audiences_returns_empty_on_error(): void {
		$this->mock_err( '/audiences' );
		$this->assertSame( array(), $this->audiences->fetch_audiences() );
	}

	// -------------------------------------------------------------------
	// resolve_platform_credentials
	// -------------------------------------------------------------------

	public function test_resolve_platform_credentials_prefers_active_connections(): void {
		$this->mock_credential_fixtures();

		$map = $this->audiences->resolve_platform_credentials();

		$this->assertSame( 'cred_meta', $map['meta'], 'Own active connection must win over the system credential.' );
	}

	public function test_resolve_platform_credentials_falls_back_to_system_credentials(): void {
		$this->mock_credential_fixtures();

		$map = $this->audiences->resolve_platform_credentials();

		$this->assertArrayHasKey( 'google', $map );
		$this->assertNull( $map['google'] );
	}

	public function test_resolve_platform_credentials_skips_inactive_connections(): void {
		$this->mock_credential_fixtures();

		$map = $this->audiences->resolve_platform_credentials();

		$this->assertArrayNotHasKey( 'tiktok', $map, 'Expired connections must not resolve a credential.' );
		$this->assertArrayNotHasKey( 'spotify', $map, 'Platforms with no credential at all must be absent.' );
	}

	public function test_resolve_platform_credentials_empty_when_nothing_connected(): void {
		$this->assertSame( array(), $this->audiences->resolve_platform_credentials() );
	}

	// -------------------------------------------------------------------
	// get_platform_choices
	// -------------------------------------------------------------------

	public function test_get_platform_choices_disables_platforms_without_credentials(): void {
		$choices = $this->audiences->get_platform_choices(
			array(
				'meta'   => 'cred_meta',
				'google' => null,
			)
		);

		$by_value = array_column( $choices, null, 'value' );

		$this->assertFalse( $by_value['meta']['disabled'] );
		$this->assertFalse( $by_value['google']['disabled'] );
		$this->assertTrue( $by_value['tiktok']['disabled'] );
		$this->assertTrue( $by_value['spotify']['disabled'] );
	}

	public function test_get_platform_choices_maps_youtube_case_to_google_wire_value(): void {
		$choices = $this->audiences->get_platform_choices( array() );
		$values  = array_column( $choices, 'value' );

		$this->assertContains( 'google', $values, 'The YouTube case must map to the backend "google" platform.' );
		$this->assertNotContains( 'youtube', $values, 'The backend contract has no "youtube" platform value.' );
	}

	// -------------------------------------------------------------------
	// get_type_choices
	// -------------------------------------------------------------------

	public function test_get_type_choices_matches_contract_creatable_types(): void {
		$this->assertSame(
			array( 'custom', 'website', 'engagement', 'customer_file' ),
			array_keys( Audiences::get_type_choices() ),
			'Type choices must mirror AUDIENCE_TYPE_VALUES minus "lookalike" (created via the seed action).'
		);
	}

	// -------------------------------------------------------------------
	// enqueue_page_data
	// -------------------------------------------------------------------

	public function test_enqueue_page_data_localizes_credential_map_on_audiences_page(): void {
		$this->mock_credential_fixtures();

		$this->audiences->enqueue_page_data( 'almost-famous_page_af-audiences' );

		$localized = get_option( '__localized_afAudienceData' );
		$this->assertIsArray( $localized );
		$this->assertSame( 'cred_meta', $localized['credentials']['meta'] );
		$this->assertArrayHasKey( 'google', $localized['credentials'] );
		$this->assertNull( $localized['credentials']['google'] );
	}

	public function test_enqueue_page_data_skips_other_admin_pages(): void {
		$this->audiences->enqueue_page_data( 'index.php' );

		$this->assertFalse( get_option( '__localized_afAudienceData' ) );
	}
}
