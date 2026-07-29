<?php
/**
 * Bushido Almost Famous admin Home hub.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Plugin;

/**
 * Connect + console hub.
 *
 * The top-level Bushido Almost Famous admin page is a static "Home" hub. It guides
 * an unconnected site through the setup wizard, and once connected it points
 * to the front-end campaign console (the [almost-famous-portal] page) plus the
 * config tools (Audiences, Creatives, Conversions, Connections, Settings).
 *
 * Campaign browsing/creation/analytics now live entirely in the front-end
 * portal, so this page performs no polling and enqueues no dashboard script.
 */
class Dashboard {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'bushido-almost-famous';

	/**
	 * Admin-post action for creating the console page.
	 *
	 * @var string
	 */
	private const CREATE_CONSOLE_ACTION = 'af_create_console_page';

	/**
	 * Nonce action for the create-console-page form.
	 *
	 * @var string
	 */
	private const CREATE_CONSOLE_NONCE = 'af_create_console_page_nonce';

	/**
	 * Option that caches the resolved console page id.
	 *
	 * @var string
	 */
	private const CONSOLE_PAGE_OPTION = 'af_console_page_id';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::CREATE_CONSOLE_ACTION, array( $this, 'handle_create_console_page' ) );
	}

	/**
	 * Register the hub as the default Bushido Almost Famous admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_menu_page(
			__( 'Bushido Almost Famous', 'bushido-almost-famous' ),
			__( 'Bushido Almost Famous', 'bushido-almost-famous' ),
			'af_view_campaigns',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-megaphone',
			30
		);

		// Home submenu item that maps to the same slug.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Home', 'bushido-almost-famous' ),
			__( 'Home', 'bushido-almost-famous' ),
			'af_view_campaigns',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the shared admin stylesheet for the hub.
	 *
	 * The hub is static PHP — no dashboard poller is enqueued.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'af-admin',
			ALMOST_FAMOUS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ALMOST_FAMOUS_VERSION
		);
	}

	/**
	 * Whether the plugin is connected to a Bushido account.
	 *
	 * @return bool True when setup is complete and an API key is stored.
	 */
	public function is_connected(): bool {
		return (bool) get_option( 'af_setup_complete', false ) && ( new Api_Auth() )->has_api_key();
	}

	/**
	 * Whether any tracked platform is currently degraded.
	 *
	 * @return bool True when the platform-status transient reports a degradation.
	 */
	public function is_degraded(): bool {
		$platform_status = $this->get_platform_status();

		foreach ( $platform_status as $status ) {
			if ( isset( $status['status'] ) && 'degraded' === $status['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Platform health rows, refreshed from the backend when stale.
	 *
	 * The af_platform_status transient (also consumed by Admin_Notices) had no
	 * producer, so the degraded banner could never fire. Rebuild it here from
	 * the org's platform connections: the backend reports lastHealthStatus as
	 * healthy/unhealthy, which we map to this surface's degraded flag.
	 *
	 * @return array<int, array{id: string, name: string, status: string}>
	 */
	private function get_platform_status(): array {
		$cached = get_transient( 'af_platform_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! $this->is_connected() ) {
			return array();
		}

		$rows = array();
		foreach ( Plugin::get_instance()->api_client()->get_connections() as $connection ) {
			$health = strtolower( (string) ( $connection->lastHealthStatus ?? '' ) );

			$rows[] = array(
				'id'     => $connection->platform,
				'name'   => $connection->platform,
				'status' => 'unhealthy' === $health ? 'degraded' : 'healthy',
			);
		}

		set_transient( 'af_platform_status', $rows, 15 * MINUTE_IN_SECONDS );

		return $rows;
	}

	/**
	 * Resolve the permalink of the campaign console page, if one exists.
	 *
	 * @return string Permalink of the console page, or empty string when none.
	 */
	public function get_console_url(): string {
		$page_id = $this->find_console_page_id();
		return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Find the published page hosting the [almost-famous-portal] shortcode or
	 * the almost-famous/portal block, caching its id to avoid re-querying.
	 *
	 * @return int Console page id, or 0 when none is found.
	 */
	private function find_console_page_id(): int {
		$cached = (int) get_option( self::CONSOLE_PAGE_OPTION, 0 );
		if ( $cached > 0 && $this->is_console_page( get_post( $cached ) ) ) {
			return $cached;
		}

		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'suppress_filters' => false,
			)
		);

		foreach ( $pages as $page ) {
			if ( $this->is_console_page( $page ) ) {
				update_option( self::CONSOLE_PAGE_OPTION, $page->ID );
				return (int) $page->ID;
			}
		}

		return 0;
	}

	/**
	 * Whether a post embeds the campaign console (shortcode or block).
	 *
	 * @param mixed $post Post object to inspect.
	 * @return bool True when the post is a published console page.
	 */
	private function is_console_page( mixed $post ): bool {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		return has_shortcode( (string) $post->post_content, 'almost-famous-portal' )
			|| has_block( 'almost-famous/portal', $post );
	}

	/**
	 * Handle the "Create console page" form submission.
	 *
	 * Inserts a published page hosting the portal shortcode, caches its id, and
	 * redirects back to the hub.
	 *
	 * @return void
	 */
	public function handle_create_console_page(): void {
		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		check_admin_referer( self::CREATE_CONSOLE_NONCE, 'af_console_nonce' );

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Campaign Console', 'bushido-almost-famous' ),
				'post_content' => '[almost-famous-portal]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( is_int( $page_id ) && $page_id > 0 ) {
			update_option( self::CONSOLE_PAGE_OPTION, $page_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * URL that the "Create console page" form posts to.
	 *
	 * @return string admin-post.php action URL.
	 */
	public function get_create_console_action_url(): string {
		return admin_url( 'admin-post.php?action=' . self::CREATE_CONSOLE_ACTION );
	}

	/**
	 * Nonce action for the create-console-page form.
	 *
	 * @return string
	 */
	public function get_create_console_nonce_action(): string {
		return self::CREATE_CONSOLE_NONCE;
	}

	/**
	 * Render the hub page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'af_view_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		$hub             = $this;
		$is_connected    = $this->is_connected();
		$degraded        = $this->is_degraded();
		$credential_mode = (string) get_option( 'af_org_credential_mode', 'agency' );
		$console_url     = $is_connected ? $this->get_console_url() : '';

		include ALMOST_FAMOUS_PLUGIN_DIR . 'includes/admin/views/home.php';
	}
}
