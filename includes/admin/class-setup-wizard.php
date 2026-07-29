<?php
/**
 * Multi-step guided onboarding wizard.
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
use AlmostFamous\Api\Api_Client;

/**
 * Multi-step guided onboarding wizard.
 *
 * Renders a three-step setup flow:
 *   Step 1 — SSL check
 *   Step 2 — API key input & connection verification
 *   Step 3 — Connected platform confirmation
 *
 * Sets af_setup_complete option on completion and auto-redirects
 * to the wizard when the option is false.
 */
class Setup_Wizard {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'af-setup-wizard';

	/**
	 * Nonce action for wizard form submissions.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'af_setup_wizard_nonce';

	/**
	 * API authentication handler.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * Constructor.
	 *
	 * @param Api_Auth   $auth   API auth handler.
	 * @param Api_Client $client API client.
	 */
	public function __construct( Api_Auth $auth, Api_Client $client ) {
		$this->auth   = $auth;
		$this->client = $client;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Register the wizard as a hidden admin page (no menu entry).
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'', // Hidden, no parent menu.
			__( 'Bushido Almost Famous Setup', 'bushido-almost-famous' ),
			__( 'Setup', 'bushido-almost-famous' ),
			'af_manage_settings',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Auto-redirect to wizard on first activation when af_setup_complete is false.
	 *
	 * Only fires once per activation via a transient flag to prevent redirect loops.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_wizard(): void {
		if ( get_option( 'af_setup_complete', false ) ) {
			return;
		}

		// Only redirect admins.
		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't redirect on AJAX, CLI, or multisite network admin.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Avoid redirect if already on the wizard page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG === $page ) {
			return;
		}

		// Use transient to redirect only once per activation.
		if ( get_transient( 'af_activation_redirect' ) ) {
			delete_transient( 'af_activation_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
	}

	/**
	 * Handle POST form submissions for the wizard steps.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['af_wizard_step'] ) ) {
			return;
		}

		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'af_wizard_nonce' );

		$step = (int) sanitize_text_field( wp_unslash( $_POST['af_wizard_step'] ) );

		switch ( $step ) {
			case 2:
				$this->process_api_key_step();
				break;
			case 3:
				$this->complete_wizard();
				break;
		}
	}

	/**
	 * Process Step 2: store and verify the API key.
	 *
	 * @return void
	 */
	private function process_api_key_step(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_form_submission; key sanitized by regex validation below (sanitize_text_field would strip +/= chars that are valid in base64-encoded keys).
		$api_key = isset( $_POST['af_api_key'] ) ? trim( wp_unslash( $_POST['af_api_key'] ) ) : '';

		// Validate API key characters — sanitize_text_field strips +/= which are valid in API keys.
		if ( ! empty( $api_key ) && ! preg_match( '/^[a-zA-Z0-9_\-\+\/\=\.]+$/', $api_key ) ) {
			$redirect = add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'step'     => 2,
					'af_error' => 'invalid_key_format',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( empty( $api_key ) ) {
			$redirect = add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'step'     => 2,
					'af_error' => 'empty_key',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$stored = $this->auth->store_api_key( $api_key );

		if ( ! $stored ) {
			$redirect = add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'step'     => 2,
					'af_error' => 'store_failed',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Verify connection by validating the API key against /auth/validate
		// (top-level endpoint — not under /public/).
		$response = $this->client->get_top( '/auth/validate' );

		if ( isset( $response['error'] ) ) {
			// Remove the invalid key.
			$this->auth->delete_api_key();

			$redirect = add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'step'     => 2,
					'af_error' => 'connection_failed',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// /auth/validate doesn't list platforms — keep the Step 3 transient
		// empty; the confirmation step renders a generic "you're connected"
		// message and pulls platform status on demand.
		set_transient( 'af_wizard_platforms', array(), HOUR_IN_SECONDS );

		$redirect = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'step' => 3,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Complete the wizard by setting af_setup_complete.
	 *
	 * @return void
	 */
	private function complete_wizard(): void {
		update_option( 'af_setup_complete', true );
		delete_transient( 'af_wizard_platforms' );

		wp_safe_redirect( admin_url( 'admin.php?page=bushido-almost-famous' ) );
		exit;
	}

	/**
	 * Determine the current wizard step.
	 *
	 * @return int Current step number (1, 2, or 3).
	 */
	public function get_current_step(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['step'] ) ) : 1;
		return max( 1, min( 3, $step ) );
	}

	/**
	 * Check if the current environment passes the SSL check.
	 *
	 * @return bool True if the site uses SSL.
	 */
	public function passes_ssl_check(): bool {
		/**
		 * Filters whether the setup wizard's SSL gate is satisfied.
		 *
		 * Defaults to is_ssl(). Local/development environments can opt out
		 * with: add_filter( 'almost_famous_passes_ssl_check', '__return_true' );
		 *
		 * @since 1.0.0
		 * @param bool $passes Whether the SSL check should be considered satisfied.
		 */
		return (bool) apply_filters( 'almost_famous_passes_ssl_check', is_ssl() );
	}

	/**
	 * Get the error message for the current request, if any.
	 *
	 * @return string Error message or empty string.
	 */
	public function get_error_message(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['af_error'] ) ? sanitize_text_field( wp_unslash( $_GET['af_error'] ) ) : '';

		switch ( $error ) {
			case 'empty_key':
				return __( 'Please enter your Bushido API key.', 'bushido-almost-famous' );
			case 'invalid_key_format':
				return __( 'API key contains invalid characters. Only alphanumeric characters, underscores, hyphens, plus signs, slashes, equals signs, and dots are allowed.', 'bushido-almost-famous' );
			case 'store_failed':
				return __( 'Failed to store the API key. Check your wp-config.php has unique AUTH_KEY and AUTH_SALT.', 'bushido-almost-famous' );
			case 'connection_failed':
				return __( 'Could not connect to the Bushido API. Please verify your API key is correct and try again.', 'bushido-almost-famous' );
			default:
				return '';
		}
	}

	/**
	 * Get connected platform data for Step 3.
	 *
	 * @return array Array of platform objects.
	 */
	public function get_connected_platforms(): array {
		$platforms = get_transient( 'af_wizard_platforms' );
		return is_array( $platforms ) ? $platforms : array();
	}

	/**
	 * Render the setup wizard page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		$wizard = $this;
		include ALMOST_FAMOUS_PLUGIN_DIR . 'includes/admin/views/setup-wizard.php';
	}
}
