<?php
/**
 * PHPUnit bootstrap file for Bushido Almost Famous plugin tests.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-config.php';
require_once dirname( __DIR__ ) . '/includes/class-creative-assets.php';

// Define test constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'ALMOST_FAMOUS_VERSION' ) ) {
	define( 'ALMOST_FAMOUS_VERSION', '1.0.0-test' );
}

if ( ! defined( 'ALMOST_FAMOUS_PLUGIN_DIR' ) ) {
	define( 'ALMOST_FAMOUS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ALMOST_FAMOUS_PLUGIN_URL' ) ) {
	define( 'ALMOST_FAMOUS_PLUGIN_URL', 'https://example.com/wp-content/plugins/bushido-almost-famous/' );
}

if ( ! defined( 'ALMOST_FAMOUS_PLUGIN_BASENAME' ) ) {
	define( 'ALMOST_FAMOUS_PLUGIN_BASENAME', 'bushido-almost-famous/bushido-almost-famous.php' );
}

// WordPress SALT key stubs for encryption tests.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-for-unit-tests-only' );
}

if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'test-auth-salt-for-unit-tests-only' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation stub for unit tests.
	 *
	 * @param string      $text   Source text.
	 * @param string|null $domain Optional text domain.
	 * @return string
	 */
	function __( string $text, ?string $domain = null ): string {
		unset( $domain );
		return $text;
	}
}

// Shared WordPress function and REST stubs.
require_once __DIR__ . '/Rest_Request_Stub.php';
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/Af_Test_Wpdb.php';
