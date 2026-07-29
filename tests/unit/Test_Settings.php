<?php
/**
 * Tests for the Settings admin controller.
 *
 * Covers:
 *   - sanitize_settings() clamping per field group
 *   - sanitize_daily_budget_limit() casts to non-negative float
 *   - get_platforms() caches via transient
 *   - get_platform_status_display() returns label/class/icon per status
 *   - ajax_test_connection() hits /auth/validate (via get_top)
 *   - removed dead surfaces (webhook secret, business rules) stay removed
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Settings;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $option_group, string $option_name, array $args = array() ): bool {
		global $af_test_registered_settings;
		$af_test_registered_settings[ $option_name ] = array(
			'group' => $option_group,
			'args'  => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( string $id, string $title, mixed $callback, string $page ): void {
		global $af_test_settings_sections;
		$af_test_settings_sections[ $id ] = compact( 'title', 'callback', 'page' );
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( string $id, string $title, mixed $callback, string $page, string $section, array $args = array() ): void {
		global $af_test_settings_fields;
		$af_test_settings_fields[ $id ] = compact( 'title', 'callback', 'page', 'section', 'args' );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action = '', string $field = '_wpnonce', bool $die = true ): int {
		// Always returns 1 (valid) in tests — capability checks are the
		// real gate, and nonce verification is exercised elsewhere.
		return 1;
	}
}

class Test_Settings extends TestCase {

	private Settings $settings;
	private Api_Client $client;
	private Api_Cache $cache;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		global $af_test_registered_settings, $af_test_settings_sections,
			$af_test_settings_fields;
		$af_test_registered_settings = array();
		$af_test_settings_sections   = array();
		$af_test_settings_fields     = array();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test' ) );
		$this->client   = new Api_Client( $auth );
		$this->cache    = new Api_Cache();
		$this->settings = new Settings( $this->client, $this->cache );
	}

	// -------------------------------------------------------------------
	// Menu + Settings API registration
	// -------------------------------------------------------------------

	public function test_register_page_uses_manage_settings_capability(): void {
		$this->settings->register_page();
		$pages = af_test_get_menu_pages();
		$this->assertSame( 'af_manage_settings', $pages[0]['capability'] );
		$this->assertSame( Settings::PAGE_SLUG, $pages[0]['menu_slug'] );
	}

	public function test_register_settings_registers_all_groups(): void {
		$this->settings->register_settings();

		global $af_test_registered_settings;
		$this->assertArrayHasKey( 'af_settings', $af_test_registered_settings );
		$this->assertArrayHasKey( 'af_daily_budget_limit', $af_test_registered_settings );
		$this->assertArrayHasKey( 'af_cache_ttl_platform_status', $af_test_registered_settings );
	}

	public function test_register_settings_registers_default_destination(): void {
		$this->settings->register_settings();

		global $af_test_registered_settings, $af_test_settings_sections;

		$this->assertArrayHasKey( 'af_campaign_defaults_section', $af_test_settings_sections );
		$this->assertArrayHasKey( \AlmostFamous\Config::DESTINATION_PAGE_OPTION, $af_test_registered_settings );
		$this->assertSame(
			'absint',
			$af_test_registered_settings[ \AlmostFamous\Config::DESTINATION_PAGE_OPTION ]['args']['sanitize_callback']
		);
	}

	public function test_default_destination_field_lists_published_pages_and_shows_resolved_url(): void {
		$page = af_test_add_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Listen Now',
			)
		);
		af_test_add_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Unfinished Draft',
			)
		);
		update_option( \AlmostFamous\Config::DESTINATION_PAGE_OPTION, $page->ID );

		ob_start();
		$this->settings->render_default_destination_field();
		$html = (string) ob_get_clean();

		// The picker offers the home page plus published pages only.
		$this->assertStringContainsString( 'Site home page', $html );
		$this->assertStringContainsString( 'Listen Now', $html );
		$this->assertStringNotContainsString( 'Unfinished Draft', $html );
		// And the admin can see exactly where ads will land.
		$this->assertStringContainsString( 'https://example.test/?page_id=' . $page->ID, $html );
	}

	public function test_register_settings_omits_dead_webhook_and_rules_surfaces(): void {
		$this->settings->register_settings();

		global $af_test_registered_settings, $af_test_settings_sections;

		// No backend counterparty exists for either surface: the webhook
		// secret has nothing to verify against (the receiver is dormant) and
		// business rules were never synced or evaluated anywhere.
		$this->assertArrayNotHasKey( 'af_webhook_secret', $af_test_registered_settings );
		$this->assertArrayNotHasKey( 'af_business_rules', $af_test_registered_settings );
		$this->assertArrayNotHasKey( 'af_webhook_section', $af_test_settings_sections );
		$this->assertArrayNotHasKey( 'af_rules_section', $af_test_settings_sections );
	}

	// -------------------------------------------------------------------
	// Sanitization callbacks
	// -------------------------------------------------------------------

	public function test_sanitize_settings_clamps_active_ttl(): void {
		$result = $this->settings->sanitize_settings( array( 'cache_ttl_active' => 5 ) );
		$this->assertSame( 10, $result['cache_ttl_active'] );

		$result = $this->settings->sanitize_settings( array( 'cache_ttl_active' => 99999 ) );
		$this->assertSame( 3600, $result['cache_ttl_active'] );
	}

	public function test_sanitize_settings_clamps_archived_ttl(): void {
		$result = $this->settings->sanitize_settings( array( 'cache_ttl_archived' => 1 ) );
		$this->assertSame( 60, $result['cache_ttl_archived'] );

		$result = $this->settings->sanitize_settings( array( 'cache_ttl_archived' => 999999 ) );
		$this->assertSame( 86400, $result['cache_ttl_archived'] );
	}

	public function test_sanitize_settings_clamps_budget_multiplier_upper_bound(): void {
		$result = $this->settings->sanitize_settings( array( 'budget_safety_multiplier' => 250 ) );
		$this->assertSame( 100, $result['budget_safety_multiplier'] );
	}

	public function test_sanitize_settings_passes_through_in_range_multiplier(): void {
		$result = $this->settings->sanitize_settings( array( 'budget_safety_multiplier' => '15' ) );
		$this->assertSame( 15, $result['budget_safety_multiplier'] );
	}

	public function test_sanitize_settings_returns_defaults_when_keys_missing(): void {
		$result = $this->settings->sanitize_settings( array() );
		$this->assertSame( 60, $result['cache_ttl_active'] );
		$this->assertSame( 300, $result['cache_ttl_archived'] );
		$this->assertSame( 10, $result['budget_safety_multiplier'] );
	}

	public function test_sanitize_settings_treats_non_array_as_empty(): void {
		$result = $this->settings->sanitize_settings( 'string-input' );
		$this->assertSame( 60, $result['cache_ttl_active'] );
	}

	public function test_sanitize_daily_budget_limit_clamps_to_zero(): void {
		$this->assertSame( 0.0, $this->settings->sanitize_daily_budget_limit( -50 ) );
		$this->assertSame( 1234.56, $this->settings->sanitize_daily_budget_limit( '1234.56' ) );
	}

	// -------------------------------------------------------------------
	// Platform status helpers
	// -------------------------------------------------------------------

	public function test_get_platform_status_display_connected(): void {
		$out = $this->settings->get_platform_status_display( array( 'status' => 'connected' ) );
		$this->assertSame( 'Connected', $out['label'] );
		$this->assertStringContainsString( 'connected', $out['class'] );
		$this->assertStringContainsString( 'yes', $out['icon'] );
	}

	public function test_get_platform_status_display_degraded(): void {
		$out = $this->settings->get_platform_status_display( array( 'status' => 'degraded' ) );
		$this->assertSame( 'Degraded', $out['label'] );
		$this->assertStringContainsString( 'warning', $out['icon'] );
	}

	public function test_get_platform_status_display_disconnected_default(): void {
		$out = $this->settings->get_platform_status_display( array() );
		$this->assertSame( 'Disconnected', $out['label'] );
	}

	// -------------------------------------------------------------------
	// get_platforms caching
	// -------------------------------------------------------------------

	public function test_get_platforms_derives_status_from_connections_and_system_creds(): void {
		// Own META connection (degraded health) + shared TIKTOK credential;
		// GOOGLE and SPOTIFY have nothing → disconnected.
		af_test_register_http_response(
			'/public/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'credentials' => array(
							array(
								'id'               => 'cred_meta',
								'platform'         => 'META',
								'status'           => 'active',
								'lastHealthStatus' => 'degraded',
								'source'           => 'user',
								'createdAt'        => '2026-04-01T00:00:00Z',
								'updatedAt'        => '2026-04-01T00:00:00Z',
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
							array( 'id' => 'sys_tk', 'platform' => 'TIKTOK', 'status' => 'active' ),
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

		$first = $this->settings->get_platforms();

		$this->assertCount( 4, $first );
		$by_platform = array_column( $first, 'status', 'platform' );
		$this->assertSame( 'degraded', $by_platform['META'] );
		$this->assertSame( 'active', $by_platform['TIKTOK'] );
		$this->assertSame( 'disconnected', $by_platform['GOOGLE'] );
		$this->assertSame( 'disconnected', $by_platform['SPOTIFY'] );

		// Cached: second call should not refire any request.
		$count_before = count( af_test_get_http_requests() );
		$second       = $this->settings->get_platforms();
		$count_after  = count( af_test_get_http_requests() );

		$this->assertSame( $first, $second );
		$this->assertSame( $count_before, $count_after, 'Second call must hit the transient cache.' );
	}

	public function test_get_platforms_reports_all_disconnected_on_error(): void {
		af_test_register_http_response(
			'/public/auth',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'error' => array( 'code' => 'down', 'message' => 'x' ) ) ),
				'headers'  => array(),
			)
		);

		$platforms = $this->settings->get_platforms();

		$this->assertCount( 4, $platforms );
		foreach ( $platforms as $platform ) {
			$this->assertSame( 'disconnected', $platform['status'] );
		}
	}

	// -------------------------------------------------------------------
	// AJAX: test connection hits /auth/validate via get_top
	// -------------------------------------------------------------------

	public function test_ajax_test_connection_calls_auth_validate_endpoint(): void {
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'valid' => true ) ),
				'headers'  => array(),
			)
		);

		try {
			$this->settings->ajax_test_connection();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Successfully connected', $e->getMessage() );
		}

		$req  = af_test_get_http_requests();
		$last = end( $req );
		$this->assertStringContainsString( '/auth/validate', (string) $last['url'] );
		$this->assertStringNotContainsString( '/public/', (string) $last['url'] );
	}

	public function test_ajax_test_connection_surfaces_error_from_api(): void {
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 401 ),
				'body'     => wp_json_encode( array( 'error' => array( 'code' => 'unauthorized', 'message' => 'bad' ) ) ),
				'headers'  => array(),
			)
		);

		try {
			$this->settings->ajax_test_connection();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Connection failed', $e->getMessage() );
		}
	}

	public function test_ajax_test_connection_blocks_without_capability(): void {
		af_test_set_caps( array() );

		try {
			$this->settings->ajax_test_connection();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------
	// Consent checkbox sanitization
	// -------------------------------------------------------------------

	public function test_sanitize_checkbox_normalizes_input(): void {
		$this->assertSame( '1', $this->settings->sanitize_checkbox( '1' ) );
		$this->assertSame( '', $this->settings->sanitize_checkbox( '0' ) );
		$this->assertSame( '', $this->settings->sanitize_checkbox( null ) );
		$this->assertSame( '', $this->settings->sanitize_checkbox( 'yes' ) );
	}
}
