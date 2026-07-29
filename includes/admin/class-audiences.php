<?php
/**
 * Audiences admin page.
 *
 * Renders the saved-audience list plus a per-platform audience creation
 * form that matches the backend contract exactly ({name, type, platform,
 * credentialId, config?}), and wires the "create lookalike" action to the
 * real /audiences/{id}/lookalike proxy route. Detailed targeting
 * (countries, age ranges, interests) is configured per campaign in the
 * campaign console, where the backend actually applies it.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Enums\Platform_Type;

/**
 * Audiences admin page.
 */
class Audiences {

	/**
	 * API client instance.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * API cache instance.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $cache;

	/**
	 * Memoized platform → credential-id map for the current request.
	 *
	 * @var array<string, string|null>|null
	 */
	private ?array $credential_map = null;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client API client.
	 * @param Api_Cache  $cache  API cache.
	 */
	public function __construct( Api_Client $client, Api_Cache $cache ) {
		$this->client = $client;
		$this->cache  = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_page_data' ), 20 );
	}

	/**
	 * Register the audiences submenu page.
	 *
	 * @return void
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'bushido-almost-famous',
			__( 'Audiences', 'bushido-almost-famous' ),
			__( 'Audiences', 'bushido-almost-famous' ),
			'af_view_campaigns',
			'af-audiences',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Localize the platform → credential map for the audience form JS.
	 *
	 * A null value identifies an agency platform whose credential is resolved
	 * server-side without exposing the shared credential id.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_page_data( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'af-audiences' ) ) {
			return;
		}

		wp_localize_script(
			'af-admin',
			'afAudienceData',
			array(
				'credentials' => $this->resolve_platform_credentials(),
			)
		);
	}

	/**
	 * Render the audiences page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$fetch_failed     = false;
		$audiences        = $this->fetch_audiences( $fetch_failed );
		$credentials      = $this->resolve_platform_credentials();
		$platform_choices = $this->get_platform_choices( $credentials );
		$type_choices     = self::get_type_choices();

		include ALMOST_FAMOUS_PLUGIN_DIR . 'includes/admin/views/audiences.php';
	}

	/**
	 * Fetch all saved audiences.
	 *
	 * @param bool|null $failed Out-param set to true when the API fetch errored
	 *                          (as distinct from a genuinely empty account).
	 * @return array List of audience arrays.
	 */
	public function fetch_audiences( ?bool &$failed = null ): array {
		$failed    = false;
		$cache_key = $this->cache->build_key( 'audiences', 'list' );
		$cached    = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->client->get( '/audiences' );

		if ( isset( $response['error'] ) ) {
			$failed = true;
			return array();
		}

		$audiences = $response['data'] ?? array();
		$this->cache->set( $cache_key, $audiences, 'campaigns' );

		return $audiences;
	}

	/**
	 * Resolve a platform → credentialId map for audience creation.
	 *
	 * Mirrors the proxy's reach-estimation resolver: the org's own active
	 * connections win, and Bushido system credentials fill the remaining
	 * platforms (agency mode). Keys are the backend's lowercase platform
	 * wire values (meta, google, tiktok, spotify).
	 *
	 * @return array<string, string|null> Platform slug to own id or null for agency.
	 */
	public function resolve_platform_credentials(): array {
		if ( null !== $this->credential_map ) {
			return $this->credential_map;
		}

		$map = array();

		foreach ( $this->client->get_connections() as $connection ) {
			if ( 'active' !== strtolower( $connection->status ) ) {
				continue;
			}

			$platform = strtolower( $connection->platform );
			if ( '' !== $platform && ! array_key_exists( $platform, $map ) ) {
				$map[ $platform ] = $connection->id;
			}
		}

		foreach ( $this->client->list_system_credentials() as $row ) {
			$platform = strtolower( (string) ( $row['platform'] ?? '' ) );

			if ( '' === $platform || array_key_exists( $platform, $map ) ) {
				continue;
			}

			$map[ $platform ] = null;
		}

		$this->credential_map = $map;

		return $map;
	}

	/**
	 * Build the platform choices for the audience form.
	 *
	 * Maps the plugin's Platform_Type cases onto the backend's lowercase
	 * platform wire values (the YouTube case launches through Google Ads,
	 * so it maps to "google"). Platforms without either an own credential
	 * or an agency fallback are flagged disabled.
	 *
	 * @param array<string, string|null> $credentials Platform credential map.
	 * @return array<int, array{value: string, label: string, disabled: bool}>
	 */
	public function get_platform_choices( array $credentials ): array {
		$choices = array();

		foreach ( Platform_Type::cases() as $platform ) {
			$value = Platform_Type::YOUTUBE === $platform ? 'google' : $platform->value;

			$label = match ( $platform ) {
				Platform_Type::META    => __( 'Meta (Facebook & Instagram)', 'bushido-almost-famous' ),
				Platform_Type::YOUTUBE => __( 'Google (YouTube)', 'bushido-almost-famous' ),
				Platform_Type::TIKTOK  => __( 'TikTok', 'bushido-almost-famous' ),
				Platform_Type::SPOTIFY => __( 'Spotify', 'bushido-almost-famous' ),
			};

			$choices[] = array(
				'value'    => $value,
				'label'    => $label,
				'disabled' => ! array_key_exists( $value, $credentials ),
			);
		}

		return $choices;
	}

	/**
	 * Get the audience type choices the backend contract accepts.
	 *
	 * Mirrors AUDIENCE_TYPE_VALUES in af-contracts (minus "lookalike" —
	 * lookalikes are created from a saved seed audience via the dedicated
	 * /audiences/{id}/lookalike route, not the create form).
	 *
	 * @return array<string, string> Map of type value to human label.
	 */
	public static function get_type_choices(): array {
		return array(
			'custom'        => __( 'Custom audience', 'bushido-almost-famous' ),
			'website'       => __( 'Website visitors', 'bushido-almost-famous' ),
			'engagement'    => __( 'Engagement audience', 'bushido-almost-famous' ),
			'customer_file' => __( 'Customer list', 'bushido-almost-famous' ),
		);
	}
}
