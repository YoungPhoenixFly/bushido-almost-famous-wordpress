<?php
/**
 * Plugin Name: Bushido Almost Famous
 * Plugin URI: https://bushido.is/almost-famous
 * Description: Cross-platform advertising orchestration engine for music — powered by Bushido.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Bushido
 * Author URI: https://bushido.is
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bushido-almost-famous
 * Domain Path: /languages
 * Network: true
 *
 * @package AlmostFamous
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALMOST_FAMOUS_VERSION', '1.0.0' );
define( 'ALMOST_FAMOUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALMOST_FAMOUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALMOST_FAMOUS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ALMOST_FAMOUS_PLUGIN_DIR . 'vendor/autoload.php';
require_once ALMOST_FAMOUS_PLUGIN_DIR . 'includes/class-config.php';
require_once ALMOST_FAMOUS_PLUGIN_DIR . 'includes/class-creative-assets.php';

$almost_famous_endpoints = \AlmostFamous\Config::resolve_service_endpoints();
if ( ! defined( 'AF_API_BASE_URL' ) ) {
	// Backward-compatible public constant documented for wp-config.php.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	define( 'AF_API_BASE_URL', $almost_famous_endpoints['api'] );
}
if ( ! defined( 'AF_BUSHIDO_APP_URL' ) ) {
	// Backward-compatible public constant documented for wp-config.php.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	define( 'AF_BUSHIDO_APP_URL', $almost_famous_endpoints['app'] );
}
unset( $almost_famous_endpoints );

register_activation_hook( __FILE__, array( \AlmostFamous\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AlmostFamous\Deactivator::class, 'deactivate' ) );
add_action( 'wp_initialize_site', array( \AlmostFamous\Activator::class, 'provision_new_site' ), 100 );

/**
 * Add a quick link on the Plugins screen — "Connect" until the site is set up,
 * "Settings" afterwards — so a fresh install always has a signposted next step.
 */
add_filter(
	'plugin_action_links_' . ALMOST_FAMOUS_PLUGIN_BASENAME,
	static function ( array $links ): array {
		if ( get_option( 'af_setup_complete', false ) ) {
			$link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=af-settings' ) ),
				esc_html__( 'Settings', 'bushido-almost-famous' )
			);
		} else {
			$link = sprintf(
				'<a href="%s"><strong>%s</strong></a>',
				esc_url( admin_url( 'admin.php?page=af-setup-wizard' ) ),
				esc_html__( 'Connect', 'bushido-almost-famous' )
			);
		}

		array_unshift( $links, $link );

		return $links;
	}
);

add_action( 'plugins_loaded', array( \AlmostFamous\Plugin::class, 'get_instance' ) );
