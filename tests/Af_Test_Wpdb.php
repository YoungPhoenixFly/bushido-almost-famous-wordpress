<?php
/**
 * Shared $wpdb fake for unit tests.
 *
 * Honours the small slice of the real WordPress $wpdb API the plugin
 * exercises:
 *   - `$wpdb->options` table name (read-only string)
 *   - `esc_like()` for SQL-LIKE escaping
 *   - `prepare()` with %s/%d/%f/%i placeholders + variadic or array args
 *   - `query()` that recognises `DELETE FROM <table> WHERE option_name
 *     LIKE '<pattern>'` statements and performs the deletion against the
 *     in-memory $af_test_options store (used by Deactivator / Uninstall)
 *   - `get_col()` / `get_results()` stubs that return empty arrays
 *
 * Tests that need a wider surface can subclass + override.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! class_exists( 'Af_Test_Wpdb' ) ) {
	class Af_Test_Wpdb {

		public string $options = 'wp_options';

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, mixed ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			$out = preg_replace_callback(
				'/%[sdif]/',
				function ( $m ) use ( &$i, $args ) {
					$value = $args[ $i ] ?? '';
					++$i;
					return match ( $m[0] ) {
						'%s'    => "'" . str_replace( "'", "''", (string) $value ) . "'",
						'%d',
						'%i'    => (string) (int) $value,
						'%f'    => (string) (float) $value,
						default => $m[0],
					};
				},
				$query
			);
			return $out ?? $query;
		}

		public function query( string $query ): int {
			global $af_test_options;
			if ( ! preg_match( '/^\s*DELETE\s+FROM/i', $query ) ) {
				return 0;
			}
			if ( ! preg_match_all( '/option_name\s+LIKE\s+[\'"]([^\'"]+)[\'"]/i', $query, $matches ) ) {
				return 0;
			}
			$patterns = $matches[1];
			$deleted  = 0;
			foreach ( array_keys( $af_test_options ) as $key ) {
				foreach ( $patterns as $pattern ) {
					if ( $this->sql_like_match( $pattern, $key ) ) {
						unset( $af_test_options[ $key ] );
						++$deleted;
						break;
					}
				}
			}
			return $deleted;
		}

		/**
		 * Test whether a SQL LIKE pattern matches a value.
		 *
		 * Rules:
		 *   \_  → literal underscore (esc_like escape)
		 *   \%  → literal percent (esc_like escape)
		 *   _   → any single character
		 *   %   → any number of characters
		 */
		private function sql_like_match( string $pattern, string $value ): bool {
			$re = '';
			$len = strlen( $pattern );
			for ( $i = 0; $i < $len; $i++ ) {
				$c = $pattern[ $i ];
				if ( '\\' === $c && $i + 1 < $len ) {
					$next = $pattern[ ++$i ];
					if ( '_' === $next || '%' === $next ) {
						$re .= preg_quote( $next, '/' );
					} else {
						$re .= preg_quote( '\\' . $next, '/' );
					}
					continue;
				}
				if ( '_' === $c ) {
					$re .= '.';
				} elseif ( '%' === $c ) {
					$re .= '.*';
				} else {
					$re .= preg_quote( $c, '/' );
				}
			}
			return (bool) preg_match( '/^' . $re . '$/', $value );
		}

		public function get_col( string $query ): array {
			return array();
		}

		public function get_results( string $query, mixed $output = 'ARRAY_A' ): array {
			return array();
		}
	}
}
