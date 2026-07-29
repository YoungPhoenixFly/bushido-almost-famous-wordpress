<?php
/**
 * Tests for the plugin Activator: version gating, role registration, and
 * default option seeding.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Activator;
use PHPUnit\Framework\TestCase;

// Stub the activation-time WP function so the env-check branches do not blow
// up the test runner.
if ( ! function_exists( 'deactivate_plugins' ) ) {
	/**
	 * Tracks plugins that the activator tried to deactivate.
	 *
	 * @param string|string[] $plugins Plugin path or paths.
	 * @return void
	 */
	function deactivate_plugins( mixed $plugins ): void {
		global $af_test_deactivated_plugins;
		$af_test_deactivated_plugins[] = $plugins;
	}
}

/**
 * Test Activator class.
 */
class Test_Activator extends TestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		global $af_test_deactivated_plugins, $wp_version;
		$af_test_deactivated_plugins = array();
		$wp_version                  = '6.4';
	}

	/**
	 * Activation seeds the `af_setup_complete` option as boolean false.
	 *
	 * @return void
	 */
	public function test_activate_seeds_setup_complete_option(): void {
		Activator::activate();

		$this->assertSame( false, get_option( 'af_setup_complete', 'sentinel' ) );
	}

	/**
	 * Activation arms the one-shot setup-wizard redirect for a fresh install,
	 * so the user is sent to the connection screen instead of an empty
	 * dashboard. Regression guard: this transient was previously only ever set
	 * in tests, leaving the wizard unreachable in production.
	 *
	 * @return void
	 */
	public function test_activate_arms_setup_redirect_when_not_configured(): void {
		Activator::activate();

		$this->assertNotFalse( get_transient( 'af_activation_redirect' ) );
	}

	/**
	 * Re-activating an already-configured site must NOT arm the redirect, so
	 * existing users are not bounced to the wizard on every plugin update.
	 *
	 * @return void
	 */
	public function test_activate_does_not_arm_redirect_when_already_setup(): void {
		update_option( 'af_setup_complete', true );

		Activator::activate();

		$this->assertFalse( get_transient( 'af_activation_redirect' ) );
	}

	/**
	 * Activation seeds the `af_settings` defaults including cache TTLs and
	 * the budget safety multiplier.
	 *
	 * @return void
	 */
	public function test_activate_seeds_af_settings_defaults(): void {
		Activator::activate();

		$settings = get_option( 'af_settings' );

		$this->assertIsArray( $settings );
		$this->assertSame( 60, $settings['cache_ttl_active'] ?? null );
		$this->assertSame( 300, $settings['cache_ttl_archived'] ?? null );
		$this->assertSame( 10, $settings['budget_safety_multiplier'] ?? null );
	}

	/**
	 * Existing `af_settings` are not overwritten by add_option on re-activation.
	 *
	 * @return void
	 */
	public function test_activate_does_not_overwrite_existing_settings(): void {
		update_option(
			'af_settings',
			array(
				'cache_ttl_active'         => 999,
				'cache_ttl_archived'       => 999,
				'budget_safety_multiplier' => 999,
			)
		);

		Activator::activate();

		$settings = get_option( 'af_settings' );

		$this->assertSame( 999, $settings['cache_ttl_active'] );
		$this->assertSame( 999, $settings['budget_safety_multiplier'] );
	}

	/**
	 * Roles::register_roles() is called from activate() — its side effect of
	 * writing the af_role_mapping option proves the call ran.
	 *
	 * @return void
	 */
	public function test_activate_registers_role_mapping(): void {
		Activator::activate();

		$mapping = get_option( 'af_role_mapping' );

		$this->assertIsArray( $mapping );
		$this->assertSame( 'bushido_admin', $mapping['administrator'] ?? null );
		$this->assertSame( 'viewer', $mapping['subscriber'] ?? null );
	}

	/**
	 * The lifecycle action is fired exactly once per activation.
	 *
	 * @return void
	 */
	public function test_activate_fires_lifecycle_action(): void {
		Activator::activate();

		$this->assertSame( 1, did_action( 'almost_famous/plugin/activated' ) );
	}

	/**
	 * Network activation provisions every existing site without arming a
	 * per-site browser redirect.
	 *
	 * @return void
	 */
	public function test_network_activation_provisions_all_existing_sites(): void {
		af_test_set_multisite( true );
		global $af_test_site_ids, $af_test_blog_switches;
		$af_test_site_ids = array( 2, 7, 11 );

		Activator::activate( true );

		$this->assertSame( array( 2, 7, 11 ), $af_test_blog_switches );
		$this->assertFalse( get_transient( 'af_activation_redirect' ) );
		$this->assertSame( 1, get_current_blog_id() );
	}

	/**
	 * Sites created after network activation receive the same defaults.
	 *
	 * @return void
	 */
	public function test_new_network_site_is_provisioned(): void {
		af_test_set_multisite( true );
		update_site_option(
			'active_sitewide_plugins',
			array( ALMOST_FAMOUS_PLUGIN_BASENAME => time() )
		);

		Activator::provision_new_site( new WP_Site( 19 ) );

		global $af_test_blog_switches;
		$this->assertSame( array( 19 ), $af_test_blog_switches );
		$this->assertSame( false, get_option( 'af_setup_complete', 'missing' ) );
		$this->assertSame( 1, get_current_blog_id() );
	}

	/**
	 * WordPress below the minimum supported version triggers the activation
	 * guard which deactivates the plugin and calls wp_die().
	 *
	 * @return void
	 */
	public function test_activate_blocks_on_old_wordpress(): void {
		global $wp_version;
		$wp_version = '6.0';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Bushido Almost Famous requires WordPress/i' );

		try {
			Activator::activate();
		} finally {
			global $af_test_deactivated_plugins;
			$this->assertNotEmpty( $af_test_deactivated_plugins );
			$this->assertSame(
				ALMOST_FAMOUS_PLUGIN_BASENAME,
				$af_test_deactivated_plugins[0]
			);
		}
	}

	/**
	 * The current PHP runtime satisfies the minimum so the PHP guard does not
	 * trip on the happy path.
	 *
	 * @return void
	 */
	public function test_activate_passes_php_version_check_on_current_runtime(): void {
		// Sanity: tests cannot run on PHP < 8.1, so this branch must succeed.
		$this->assertTrue( version_compare( PHP_VERSION, '8.1', '>=' ) );

		Activator::activate();

		$this->assertTrue( get_option( 'af_setup_complete' ) === false );
	}
}
