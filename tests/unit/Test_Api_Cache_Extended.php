<?php
/**
 * Extends Test_Api_Cache.php with coverage for:
 *   - delete_by_prefix() — the $wpdb LIKE-driven invalidation path.
 *   - The almost_famous/cache/ttl filter receives the data_type argument
 *     so callers can override per-type.
 *
 * NOTE on multisite: Api_Cache currently does NOT prefix keys with the
 * blog ID, so tests for cross-site key isolation would fail. Documented
 * below in the skipped test.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Cache;
use PHPUnit\Framework\TestCase;

/**
 * Minimal $wpdb fake that records LIKE patterns and returns an arbitrary list
 * of matching transient option_names so delete_by_prefix() can exercise its
 * loop body.
 */
class Test_Api_Cache_Extended_FakeWpdb {

	public string $options = 'wp_options';

	/**
	 * Pattern → option_names that LIKE-match.
	 *
	 * @var array<string,array<int,string>>
	 */
	public array $rows = array();

	/**
	 * @var array<int,string>
	 */
	public array $captured_patterns = array();

	public function esc_like( string $value ): string {
		return $value;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		if ( isset( $args[0] ) && is_string( $args[0] ) ) {
			$this->captured_patterns[] = $args[0];
			$this->last_pattern        = $args[0];
		}
		return $sql;
	}

	public string $last_pattern = '';

	/**
	 * @return array<int,string>
	 */
	public function get_col( mixed $query ): array {
		unset( $query );
		// Trim trailing % from the LIKE pattern to find the prefix.
		$prefix = rtrim( $this->last_pattern, '%' );
		foreach ( $this->rows as $registered_prefix => $names ) {
			if ( str_starts_with( $prefix, $registered_prefix ) || str_starts_with( $registered_prefix, $prefix ) ) {
				return $names;
			}
		}
		return array();
	}
}

class Test_Api_Cache_Extended extends TestCase {

	private Api_Cache $cache;
	private Test_Api_Cache_Extended_FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$this->cache = new Api_Cache();
		$this->wpdb  = new Test_Api_Cache_Extended_FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// delete_by_prefix — happy path
	// -----------------------------------------------------------------------

	public function test_delete_by_prefix_clears_matching_transients_and_runs_like_query(): void {
		// Two transients exist; one under campaigns_acct_1, one under audiences.
		set_transient( 'af_campaigns_acct_1_filterA', array( 'id' => 1 ) );
		set_transient( 'af_campaigns_acct_1_filterB', array( 'id' => 2 ) );
		set_transient( 'af_audiences_acct_1', array( 'id' => 99 ) );

		// Tell the fake which option_names match the campaigns_acct_1 LIKE.
		$this->wpdb->rows['_transient_af_campaigns_acct_1'] = array(
			'_transient_af_campaigns_acct_1_filterA',
			'_transient_af_campaigns_acct_1_filterB',
		);

		$this->cache->delete_by_prefix( 'campaigns_acct_1' );

		// LIKE pattern captured.
		$this->assertNotEmpty( $this->wpdb->captured_patterns );
		$this->assertStringContainsString( '_transient_af_campaigns_acct_1', $this->wpdb->captured_patterns[0] );
		$this->assertStringEndsWith( '%', $this->wpdb->captured_patterns[0] );

		// Both campaign transients gone; unrelated audience transient survives.
		$this->assertFalse( get_transient( 'af_campaigns_acct_1_filterA' ) );
		$this->assertFalse( get_transient( 'af_campaigns_acct_1_filterB' ) );
		$this->assertNotFalse( get_transient( 'af_audiences_acct_1' ) );
	}

	public function test_delete_by_prefix_handles_empty_match_set(): void {
		$this->cache->delete_by_prefix( 'no_matches_here' );

		// Just confirms it does not throw and the LIKE query was attempted.
		$this->assertNotEmpty( $this->wpdb->captured_patterns );
		$this->assertStringContainsString( '_transient_af_no_matches_here', $this->wpdb->captured_patterns[0] );
	}


	public function test_ttl_filter_receives_data_type_argument(): void {
		$seen_types = array();
		add_filter(
			'almost_famous/cache/ttl',
			static function ( int $ttl, string $data_type ) use ( &$seen_types ): int {
				$seen_types[] = $data_type;
				return 'analytics' === $data_type ? 1 : $ttl;
			},
			10,
			2
		);

		$this->assertSame( 1, $this->cache->get_ttl( 'analytics' ) );
		$this->assertSame( 300, $this->cache->get_ttl( 'campaigns' ) );

		$this->assertSame( array( 'analytics', 'campaigns' ), $seen_types );
	}

	public function test_set_uses_filtered_ttl_for_data_type(): void {
		add_filter(
			'almost_famous/cache/ttl',
			static function ( int $ttl, string $data_type ): int {
				if ( 'campaigns' === $data_type ) {
					return 7200;
				}
				return $ttl;
			},
			10,
			2
		);

		// We don't have a way to inspect TTL on a stubbed transient, but the
		// filter call path must succeed and the data must still be readable.
		$this->assertTrue( $this->cache->set( 'af_campaigns_filtered', array( 'ok' => true ), 'campaigns' ) );
		$this->assertSame( array( 'ok' => true ), $this->cache->get( 'af_campaigns_filtered' ) );
	}

	// -----------------------------------------------------------------------
	// Multisite scoping is intentionally NOT covered yet
	// -----------------------------------------------------------------------

	public function test_multisite_build_key_is_currently_blog_id_invariant(): void {
		// Document the current behavior: build_key does not vary by blog ID,
		// so the same cache key is produced regardless of multisite context.
		// If/when Api_Cache adds blog-id scoping, this test can be flipped
		// into a proper isolation assertion.
		af_test_set_multisite( true );

		$key = $this->cache->build_key( 'campaigns', 'scope' );
		$this->assertSame( 'af_campaigns_scope', $key );

		af_test_set_multisite( false );
		$this->assertSame( $key, $this->cache->build_key( 'campaigns', 'scope' ) );
	}
}
