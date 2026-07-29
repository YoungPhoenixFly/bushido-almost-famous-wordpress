<?php
/**
 * Main plugin orchestrator.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Api_Proxy;
use AlmostFamous\Api\Oauth_Controller;
use AlmostFamous\Api\Wp_Connect_Controller;
use AlmostFamous\Admin\Admin_Menu;
use AlmostFamous\Admin\Setup_Wizard;
use AlmostFamous\Admin\Site_Health;
use AlmostFamous\Shortcodes\Shortcodes;
use AlmostFamous\Webhooks\Webhook_Handlers;
use AlmostFamous\Webhooks\Webhook_Receiver;
use AlmostFamous\Commerce\Woo_Integration;
use AlmostFamous\Consent\Consent_Integration;
use AlmostFamous\Multisite\Network_Admin;
use AlmostFamous\Multisite\Site_Manager;

/**
 * Plugin singleton class — initializes hooks, loads services, and manages plugin lifecycle.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * API authentication service.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $api_auth;

	/**
	 * API client service.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api_client;

	/**
	 * API cache service.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $api_cache;

	/**
	 * API REST proxy service.
	 *
	 * @var Api_Proxy
	 */
	private Api_Proxy $api_proxy;

	/**
	 * Backend-bounce OAuth controller.
	 *
	 * @var Oauth_Controller
	 */
	private Oauth_Controller $oauth;

	/**
	 * WordPress "Connect this site" handshake controller.
	 *
	 * @var Wp_Connect_Controller
	 */
	private Wp_Connect_Controller $wp_connect;

	/**
	 * Admin menu controller.
	 *
	 * @var Admin_Menu
	 */
	private Admin_Menu $admin_menu;

	/**
	 * Setup wizard controller.
	 *
	 * @var Setup_Wizard
	 */
	private Setup_Wizard $setup_wizard;

	/**
	 * Site Health integration.
	 *
	 * @var Site_Health
	 */
	private Site_Health $site_health;

	/**
	 * Shortcodes controller.
	 *
	 * @var Shortcodes
	 */
	private Shortcodes $shortcodes;

	/**
	 * Signed inbound webhook receiver.
	 *
	 * @var Webhook_Receiver
	 */
	private Webhook_Receiver $webhook_receiver;

	/**
	 * WooCommerce integration (attribution + conversion tracking).
	 *
	 * @var Woo_Integration
	 */
	private Woo_Integration $woo_integration;

	/**
	 * Consent + WP Privacy (exporter/eraser) integration.
	 *
	 * @var Consent_Integration
	 */
	private Consent_Integration $consent_integration;

	/**
	 * Multisite network-admin settings.
	 *
	 * @var Network_Admin
	 */
	private Network_Admin $network_admin_settings;

	/**
	 * Resolves the effective site or network credential.
	 *
	 * @var Site_Manager
	 */
	private Site_Manager $site_manager;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the API client service.
	 *
	 * @return Api_Client
	 */
	public function api_client(): Api_Client {
		return $this->api_client;
	}

	/**
	 * Get the API auth service.
	 *
	 * @return Api_Auth
	 */
	public function api_auth(): Api_Auth {
		return $this->api_auth;
	}

	/**
	 * Get the API cache service.
	 *
	 * @return Api_Cache
	 */
	public function api_cache(): Api_Cache {
		return $this->api_cache;
	}

	/**
	 * Get the admin menu controller.
	 *
	 * @return Admin_Menu
	 */
	public function admin_menu(): Admin_Menu {
		return $this->admin_menu;
	}

	/**
	 * Get the effective multisite credential resolver.
	 *
	 * @return Site_Manager
	 */
	public function site_manager(): Site_Manager {
		return $this->site_manager;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {
		$this->init_services();
		$this->init_hooks();
	}

	/**
	 * Initialize service dependencies.
	 *
	 * @return void
	 */
	private function init_services(): void {
		$this->api_auth               = new Api_Auth();
		$this->network_admin_settings = new Network_Admin( $this->api_auth );
		$this->site_manager           = new Site_Manager( $this->api_auth, $this->network_admin_settings );
		$this->api_client             = $this->site_manager->get_api_client() ?? new Api_Client( $this->api_auth );
		$this->api_cache              = new Api_Cache();
		$this->api_proxy              = new Api_Proxy( $this->api_client, $this->api_cache );
		$this->oauth                  = new Oauth_Controller( $this->api_client );
		$this->wp_connect             = new Wp_Connect_Controller( new Api_Client( $this->api_auth ), $this->api_auth );
		$this->admin_menu             = new Admin_Menu( $this->api_client, $this->api_cache );
		$this->setup_wizard           = new Setup_Wizard( $this->api_auth, $this->api_client );
		$this->site_health            = new Site_Health( $this->api_client );
		$this->shortcodes             = new Shortcodes();
		$this->shortcodes->register();

		$this->webhook_receiver = new Webhook_Receiver(
			new Webhook_Handlers( $this->api_cache ),
			$this->api_auth
		);

		// Storefront + privacy + multisite integrations. Each self-guards its
		// preconditions (WooCommerce active / is_multisite), so constructing
		// them unconditionally is safe.
		$this->woo_integration     = new Woo_Integration();
		$this->consent_integration = new Consent_Integration();
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Admin menu and page controllers (Dashboard, Analytics, Notices).
		$this->admin_menu->init();

		// Setup wizard.
		$this->setup_wizard->init();

		// WP Site Health integration.
		$this->site_health->init();

		// Block registration.
		add_action( 'init', array( $this, 'register_blocks' ) );

		// REST API proxy routes.
		add_action( 'rest_api_init', array( $this->api_proxy, 'register_routes' ) );

		// Backend-bounce OAuth routes (Connect/Callback/Disconnect for Meta, Google, TikTok, Spotify).
		add_action( 'rest_api_init', array( $this->oauth, 'register_routes' ) );

		// WordPress "Connect this site" handshake routes (start + callback).
		add_action( 'rest_api_init', array( $this->wp_connect, 'register_routes' ) );
		// Public requests only ensure background retry work is scheduled; the
		// potentially slow HTTP retry runs under a cross-request lease in cron.
		add_action( 'init', array( $this->wp_connect, 'schedule_pending_delivery_retry' ) );
		add_action( Wp_Connect_Controller::DELIVERY_RETRY_HOOK, array( $this->wp_connect, 'retry_pending_delivery' ) );

		// Signed inbound webhook receiver (HMAC + replay window + idempotency).
		// Dormant: register_routes() self-gates on the
		// `almost_famous/enable_webhooks` filter (default false) because no
		// Bushido backend currently emits these events.
		$this->webhook_receiver->register();

		// WooCommerce order attribution + Meta CAPI conversion tracking
		// (no-op unless WooCommerce is active).
		$this->woo_integration->register();

		// WP Privacy exporter/eraser + consent-management detection.
		$this->consent_integration->init();

		// Multisite network-admin settings page + network API key
		// (no-op on single-site installs).
		$this->network_admin_settings->init();

		// Shared admin assets for campaign, creative, dashboard, and analytics pages.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ), 5 );
	}

	/**
	 * Register and enqueue shared admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'bushido-almost-famous' ) && false === strpos( $hook_suffix, 'af-' ) ) {
			return;
		}

		wp_enqueue_media();

		wp_register_style(
			'af-admin',
			ALMOST_FAMOUS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ALMOST_FAMOUS_VERSION
		);

		wp_register_script(
			'af-admin',
			ALMOST_FAMOUS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch', 'media-editor', 'media-views' ),
			ALMOST_FAMOUS_VERSION,
			true
		);

		wp_enqueue_style( 'af-admin' );
		wp_enqueue_script( 'af-admin' );

		wp_localize_script(
			'af-admin',
			'afAdminData',
			array(
				'restNamespace' => 'almost-famous/v1',
				'endpoints'     => array(
					'creatives' => '/almost-famous/v1/creatives',
				),
				'i18n'          => array(
					'loadingCreatives'      => __( 'Loading creative assets...', 'bushido-almost-famous' ),
					'noCreatives'           => __( 'No approved creative assets are available yet.', 'bushido-almost-famous' ),
					'creativeLoadFail'      => __( 'Unable to load creative assets right now.', 'bushido-almost-famous' ),
					'selectedCreatives'     => __( 'Selected creatives:', 'bushido-almost-famous' ),
					'processing'            => __( 'Creative processing is still in progress. Refreshing…', 'bushido-almost-famous' ),
					'processingFailed'      => __( 'Unable to refresh creative status automatically.', 'bushido-almost-famous' ),
					'processingTimeout'     => __( 'Still processing. Refresh the page later to check on progress.', 'bushido-almost-famous' ),
					'campaignSaved'         => __( 'Campaign saved successfully.', 'bushido-almost-famous' ),
					'saving'                => __( 'Saving…', 'bushido-almost-famous' ),
					'audienceSaved'         => __( 'Audience saved successfully.', 'bushido-almost-famous' ),
					'deleteAudience'        => __( 'Delete this audience? Campaigns already using it keep running; the audience just stops being available for new campaigns.', 'bushido-almost-famous' ),
					'deleteAudienceErr'     => __( 'Could not delete the audience. Please try again.', 'bushido-almost-famous' ),
					'noPlatformCredential'  => __( 'No connected credential for the selected platform. Connect one on the Accounts page first.', 'bushido-almost-famous' ),
					'lookalikeCreated'      => __( 'Lookalike audience created.', 'bushido-almost-famous' ),
					'creatingLookalike'     => __( 'Creating…', 'bushido-almost-famous' ),
					/* translators: %s: seed audience name. */
					'lookalikeNameFmt'      => __( 'Lookalike — %s', 'bushido-almost-famous' ),
					'estimating'            => __( 'Estimating…', 'bushido-almost-famous' ),
					'refreshEstimate'       => __( 'Refresh Estimate', 'bushido-almost-famous' ),
					'estimateError'         => __( 'Error', 'bushido-almost-famous' ),
					'applied'               => __( 'Applied!', 'bushido-almost-famous' ),
					'apply'                 => __( 'Apply', 'bushido-almost-famous' ),
					'creativeGenFailed'     => __( 'Creative generation failed. Please try again.', 'bushido-almost-famous' ),
					'assetProcessingFailed' => __( 'Asset processing failed. Please try again.', 'bushido-almost-famous' ),
					/* translators: %s: progress percentage. */
					'progressFmt'           => __( 'Progress: %s%', 'bushido-almost-famous' ),
				),
			)
		);
	}

	/**
	 * Register Gutenberg blocks.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$blocks = array( 'campaign-widget', 'portal' );

		foreach ( $blocks as $block ) {
			$block_dir = ALMOST_FAMOUS_PLUGIN_DIR . 'blocks/' . $block;
			if ( file_exists( $block_dir . '/block.json' ) ) {
				register_block_type( $block_dir );
			}
		}
	}
}
