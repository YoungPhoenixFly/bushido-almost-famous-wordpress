<?php
/**
 * WordPress admin notice management.
 *
 * Handles platform health notifications (Story 2.5), upgrade required
 * notices, and dismissible notice persistence via user meta.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin notice management.
 *
 * On admin_init, checks:
 *   - af_platform_status transient for degraded platforms
 *   - af_upgrade_required transient for plugin update notices
 *
 * Notices are dismissible and stored in user meta to prevent re-display.
 */
class Admin_Notices {

	/**
	 * User meta key prefix for dismissed notices.
	 *
	 * @var string
	 */
	private const DISMISSED_META_PREFIX = 'af_dismissed_notice_';

	/**
	 * Nonce action for AJAX dismiss.
	 *
	 * @var string
	 */
	private const DISMISS_NONCE_ACTION = 'af_dismiss_notice';

	/**
	 * Collected notices to display.
	 *
	 * @var array<array{id: string, type: string, message: string, dismissible: bool}>
	 */
	private array $notices = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'check_platform_status' ) );
		add_action( 'admin_init', array( $this, 'check_upgrade_required' ) );
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
		add_action( 'wp_ajax_af_dismiss_notice', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dismiss_script' ) );
	}

	/**
	 * Check platform status transient and queue notices for degraded platforms.
	 *
	 * @return void
	 */
	public function check_platform_status(): void {
		$platform_status = get_transient( 'af_platform_status' );

		if ( ! is_array( $platform_status ) || empty( $platform_status ) ) {
			return;
		}

		$degraded_platforms = array();

		foreach ( $platform_status as $platform ) {
			if ( isset( $platform['status'] ) && 'degraded' === $platform['status'] ) {
				$degraded_platforms[] = $platform['name'] ?? $platform['id'] ?? __( 'Unknown Platform', 'bushido-almost-famous' );
			}
		}

		if ( empty( $degraded_platforms ) ) {
			return;
		}

		$notice_id = 'platform_degraded_' . md5( implode( ',', $degraded_platforms ) );

		if ( $this->is_notice_dismissed( $notice_id ) ) {
			return;
		}

		$platform_list = implode( ', ', $degraded_platforms );

		$this->add_notice(
			$notice_id,
			'warning',
			sprintf(
				/* translators: %s: comma-separated list of degraded platform names */
				__( 'Bushido Almost Famous: The following platforms are experiencing issues: %s. Campaign data may be delayed.', 'bushido-almost-famous' ),
				$platform_list
			),
			true
		);
	}

	/**
	 * Check af_upgrade_required transient and display upgrade notice.
	 *
	 * Set by Api_Client when the API returns HTTP 426 Upgrade Required.
	 *
	 * @return void
	 */
	public function check_upgrade_required(): void {
		$upgrade_data = get_transient( 'af_upgrade_required' );

		if ( ! is_array( $upgrade_data ) || empty( $upgrade_data ) ) {
			return;
		}

		$notice_id = 'upgrade_required';

		if ( $this->is_notice_dismissed( $notice_id ) ) {
			return;
		}

		$min_version = $upgrade_data['minimum_version'] ?? '';
		$message     = $upgrade_data['message'] ?? '';

		if ( empty( $message ) ) {
			$message = sprintf(
				/* translators: %s: minimum plugin version required */
				__( 'Bushido Almost Famous: A plugin update is required. Please update to version %s or later for continued API access.', 'bushido-almost-famous' ),
				'' !== $min_version ? $min_version : __( 'latest', 'bushido-almost-famous' )
			);
		}

		$this->add_notice(
			$notice_id,
			'error',
			$message,
			true
		);
	}

	/**
	 * Add a notice to the queue.
	 *
	 * @param string $id          Unique notice identifier.
	 * @param string $type        Notice type: 'info', 'success', 'warning', 'error'.
	 * @param string $message     Notice message (escaped on output).
	 * @param bool   $dismissible Whether the notice is dismissible.
	 * @return void
	 */
	public function add_notice( string $id, string $type, string $message, bool $dismissible = true ): void {
		$this->notices[] = array(
			'id'          => $id,
			'type'        => $type,
			'message'     => $message,
			'dismissible' => $dismissible,
		);
	}

	/**
	 * Display all queued admin notices.
	 *
	 * @return void
	 */
	public function display_notices(): void {
		/**
		 * Filter notices before display.
		 *
		 * @param array $notices Array of notice data.
		 */
		$notices = apply_filters( 'almost_famous/admin/notices', $this->notices );

		foreach ( $notices as $notice ) {
			$classes = array(
				'notice',
				'notice-' . sanitize_html_class( $notice['type'] ),
				'af-admin-notice',
			);

			if ( $notice['dismissible'] ) {
				$classes[] = 'is-dismissible';
			}

			printf(
				'<div class="%1$s" data-af-notice-id="%2$s"><p>%3$s</p></div>',
				esc_attr( implode( ' ', $classes ) ),
				esc_attr( $notice['id'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Handle AJAX request to dismiss a notice.
	 *
	 * Stores the dismissed notice ID in user meta so it does not reappear.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( self::DISMISS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'af_view_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'bushido-almost-famous' ) ), 403 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_ajax_referer.
		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( wp_unslash( $_POST['notice_id'] ) ) : '';

		if ( empty( $notice_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing notice ID.', 'bushido-almost-famous' ) ), 400 );
			return;
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::DISMISSED_META_PREFIX . $notice_id, time() );

		wp_send_json_success( array( 'dismissed' => $notice_id ) );
	}

	/**
	 * Check if a notice has been dismissed by the current user.
	 *
	 * @param string $notice_id Notice identifier.
	 * @return bool True if dismissed.
	 */
	public function is_notice_dismissed( string $notice_id ): bool {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISSED_META_PREFIX . $notice_id, true );

		return ! empty( $dismissed );
	}

	/**
	 * Enqueue inline script for dismiss AJAX on all admin pages.
	 *
	 * Only outputs if there are notices to show — lightweight inline handler.
	 *
	 * @return void
	 */
	public function enqueue_dismiss_script(): void {
		// Only enqueue on Bushido Almost Famous admin pages, or when notices exist.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page       = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$is_af_page = ( 0 === strpos( $page, 'bushido-almost-famous' ) || 0 === strpos( $page, 'af-' ) );

		if ( ! $is_af_page && empty( $this->notices ) ) {
			return;
		}

		wp_add_inline_script(
			'common',
			sprintf(
				'( function() {
					"use strict";
					document.addEventListener( "click", function( e ) {
						var btn = e.target.closest( ".af-admin-notice .notice-dismiss" );
						if ( ! btn ) return;
						var notice = btn.closest( ".af-admin-notice" );
						if ( ! notice ) return;
						var noticeId = notice.getAttribute( "data-af-notice-id" );
						if ( ! noticeId ) return;
						var data = new FormData();
						data.append( "action", "af_dismiss_notice" );
						data.append( "nonce", "%s" );
						data.append( "notice_id", noticeId );
						fetch( "%s", { method: "POST", credentials: "same-origin", body: data } );
					} );
				} )();',
				esc_js( wp_create_nonce( self::DISMISS_NONCE_ACTION ) ),
				esc_js( admin_url( 'admin-ajax.php' ) )
			)
		);
	}
}
