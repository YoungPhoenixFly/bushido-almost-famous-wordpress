<?php
/**
 * Transient cache with ETag support and write-through invalidation.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

/**
 * Manages WordPress transient caching for Bushido API responses.
 *
 * Supports ETag conditional requests, configurable TTLs per data type,
 * and write-through invalidation on mutations.
 */
class Api_Cache {

	/**
	 * Default TTLs in seconds, keyed by data type.
	 *
	 * @var array<string, int>
	 */
	private const DEFAULT_TTLS = array(
		'campaigns'                   => 300,   // 5 minutes.
		'analytics'                   => 60,    // 60 seconds for active.
		'analytics_archived'          => 300,   // 5 minutes for archived.
		'platform_status'             => 3600,  // 1 hour general.
		'platform_status_permissions' => 900,  // 15 minutes permissions.
		'platforms_list'              => 86400, // 24 hours.
	);

	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'af_';

	/**
	 * ETag key suffix.
	 *
	 * @var string
	 */
	private const ETAG_SUFFIX = '_etag';

	/**
	 * Retrieve cached data for a given cache key.
	 *
	 * @param string $cache_key The transient cache key.
	 * @return mixed Cached data or null on cache miss.
	 */
	public function get( string $cache_key ): mixed {
		$value = get_transient( $cache_key );

		if ( false === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Store data in the transient cache with a TTL based on data type.
	 *
	 * @param string $cache_key The transient cache key.
	 * @param mixed  $data      The data to cache.
	 * @param string $data_type The data type for TTL lookup.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $cache_key, mixed $data, string $data_type = 'campaigns' ): bool {
		$ttl = $this->get_ttl( $data_type );

		return set_transient( $cache_key, $data, $ttl );
	}

	/**
	 * Delete a specific transient cache entry.
	 *
	 * @param string $cache_key The transient cache key to delete.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $cache_key ): bool {
		return delete_transient( $cache_key );
	}

	/**
	 * Delete all transients matching a given prefix.
	 *
	 * Uses a direct database query to find and remove all transients
	 * whose option_name matches _transient_af_{prefix}*.
	 *
	 * @param string $prefix The prefix to match (without the af_ part).
	 * @return void
	 */
	public function delete_by_prefix( string $prefix ): void {
		global $wpdb;

		$like_pattern = $wpdb->esc_like( '_transient_' . self::PREFIX . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_pattern
			)
		);

		foreach ( $transient_keys as $option_name ) {
			// Strip the _transient_ prefix to get the transient name.
			$transient_name = str_replace( '_transient_', '', $option_name );
			delete_transient( $transient_name );
		}
	}

	/**
	 * Retrieve the stored ETag for a cache key.
	 *
	 * @param string $cache_key The cache key to look up the ETag for.
	 * @return string The stored ETag, or empty string if none exists.
	 */
	public function get_etag( string $cache_key ): string {
		$etag = get_transient( $cache_key . self::ETAG_SUFFIX );

		if ( false === $etag ) {
			return '';
		}

		return (string) $etag;
	}

	/**
	 * Store an ETag for a cache key.
	 *
	 * ETags are stored with the same TTL as the parent cache entry.
	 * Uses a generous TTL since ETags should outlive their cached data.
	 *
	 * @param string $cache_key The cache key to associate the ETag with.
	 * @param string $etag      The ETag value from the API response header.
	 * @return bool True on success, false on failure.
	 */
	public function set_etag( string $cache_key, string $etag ): bool {
		// Store ETags for 24 hours — they outlive the cached data.
		return set_transient( $cache_key . self::ETAG_SUFFIX, $etag, DAY_IN_SECONDS );
	}

	/**
	 * Delete the stored ETag for a cache key.
	 *
	 * Used when a conditional request 304s but the data cache has already
	 * expired, so the stale ETag must be discarded to force a fresh fetch.
	 *
	 * @param string $cache_key The cache key.
	 * @return bool
	 */
	public function delete_etag( string $cache_key ): bool {
		return delete_transient( $cache_key . self::ETAG_SUFFIX );
	}

	/**
	 * Get the TTL for a given data type.
	 *
	 * Returns the configured default TTL, filterable via the
	 * 'almost_famous/cache/ttl' hook.
	 *
	 * @param string $data_type The data type key (e.g., 'campaigns', 'analytics').
	 * @return int TTL in seconds.
	 */
	public function get_ttl( string $data_type ): int {
		$ttl = self::DEFAULT_TTLS[ $data_type ] ?? 300;

		/**
		 * Filter the cache TTL for a specific data type.
		 *
		 * @param int    $ttl       The TTL in seconds.
		 * @param string $data_type The data type key.
		 */
		return (int) apply_filters( 'almost_famous/cache/ttl', $ttl, $data_type );
	}

	/**
	 * Build a cache key from a data type and optional scope ID.
	 *
	 * @param string $data_type The data type (e.g., 'campaigns', 'analytics').
	 * @param string $scope_id  Optional scope identifier (e.g., account ID, campaign ID).
	 * @return string The full cache key in the format af_{type}_{scope}.
	 */
	public function build_key( string $data_type, string $scope_id = '' ): string {
		$key = self::PREFIX . $data_type;

		if ( '' !== $scope_id ) {
			$key .= '_' . $scope_id;
		}

		return $key;
	}
}
