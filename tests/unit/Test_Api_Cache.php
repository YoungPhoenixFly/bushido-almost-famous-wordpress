<?php
/**
 * Tests for Api_Cache transient caching.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Cache;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Test Api_Cache class.
 */
class Test_Api_Cache extends TestCase {

	/**
	 * Cache instance under test.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $cache;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$this->cache = new Api_Cache();
	}

	/**
	 * Test build_key with data type only.
	 *
	 * @return void
	 */
	public function test_build_key_type_only(): void {
		$key = $this->cache->build_key( 'campaigns' );
		$this->assertSame( 'af_campaigns', $key );
	}

	/**
	 * Test build_key with data type and scope ID.
	 *
	 * @return void
	 */
	public function test_build_key_with_scope(): void {
		$key = $this->cache->build_key( 'campaigns', 'acct_123' );
		$this->assertSame( 'af_campaigns_acct_123', $key );
	}

	/**
	 * Test build_key with empty scope returns type-only key.
	 *
	 * @return void
	 */
	public function test_build_key_empty_scope(): void {
		$key = $this->cache->build_key( 'analytics', '' );
		$this->assertSame( 'af_analytics', $key );
	}

	/**
	 * Test build_key with various data types.
	 *
	 * @return void
	 */
	public function test_build_key_various_types(): void {
		$this->assertSame( 'af_platforms_list', $this->cache->build_key( 'platforms_list' ) );
		$this->assertSame( 'af_campaigns_acct_1', $this->cache->build_key( 'campaigns', 'acct_1' ) );
	}

	/**
	 * Test get_ttl returns correct default for each known data type.
	 *
	 * @dataProvider data_type_ttl_provider
	 *
	 * @param string $data_type The data type.
	 * @param int    $expected  Expected TTL in seconds.
	 * @return void
	 */
	public function test_get_ttl_defaults( string $data_type, int $expected ): void {
		$this->assertSame( $expected, $this->cache->get_ttl( $data_type ) );
	}

	/**
	 * Data provider for default TTL values.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function data_type_ttl_provider(): array {
		return array(
			'campaigns'                  => array( 'campaigns', 300 ),
			'analytics'                  => array( 'analytics', 60 ),
			'analytics_archived'         => array( 'analytics_archived', 300 ),
			'platform_status'            => array( 'platform_status', 3600 ),
			'platform_status_permissions' => array( 'platform_status_permissions', 900 ),
			'platforms_list'             => array( 'platforms_list', 86400 ),
		);
	}

	/**
	 * Test get_ttl returns 300 (default) for an unknown data type.
	 *
	 * @return void
	 */
	public function test_get_ttl_unknown_type_returns_default(): void {
		$this->assertSame( 300, $this->cache->get_ttl( 'unknown_type' ) );
	}

	/**
	 * Test get_ttl is filterable via almost_famous/cache/ttl.
	 *
	 * @return void
	 */
	public function test_get_ttl_filterable(): void {
		add_filter(
			'almost_famous/cache/ttl',
			function ( int $ttl, string $data_type ): int {
				if ( 'campaigns' === $data_type ) {
					return 600;
				}
				return $ttl;
			}
		);

		$this->assertSame( 600, $this->cache->get_ttl( 'campaigns' ) );
		// Other types remain unaffected.
		$this->assertSame( 60, $this->cache->get_ttl( 'analytics' ) );
	}

	/**
	 * Test set and get roundtrip.
	 *
	 * @return void
	 */
	public function test_set_and_get_roundtrip(): void {
		$data = array( 'id' => 'camp_1', 'name' => 'Test Campaign' );

		$this->cache->set( 'af_campaigns_1', $data, 'campaigns' );
		$result = $this->cache->get( 'af_campaigns_1' );

		$this->assertSame( $data, $result );
	}

	/**
	 * Test get returns null on cache miss.
	 *
	 * @return void
	 */
	public function test_get_returns_null_on_miss(): void {
		$this->assertNull( $this->cache->get( 'af_nonexistent' ) );
	}

	/**
	 * Test delete removes a cached entry.
	 *
	 * @return void
	 */
	public function test_delete_removes_entry(): void {
		$this->cache->set( 'af_campaigns_1', array( 'test' => true ), 'campaigns' );
		$this->assertNotNull( $this->cache->get( 'af_campaigns_1' ) );

		$this->cache->delete( 'af_campaigns_1' );
		$this->assertNull( $this->cache->get( 'af_campaigns_1' ) );
	}

	/**
	 * Test delete returns false for nonexistent key.
	 *
	 * @return void
	 */
	public function test_delete_nonexistent_returns_false(): void {
		$this->assertFalse( $this->cache->delete( 'af_nope' ) );
	}

	/**
	 * Test ETag set and get roundtrip.
	 *
	 * @return void
	 */
	public function test_etag_roundtrip(): void {
		$this->cache->set_etag( 'af_campaigns_1', '"abc123"' );
		$this->assertSame( '"abc123"', $this->cache->get_etag( 'af_campaigns_1' ) );
	}

	/**
	 * Test get_etag returns empty string on miss.
	 *
	 * @return void
	 */
	public function test_get_etag_returns_empty_on_miss(): void {
		$this->assertSame( '', $this->cache->get_etag( 'af_nonexistent' ) );
	}

	/**
	 * Test set returns true on success.
	 *
	 * @return void
	 */
	public function test_set_returns_true(): void {
		$result = $this->cache->set( 'af_test', 'value', 'campaigns' );
		$this->assertTrue( $result );
	}

	/**
	 * Test set_etag returns true on success.
	 *
	 * @return void
	 */
	public function test_set_etag_returns_true(): void {
		$result = $this->cache->set_etag( 'af_test', '"etag_value"' );
		$this->assertTrue( $result );
	}
}
