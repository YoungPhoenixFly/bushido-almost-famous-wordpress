<?php
/**
 * Sub-process runner that executes the plugin's `uninstall.php` against an
 * in-memory state and serialises the post-run state to STDOUT as JSON.
 *
 * Inputs are read from environment variables so the parent process does not
 * have to coordinate via shared globals:
 *   AF_UNINSTALL_DEFINE  - "1" defines WP_UNINSTALL_PLUGIN before the include.
 *   AF_UNINSTALL_STATE   - JSON map of `option_name => value` to seed the
 *                          in-memory options store, plus a `_roles` array of
 *                          role slugs.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

af_test_reset();

global $af_test_options, $af_test_roles;
$payload = getenv( 'AF_UNINSTALL_STATE' );
if ( is_string( $payload ) && '' !== $payload ) {
	$decoded = json_decode( $payload, true );
	if ( is_array( $decoded ) ) {
		if ( isset( $decoded['options'] ) && is_array( $decoded['options'] ) ) {
			$af_test_options = $decoded['options'];
		}
		if ( isset( $decoded['roles'] ) && is_array( $decoded['roles'] ) ) {
			foreach ( $decoded['roles'] as $slug ) {
				$af_test_roles[ (string) $slug ] = new AF_Test_WP_Role( (string) $slug, array() );
			}
		}
	}
}

// Inline fake $wpdb mirroring the helper used by Test_Deactivator.
$wpdb_class = new class() {
	public string $options = 'wp_options';

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $value ) {
			$pos = strpos( $sql, '%s' );
			if ( false === $pos ) {
				break;
			}
			$sql = substr( $sql, 0, $pos ) . "'" . (string) $value . "'" . substr( $sql, $pos + 2 );
		}
		return $sql;
	}

	public function query( string $sql ): int {
		global $af_test_options;
		$deleted = 0;
		if ( 0 !== preg_match_all( "/'([^']*)'/", $sql, $matches ) ) {
			foreach ( $af_test_options as $key => $_value ) {
				foreach ( $matches[1] as $pattern ) {
					$prefix = rtrim( $pattern, '%' );
					$prefix = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), $prefix );
					if ( '' !== $prefix && $prefix !== $pattern && str_starts_with( (string) $key, $prefix ) ) {
						unset( $af_test_options[ $key ] );
						++$deleted;
						break;
					}
				}
			}
		}
		return $deleted;
	}
};

global $wpdb;
$wpdb = $wpdb_class;

if ( '1' === getenv( 'AF_UNINSTALL_DEFINE' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'bushido-almost-famous/bushido-almost-famous.php' );
}

// Register a shutdown hook to emit the final state even if uninstall.php
// calls exit() (which it does when WP_UNINSTALL_PLUGIN is undefined).
register_shutdown_function(
	static function (): void {
		global $af_test_options, $af_test_roles;
		echo "\n__AF_UNINSTALL_RESULT__:" . json_encode(
			array(
				'options' => $af_test_options,
				'roles'   => array_keys( $af_test_roles ),
			)
		) . "\n";
	}
);

require dirname( __DIR__, 2 ) . '/uninstall.php';
