<?php
/**
 * Tests for the plugin Deactivator: transient purge, role teardown, option
 * preservation, and lifecycle action emission.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Deactivator;
use PHPUnit\Framework\TestCase;

/**
 * Fake $wpdb that implements the small subset of the API the Deactivator and
 * uninstall.php touch: `prepare()`, `esc_like()`, and `query()` with a `DELETE
 * FROM ... WHERE option_name LIKE ...` statement against the in-memory
 * options store.
 */
if ( ! class_exists( 'Af_Deactivator_Wpdb' ) ) {
class Af_Deactivator_Wpdb {

	/**
	 * Table name used by callers.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * Escape a value for use inside a LIKE expression.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Bind LIKE patterns into the query, returning a "rendered" SQL string the
	 * fake query() method can later inspect.
	 *
	 * @param string $sql  SQL template with %s placeholders.
	 * @param mixed  ...$args Values for placeholders.
	 * @return string
	 */
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

	/**
	 * Execute the deletion-by-LIKE statement against the in-memory options
	 * stub. Each LIKE pattern that contains a % wildcard becomes a prefix
	 * match anchored at the start of the option name.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Number of rows deleted (best-effort).
	 */
	public function query( string $sql ): int {
		global $af_test_options;
		$deleted = 0;

		if ( 0 !== preg_match_all( "/'([^']*)'/", $sql, $matches ) ) {
			$patterns = $matches[1];
			foreach ( $af_test_options as $key => $_value ) {
				foreach ( $patterns as $pattern ) {
					// Translate the WP-style LIKE prefix into a string match,
					// undoing the esc_like backslash escapes.
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
}
}

/**
 * Test Deactivator class.
 */
class Test_Deactivator extends TestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		global $wpdb;
		$wpdb = new Af_Deactivator_Wpdb();
	}

	/**
	 * Deactivation purges every `_transient_af_*` row from the options store
	 * but leaves unrelated options untouched.
	 *
	 * @return void
	 */
	public function test_deactivate_purges_af_transients(): void {
		global $af_test_options;
		$af_test_options['_transient_af_campaigns']            = array( 'id' => 1 );
		$af_test_options['_transient_timeout_af_campaigns']    = time() + 60;
		$af_test_options['_transient_af_analytics_acct_1']     = array( 'foo' => 'bar' );
		$af_test_options['_transient_other_plugin']            = 'keep';
		$af_test_options['af_api_key']                         = 'should-remain';

		Deactivator::deactivate();

		$this->assertArrayNotHasKey( '_transient_af_campaigns', $af_test_options );
		$this->assertArrayNotHasKey( '_transient_timeout_af_campaigns', $af_test_options );
		$this->assertArrayNotHasKey( '_transient_af_analytics_acct_1', $af_test_options );
		$this->assertArrayHasKey( '_transient_other_plugin', $af_test_options );
	}

	/**
	 * Plugin options outside the transient prefix are preserved.
	 *
	 * @return void
	 */
	public function test_deactivate_preserves_plugin_options(): void {
		update_option( 'af_api_key', 'secret-token' );
		update_option( 'af_settings', array( 'cache_ttl_active' => 60 ) );
		update_option( 'af_setup_complete', true );

		Deactivator::deactivate();

		$this->assertSame( 'secret-token', get_option( 'af_api_key' ) );
		$this->assertSame( array( 'cache_ttl_active' => 60 ), get_option( 'af_settings' ) );
		$this->assertSame( true, get_option( 'af_setup_complete' ) );
	}

	/**
	 * Deactivation preserves the role mapping; only uninstall may delete
	 * durable plugin configuration.
	 *
	 * @return void
	 */
	public function test_deactivate_preserves_role_mapping(): void {
		update_option( 'af_role_mapping', array( 'administrator' => 'bushido_admin' ) );

		Deactivator::deactivate();

		$this->assertSame(
			array( 'administrator' => 'bushido_admin' ),
			get_option( 'af_role_mapping', false )
		);
	}

	/**
	 * The lifecycle action is fired once per deactivation.
	 *
	 * @return void
	 */
	public function test_deactivate_fires_lifecycle_action(): void {
		Deactivator::deactivate();

		$this->assertSame( 1, did_action( 'almost_famous/plugin/deactivated' ) );
	}

	/**
	 * Running deactivate against a clean slate is a no-op rather than an
	 * error.
	 *
	 * @return void
	 */
	public function test_deactivate_is_safe_with_no_state(): void {
		Deactivator::deactivate();

		global $af_test_options;
		$this->assertSame( array(), $af_test_options );
	}
}
