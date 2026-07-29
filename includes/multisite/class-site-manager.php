<?php
/**
 * Per-site campaign management in Multisite installations.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Multisite;

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;

/**
 * Per-site campaign management in Multisite installations.
 *
 * Provides site-specific API key resolution with fallback to the
 * network-level key, and per-site campaign data isolation.
 */
class Site_Manager {

	/**
	 * API authentication handler for site-level key.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * Network admin handler.
	 *
	 * @var Network_Admin
	 */
	private Network_Admin $network_admin;

	/**
	 * Constructor.
	 *
	 * @param Api_Auth      $auth          API auth handler (site-level).
	 * @param Network_Admin $network_admin Network admin handler.
	 */
	public function __construct( Api_Auth $auth, Network_Admin $network_admin ) {
		$this->auth          = $auth;
		$this->network_admin = $network_admin;
	}

	/**
	 * Get the effective API key for the current site.
	 *
	 * Returns the site-specific key if available and per-site override
	 * is allowed. Otherwise falls back to the network-level key.
	 *
	 * @return string Decrypted API key or empty string if none configured.
	 */
	public function get_api_key(): string {
		// If not multisite, just use the standard site key.
		if ( ! is_multisite() ) {
			return $this->auth->decrypt_api_key();
		}

		// Check if the site has its own key and override is allowed.
		if ( $this->network_admin->allows_site_override() && $this->auth->has_api_key() ) {
			return $this->auth->decrypt_api_key();
		}

		// Fall back to network key.
		$network_key = $this->network_admin->get_network_api_key();

		if ( ! empty( $network_key ) ) {
			return $network_key;
		}

		// Last resort: try site-level key even if override isn't set,
		// in case network key is missing entirely.
		return $this->auth->decrypt_api_key();
	}

	/**
	 * Check if the current site has an effective API key configured.
	 *
	 * @return bool True if an API key is available (site or network).
	 */
	public function has_api_key(): bool {
		return ! empty( $this->get_api_key() );
	}

	/**
	 * Determine the source of the current API key.
	 *
	 * @return string 'site', 'network', or 'none'.
	 */
	public function get_api_key_source(): string {
		if ( ! is_multisite() ) {
			return $this->auth->has_api_key() ? 'site' : 'none';
		}

		if ( $this->network_admin->allows_site_override() && $this->auth->has_api_key() ) {
			return 'site';
		}

		if ( $this->network_admin->has_network_api_key() ) {
			return 'network';
		}

		if ( $this->auth->has_api_key() ) {
			return 'site';
		}

		return 'none';
	}

	/**
	 * Create an API client configured with the correct key for this site.
	 *
	 * @return Api_Client|null API client or null if no key is available.
	 */
	public function get_api_client(): ?Api_Client {
		$api_key = $this->get_api_key();

		if ( '' === $api_key ) {
			return null;
		}

		if ( 'site' === $this->get_api_key_source() ) {
			return new Api_Client( $this->auth );
		}

		// Network key: hand the client an Api_Auth pinned to the decrypted
		// network key so wp_remote_request() goes out with X-API-Key: <network>.
		return new Api_Client( Api_Auth::with_plaintext_key( $api_key ) );
	}

	/**
	 * Get campaigns for the current site only.
	 *
	 * Passes the site URL as a filter parameter so the API returns
	 * only campaigns associated with this specific site.
	 *
	 * @param Api_Client $client API client to use.
	 * @param array      $params Additional query parameters.
	 * @return array API response data.
	 */
	public function get_site_campaigns( Api_Client $client, array $params = array() ): array {
		$params['site_url'] = get_site_url();
		$params['site_id']  = (string) get_current_blog_id();

		$response = $client->get( '/campaigns', $params );

		if ( isset( $response['error'] ) ) {
			return array();
		}

		return $response['data'] ?? array();
	}

	/**
	 * Get the current site's identifier for API requests.
	 *
	 * @return array{site_id: string, site_url: string, blog_id: int} Site identification data.
	 */
	public function get_site_context(): array {
		return array(
			'site_id'  => (string) get_current_blog_id(),
			'site_url' => get_site_url(),
			'blog_id'  => get_current_blog_id(),
		);
	}

	/**
	 * Check if the current site can manage its own API key.
	 *
	 * @return bool True if the site can set its own key.
	 */
	public function can_manage_site_key(): bool {
		if ( ! is_multisite() ) {
			return true;
		}

		return $this->network_admin->allows_site_override();
	}
}
