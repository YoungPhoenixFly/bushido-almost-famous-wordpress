<?php
/**
 * Tests for the Config class: API base URL resolution, public-portal token
 * signing/verification, same-site request context, and demo-mode flag.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test Config class.
 */
class Test_Config extends TestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$this->clear_config_env();
	}

	/**
	 * Tear down state after each test so envs don't leak across cases.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clear_config_env();
		parent::tearDown();
	}

	/**
	 * Remove every Config-relevant environment variable that could influence
	 * resolution. Tests opt back in to specific keys as needed.
	 *
	 * @return void
	 */
	private function clear_config_env(): void {
		$keys = array(
			'AF_API_BASE_URL',
			'ALMOST_FAMOUS_API_BASE_URL',
			'WP_ALMOST_FAMOUS_API_BASE_URL',
			'AF_BUSHIDO_APP_URL',
			'ALMOST_FAMOUS_BUSHIDO_APP_URL',
			'WP_ALMOST_FAMOUS_BUSHIDO_APP_URL',
			'AF_ENVIRONMENT',
			'ALMOST_FAMOUS_ENVIRONMENT',
			'WP_ENVIRONMENT_TYPE',
			'AF_PUBLIC_PORTAL_DEMO_MODE',
			'ALMOST_FAMOUS_PUBLIC_PORTAL_DEMO_MODE',
		);

		foreach ( $keys as $key ) {
			putenv( $key );
			unset( $_ENV[ $key ], $_SERVER[ $key ] );
		}
	}

	// -----------------------------------------------------------------------
	// resolve_api_base_url
	// -----------------------------------------------------------------------

	/**
	 * Constant takes precedence over every other source.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_constant_wins_over_env_and_option(): void {
		define( 'AF_API_BASE_URL', 'https://api.almost-famous.backend-bushidoco.de/api/v1' );
		define( 'AF_BUSHIDO_APP_URL', 'https://bushido.is' );
		putenv( 'AF_API_BASE_URL=https://from-env.example.test/api/v1' );
		putenv( 'AF_BUSHIDO_APP_URL=https://from-env.example.test' );
		$_ENV['AF_API_BASE_URL']    = 'https://from-env.example.test/api/v1';
		$_SERVER['AF_API_BASE_URL'] = 'https://from-env.example.test/api/v1';
		update_option( 'af_api_base_url', 'https://from-option.example.test/api/v1' );
		update_option( 'af_bushido_app_url', 'https://from-option.example.test' );

		$this->assertSame(
			'https://api.almost-famous.backend-bushidoco.de/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * Env var wins when the constant is absent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_env_var_wins_when_constant_missing(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );
		putenv( 'AF_API_BASE_URL=https://env-primary.example.test/api/v1' );
		putenv( 'AF_BUSHIDO_APP_URL=https://env-primary.example.test' );

		$this->assertSame(
			'https://env-primary.example.test/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * Alternate env variable keys are honored.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_alternate_env_keys(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );
		putenv( 'ALMOST_FAMOUS_API_BASE_URL=https://alt-env.example.test/api/v1' );
		putenv( 'ALMOST_FAMOUS_BUSHIDO_APP_URL=https://alt-env.example.test' );

		$this->assertSame(
			'https://alt-env.example.test/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * Option falls through when neither constant nor env are set.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_option_used_when_constant_and_env_missing(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );
		update_option( 'af_api_base_url', 'https://from-option.example.test/api/v1' );
		update_option( 'af_bushido_app_url', 'https://from-option.example.test' );

		$this->assertSame(
			'https://from-option.example.test/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * Production is the source and WordPress.org default.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_default_production_when_no_other_source(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );

		$this->assertSame(
			'https://api.almost-famous.backend-bushidoco.de/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * WordPress runtime environment cannot silently select another service.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_remains_production_for_staging_wordpress_environment(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );

		$this->assertSame(
			'https://api.almost-famous.backend-bushidoco.de/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * Development also needs an explicit paired override or staging artifact.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_remains_production_for_development_environment(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );
		putenv( 'AF_ENVIRONMENT=development' );

		$this->assertSame(
			'https://api.almost-famous.backend-bushidoco.de/api/v1',
			Config::resolve_api_base_url()
		);
	}

	/**
	 * Bare hosts get an `/api/v1` suffix appended.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_resolve_api_base_url_normalizes_host_without_path(): void {
		$this->assertFalse( defined( 'AF_API_BASE_URL' ) );
		putenv( 'AF_API_BASE_URL=https://bare.example.test/' );
		putenv( 'AF_BUSHIDO_APP_URL=https://bare.example.test/' );

		$this->assertSame(
			'https://bare.example.test/api/v1',
			Config::resolve_api_base_url()
		);
	}

	// -----------------------------------------------------------------------
	// resolve_bushido_app_url
	// -----------------------------------------------------------------------

	public function test_resolve_bushido_app_url_defaults_to_production(): void {
		$this->assertSame( 'https://bushido.is', Config::resolve_bushido_app_url() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_bushido_app_url_honors_constant_override(): void {
		define( 'AF_API_BASE_URL', 'https://api.example.test/api/v1' );
		define( 'AF_BUSHIDO_APP_URL', ' https://connect.example.test/custom/ ' );

		$this->assertSame( 'https://connect.example.test/custom', Config::resolve_bushido_app_url() );
	}

	/**
	 * A partial pair fails closed instead of mixing environments.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_partial_environment_pair_is_rejected(): void {
		putenv( 'AF_API_BASE_URL=https://api.example.test/api/v1' );

		$this->expectException( UnexpectedValueException::class );
		Config::resolve_service_endpoints();
	}

	/**
	 * Query strings cannot be smuggled ahead of the normalized API path.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_endpoint_pair_rejects_api_query_strings(): void {
		putenv( 'AF_API_BASE_URL=https://api.example.test?tenant=1' );
		putenv( 'AF_BUSHIDO_APP_URL=https://connect.example.test' );

		$this->expectException( UnexpectedValueException::class );
		Config::resolve_service_endpoints();
	}

	/**
	 * Fragments are browser-only state and never valid service endpoints.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_endpoint_pair_rejects_app_fragments(): void {
		putenv( 'AF_API_BASE_URL=https://api.example.test/api/v1' );
		putenv( 'AF_BUSHIDO_APP_URL=https://connect.example.test#consent' );

		$this->expectException( UnexpectedValueException::class );
		Config::resolve_service_endpoints();
	}

	/**
	 * Only an explicitly built staging channel selects staging.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_explicit_staging_release_channel_resolves_coherent_pair(): void {
		define( 'BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL', 'staging' );

		$this->assertSame(
			array(
				'api' => 'https://api.almost-famous-staging.backend-bushidoco.de/api/v1',
				'app' => 'https://staging.bushido.is',
			),
			Config::resolve_service_endpoints()
		);
	}

	// -----------------------------------------------------------------------
	// is_demo_mode_enabled
	// -----------------------------------------------------------------------

	/**
	 * Defaults to false when nothing configures it.
	 *
	 * @return void
	 */
	public function test_is_demo_mode_enabled_defaults_false(): void {
		$this->assertFalse( Config::is_demo_mode_enabled() );
	}

	/**
	 * Truthy env variable flips the flag on.
	 *
	 * @return void
	 */
	public function test_is_demo_mode_enabled_env_truthy(): void {
		putenv( 'AF_PUBLIC_PORTAL_DEMO_MODE=1' );

		$this->assertTrue( Config::is_demo_mode_enabled() );
	}

	/**
	 * Falsy env variable forces the flag off even when the option says yes.
	 *
	 * @return void
	 */
	public function test_is_demo_mode_enabled_env_falsy_overrides_option(): void {
		putenv( 'AF_PUBLIC_PORTAL_DEMO_MODE=false' );
		update_option( 'af_public_portal_demo_mode', true );

		$this->assertFalse( Config::is_demo_mode_enabled() );
	}

	/**
	 * Option enables demo mode when no env variable is set.
	 *
	 * @return void
	 */
	public function test_is_demo_mode_enabled_via_option(): void {
		update_option( 'af_public_portal_demo_mode', '1' );

		$this->assertTrue( Config::is_demo_mode_enabled() );
	}

	// -----------------------------------------------------------------------
	// get_default_destination_url
	// -----------------------------------------------------------------------

	/**
	 * With no page configured, ads default to this site's home page — never an
	 * off-site destination the attribution cookie could not observe.
	 *
	 * @return void
	 */
	public function test_default_destination_falls_back_to_site_home(): void {
		$this->assertSame( 'https://example.test/', Config::get_default_destination_url() );
	}

	/**
	 * A configured published page resolves to its permalink.
	 *
	 * @return void
	 */
	public function test_default_destination_uses_configured_published_page(): void {
		$page = af_test_add_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Listen',
			)
		);
		update_option( Config::DESTINATION_PAGE_OPTION, $page->ID );

		$this->assertSame( 'https://example.test/?page_id=' . $page->ID, Config::get_default_destination_url() );
	}

	/**
	 * An unpublished (or trashed) page must not become an ad destination —
	 * paid traffic would land on a 404. Fall back to the home page.
	 *
	 * @return void
	 */
	public function test_default_destination_ignores_unpublished_page(): void {
		$page = af_test_add_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Unfinished',
			)
		);
		update_option( Config::DESTINATION_PAGE_OPTION, $page->ID );

		$this->assertSame( 'https://example.test/', Config::get_default_destination_url() );
	}

	/**
	 * A deleted page id (option left dangling) falls back to the home page.
	 *
	 * @return void
	 */
	public function test_default_destination_ignores_missing_page(): void {
		update_option( Config::DESTINATION_PAGE_OPTION, 4242 );

		$this->assertSame( 'https://example.test/', Config::get_default_destination_url() );
	}

	/**
	 * The resolved destination is filterable.
	 *
	 * @return void
	 */
	public function test_default_destination_is_filterable(): void {
		add_filter(
			'almost_famous/default_destination_url',
			static fn (): string => 'https://example.test/tour'
		);

		$this->assertSame( 'https://example.test/tour', Config::get_default_destination_url() );
	}

}
