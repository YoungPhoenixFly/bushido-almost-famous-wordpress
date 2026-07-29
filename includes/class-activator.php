<?php
/**
 * Plugin activation handler.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous;

/**
 * Handles plugin activation — version checks, role registration, default options.
 */
class Activator {

	/**
	 * Minimum PHP version required.
	 *
	 * @var string
	 */
	private const MIN_PHP_VERSION = '8.1';

	/**
	 * Minimum WordPress version required.
	 *
	 * @var string
	 */
	private const MIN_WP_VERSION = '6.4';

	/**
	 * Run activation checks and setup.
	 *
	 * @param bool $network_wide Whether WordPress is activating network-wide.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		self::check_php_version();
		self::check_wp_version();

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::provision_current_site( false );
				restore_current_blog();
			}
		} else {
			self::provision_current_site( true );
		}

		/**
		 * Fires after the Bushido Almost Famous plugin is activated.
		 */
		do_action( 'almost_famous/plugin/activated' );
	}

	/**
	 * Provision a site created after network activation.
	 *
	 * @param \WP_Site|int $site New site object or blog id.
	 * @return void
	 */
	public static function provision_new_site( \WP_Site|int $site ): void {
		if ( ! is_multisite() || ! self::is_network_active() ) {
			return;
		}

		$site_id = $site instanceof \WP_Site ? (int) $site->blog_id : $site;
		if ( $site_id <= 0 ) {
			return;
		}

		switch_to_blog( $site_id );
		self::provision_current_site( false );
		restore_current_blog();
	}

	/**
	 * Determine whether the plugin is active network-wide.
	 *
	 * @return bool
	 */
	private static function is_network_active(): bool {
		if ( function_exists( 'is_plugin_active_for_network' ) ) {
			return is_plugin_active_for_network( ALMOST_FAMOUS_PLUGIN_BASENAME );
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ ALMOST_FAMOUS_PLUGIN_BASENAME ] );
	}

	/**
	 * Register roles and defaults for the current blog.
	 *
	 * @param bool $arm_redirect Whether to arm the setup redirect.
	 * @return void
	 */
	private static function provision_current_site( bool $arm_redirect ): void {
		self::register_roles();
		self::set_default_options();

		// Signal the setup wizard to redirect on the next admin page load, so a
		// fresh install lands on the connection screen instead of an empty,
		// unconfigured dashboard. Consumed once by
		// Setup_Wizard::maybe_redirect_to_wizard(); skipped for already-configured
		// sites (re-activation) since that handler bails when setup is complete.
		if ( $arm_redirect && ! get_option( 'af_setup_complete', false ) ) {
			set_transient( 'af_activation_redirect', true, 30 );
		}
	}

	/**
	 * Verify PHP version meets minimum requirement.
	 *
	 * @return void
	 */
	private static function check_php_version(): void {
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			deactivate_plugins( ALMOST_FAMOUS_PLUGIN_BASENAME );
			wp_die(
				sprintf(
					/* translators: %s: Minimum PHP version required. */
					esc_html__( 'Bushido Almost Famous requires PHP %s or higher.', 'bushido-almost-famous' ),
					esc_html( self::MIN_PHP_VERSION )
				),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Verify WordPress version meets minimum requirement.
	 *
	 * @return void
	 */
	private static function check_wp_version(): void {
		global $wp_version;

		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			deactivate_plugins( ALMOST_FAMOUS_PLUGIN_BASENAME );
			wp_die(
				sprintf(
					/* translators: %s: Minimum WordPress version required. */
					esc_html__( 'Bushido Almost Famous requires WordPress %s or higher.', 'bushido-almost-famous' ),
					esc_html( self::MIN_WP_VERSION )
				),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Register the Bushido Admin custom role.
	 *
	 * Delegates to the canonical Roles class to avoid duplication.
	 *
	 * @return void
	 */
	private static function register_roles(): void {
		\AlmostFamous\Roles\Roles::register_roles();
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		add_option( 'af_setup_complete', false );
		add_option(
			'af_settings',
			array(
				'cache_ttl_active'         => 60,
				'cache_ttl_archived'       => 300,
				'budget_safety_multiplier' => 10,
			)
		);
	}
}
