<?php
/**
 * Runtime configuration helpers for Bushido Almost Famous.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous;

use WP_REST_Request;

/**
 * Resolves environment-driven configuration and portal request guards.
 */
class Config {

	/**
	 * Production endpoint pair used by WordPress.org builds.
	 *
	 * @var array{api: string, app: string}
	 */
	private const PRODUCTION_ENDPOINTS = array(
		'api' => 'https://api.almost-famous.backend-bushidoco.de/api/v1',
		'app' => 'https://bushido.is',
	);

	/**
	 * Staging pair used only by explicitly-built, non-WordPress.org artifacts.
	 *
	 * @var array{api: string, app: string}
	 */
	private const STAGING_ENDPOINTS = array(
		'api' => 'https://api.almost-famous-staging.backend-bushidoco.de/api/v1',
		'app' => 'https://staging.bushido.is',
	);

	/**
	 * Option holding the page ID ads click through to (0 = site home page).
	 *
	 * @var string
	 */
	public const DESTINATION_PAGE_OPTION = 'af_default_destination_page_id';

	/**
	 * Resolve the default click-through destination for campaign ads.
	 *
	 * Ads run from this site should land back on this site: only clicks that
	 * arrive on WordPress can be picked up by the plugin's own attribution
	 * cookie and reported as WooCommerce conversions. A destination on another
	 * host is invisible to that loop, so the default is a page here — the one
	 * chosen under Settings, or the site home page.
	 *
	 * Campaign authors can still override the destination per campaign (e.g. a
	 * Spotify link, which Spotify engagement campaigns require).
	 *
	 * @return string Absolute URL, always on this site unless filtered.
	 */
	public static function get_default_destination_url(): string {
		$page_id = (int) get_option( self::DESTINATION_PAGE_OPTION, 0 );
		$url     = '';

		// Only a published page is a valid ad destination — a draft or a
		// deleted page would send paid traffic to a 404.
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$permalink = get_permalink( $page_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$url = $permalink;
			}
		}

		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		/**
		 * Filter the default click-through destination for campaign ads.
		 *
		 * @param string $url     Resolved destination URL.
		 * @param int    $page_id Configured page ID (0 when unset).
		 */
		return (string) apply_filters( 'almost_famous/default_destination_url', $url, $page_id );
	}

	/**
	 * Resolve the API and consent-app endpoints as one indivisible pair.
	 *
	 * Production is the source/WP.org default. Staging is selected only by a
	 * staging artifact, which defines BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL.
	 * Custom/local endpoints are accepted only when both values are supplied
	 * through the same configuration layer. A partial or unknown profile fails
	 * closed so the app cannot mint a code against one environment and send it
	 * to another environment's exchange endpoint.
	 *
	 * @return array{api: string, app: string}
	 * @throws \UnexpectedValueException When a service pair is partial, invalid, or unknown.
	 */
	public static function resolve_service_endpoints(): array {
		$constant_api = defined( 'AF_API_BASE_URL' ) && is_string( AF_API_BASE_URL )
			? trim( AF_API_BASE_URL )
			: '';
		$constant_app = defined( 'AF_BUSHIDO_APP_URL' ) && is_string( AF_BUSHIDO_APP_URL )
			? trim( AF_BUSHIDO_APP_URL )
			: '';
		if ( '' !== $constant_api || '' !== $constant_app ) {
			return self::require_pair( $constant_api, $constant_app, 'wp-config.php constants' );
		}

		$environment_api = self::get_env_value(
			array(
				'AF_API_BASE_URL',
				'ALMOST_FAMOUS_API_BASE_URL',
				'WP_ALMOST_FAMOUS_API_BASE_URL',
			)
		);
		$environment_app = self::get_env_value(
			array(
				'AF_BUSHIDO_APP_URL',
				'ALMOST_FAMOUS_BUSHIDO_APP_URL',
				'WP_ALMOST_FAMOUS_BUSHIDO_APP_URL',
			)
		);
		if ( '' !== $environment_api || '' !== $environment_app ) {
			return self::require_pair( $environment_api, $environment_app, 'environment variables' );
		}

		$option_api = get_option( 'af_api_base_url', '' );
		$option_app = get_option( 'af_bushido_app_url', '' );
		$option_api = is_string( $option_api ) ? trim( $option_api ) : '';
		$option_app = is_string( $option_app ) ? trim( $option_app ) : '';
		if ( '' !== $option_api || '' !== $option_app ) {
			return self::require_pair( $option_api, $option_app, 'WordPress options' );
		}

		$channel = defined( 'BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL' )
			&& is_string( BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL )
			? strtolower( trim( BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL ) )
			: 'production';

		return match ( $channel ) {
			'production' => self::PRODUCTION_ENDPOINTS,
			'staging'    => self::STAGING_ENDPOINTS,
			default      => throw new \UnexpectedValueException(
				sprintf(
					/* translators: %s: invalid release channel. */
					esc_html__( 'Unknown Bushido Almost Famous release channel: %s', 'bushido-almost-famous' ),
					esc_html( $channel )
				)
			),
		};
	}

	/**
	 * Resolve the backend API base URL.
	 *
	 * @return string
	 */
	public static function resolve_api_base_url(): string {
		return self::resolve_service_endpoints()['api'];
	}

	/**
	 * Resolve the Bushido web-app base URL used by onboarding links.
	 *
	 * @return string
	 */
	public static function resolve_bushido_app_url(): string {
		return self::resolve_service_endpoints()['app'];
	}

	/**
	 * Whether local demo mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_demo_mode_enabled(): bool {
		$raw = self::get_env_value(
			array(
				'AF_PUBLIC_PORTAL_DEMO_MODE',
				'ALMOST_FAMOUS_PUBLIC_PORTAL_DEMO_MODE',
			)
		);

		if ( '' === $raw ) {
			$option = get_option( 'af_public_portal_demo_mode', false );
			return rest_sanitize_boolean( $option );
		}

		return rest_sanitize_boolean( $raw );
	}

	/**
	 * Normalize a URL by trimming trailing slashes.
	 *
	 * @param string $url URL string.
	 * @return string
	 */
	private static function normalize_url( string $url ): string {
		$normalized = untrailingslashit( trim( $url ) );
		$path       = wp_parse_url( $normalized, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			return $normalized . '/api/v1';
		}

		return $normalized;
	}

	/**
	 * Validate and normalize a custom API/app pair.
	 *
	 * @param string $api    API endpoint.
	 * @param string $app    Consent-app endpoint.
	 * @param string $source Configuration source label.
	 * @return array{api: string, app: string}
	 * @throws \UnexpectedValueException When the pair is partial or invalid.
	 */
	private static function require_pair( string $api, string $app, string $source ): array {
		if ( '' === $api || '' === $app ) {
			throw new \UnexpectedValueException(
				sprintf(
					/* translators: %s: configuration source. */
					esc_html__( 'Bushido Almost Famous requires both API and app URLs from %s.', 'bushido-almost-famous' ),
					esc_html( $source )
				)
			);
		}

		$normalized_api = self::normalize_url( $api );
		$normalized_app = untrailingslashit( trim( $app ) );
		if (
			! self::is_valid_endpoint( $normalized_api )
			|| ! self::is_valid_endpoint( $normalized_app )
		) {
			throw new \UnexpectedValueException(
				sprintf(
					/* translators: %s: configuration source. */
					esc_html__( 'Bushido Almost Famous received an invalid endpoint pair from %s.', 'bushido-almost-famous' ),
					esc_html( $source )
				)
			);
		}

		return array(
			'api' => $normalized_api,
			'app' => $normalized_app,
		);
	}

	/**
	 * Accept HTTPS service URLs and HTTP only for local development.
	 *
	 * @param string $url Endpoint URL.
	 * @return bool
	 */
	private static function is_valid_endpoint( string $url ): bool {
		$scheme   = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$username = wp_parse_url( $url, PHP_URL_USER );
		$password = wp_parse_url( $url, PHP_URL_PASS );
		$query    = wp_parse_url( $url, PHP_URL_QUERY );
		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
		if (
			'' === $host
			|| ! in_array( $scheme, array( 'http', 'https' ), true )
			|| null !== $username
			|| null !== $password
			|| null !== $query
			|| null !== $fragment
		) {
			return false;
		}
		if ( 'https' === $scheme ) {
			return true;
		}
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
	}

	/**
	 * Look up the first non-empty environment value from a list of keys.
	 *
	 * @param array<int, string> $keys Candidate environment keys.
	 * @return string
	 */
	private static function get_env_value( array $keys ): string {
		foreach ( $keys as $key ) {
			$value = getenv( $key );
			if ( is_string( $value ) && '' !== $value ) {
				return trim( $value );
			}

			if ( isset( $_ENV[ $key ] ) && is_string( $_ENV[ $key ] ) && '' !== $_ENV[ $key ] ) {
				return trim( sanitize_text_field( $_ENV[ $key ] ) );
			}

			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			}
		}

		return '';
	}
}
