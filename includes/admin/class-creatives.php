<?php
/**
 * Creative asset management.
 *
 * Handles source asset upload (asset create → server-side S3 PUT →
 * confirm), lists uploaded assets, and surfaces the backend's real
 * per-platform sync status for each asset.
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
use AlmostFamous\Creative_Assets;

/**
 * Creative asset management.
 */
class Creatives {

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
		add_action( 'admin_init', array( $this, 'handle_upload' ) );
	}

	/**
	 * Register the creatives submenu page.
	 *
	 * @return void
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'bushido-almost-famous',
			__( 'Creatives', 'bushido-almost-famous' ),
			__( 'Creatives', 'bushido-almost-famous' ),
			'af_view_campaigns',
			'af-creatives',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the creatives page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$creatives = $this->fetch_creatives();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$creative_id = isset( $_GET['creative_id'] ) ? sanitize_text_field( wp_unslash( $_GET['creative_id'] ) ) : '';
		$creative    = array();

		if ( ! empty( $creative_id ) ) {
			$creative = $this->fetch_creative( $creative_id );
		}

		include ALMOST_FAMOUS_PLUGIN_DIR . 'includes/admin/views/creatives.php';
	}

	/**
	 * Handle source asset upload via WordPress media library (Story 4.3).
	 *
	 * Processes the uploaded file using wp_handle_upload, then streams it
	 * to the Bushido asset API (create → S3 PUT → confirm).
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		if ( ! isset( $_POST['af_creative_upload_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['af_creative_upload_nonce'] ) ),
			'af_creative_upload'
		) ) {
			wp_die( esc_html__( 'Security check failed.', 'bushido-almost-famous' ) );
		}

		if ( ! current_user_can( 'af_manage_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bushido-almost-famous' ) );
		}

		// Check if a media library attachment ID was provided.
		$attachment_id = isset( $_POST['af_source_attachment_id'] )
			? absint( $_POST['af_source_attachment_id'] )
			: 0;

		$asset_url     = '';
		$file_path     = '';
		$mime_type     = '';
		$file_size     = 0;
		$stored_attach = 0;

		if ( $attachment_id > 0 ) {
			// Use existing media library attachment.
			$asset_url     = wp_get_attachment_url( $attachment_id );
			$file_path     = (string) get_attached_file( $attachment_id );
			$mime_type     = (string) get_post_mime_type( $attachment_id );
			$file_size     = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;
			$stored_attach = $attachment_id;
		} elseif ( ! empty( $_FILES['af_source_asset'] ) && ! empty( $_FILES['af_source_asset']['name'] ) ) {
			// Handle direct file upload.
			$overrides = array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg' => 'image/jpeg',
					'png'      => 'image/png',
					'gif'      => 'image/gif',
					'webp'     => 'image/webp',
					'mp4'      => 'video/mp4',
					'mov'      => 'video/quicktime',
				),
			);

			$uploaded = wp_handle_upload( $_FILES['af_source_asset'], $overrides );

			if ( isset( $uploaded['error'] ) ) {
				set_transient( 'af_creative_error', $uploaded['error'], 30 );
				wp_safe_redirect( admin_url( 'admin.php?page=af-creatives' ) );
				exit;
			}

			$asset_url = $uploaded['url'];
			$file_path = $uploaded['file'];
			$mime_type = $uploaded['type'];
			$file_size = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;

			// Register in media library for future reference.
			$attachment = array(
				'post_mime_type' => $uploaded['type'],
				'post_title'     => sanitize_file_name( wp_basename( $uploaded['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $uploaded['file'] );

			if ( ! is_wp_error( $attach_id ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attach_id, $uploaded['file'] );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				$stored_attach = (int) $attach_id;
			}
		}

		if ( empty( $asset_url ) || empty( $file_path ) || empty( $mime_type ) || $file_size <= 0 ) {
			set_transient( 'af_creative_error', __( 'No source asset provided.', 'bushido-almost-famous' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=af-creatives' ) );
			exit;
		}

		$creative_name = isset( $_POST['af_creative_name'] )
			? sanitize_text_field( wp_unslash( $_POST['af_creative_name'] ) )
			: __( 'Untitled Creative', 'bushido-almost-famous' );

		$response = Creative_Assets::upload_asset_from_file(
			$this->client,
			$creative_name,
			$file_path,
			$mime_type,
			$file_size,
			array(
				'name'          => $creative_name,
				'source_url'    => $asset_url,
				'file_path'     => $file_path,
				'mime_type'     => $mime_type,
				'file_size'     => $file_size,
				'attachment_id' => $stored_attach,
			)
		);

		$this->cache->delete_by_prefix( 'creatives' );

		if ( isset( $response['error'] ) ) {
			set_transient( 'af_creative_error', $response['error']->message, 30 );
		} else {
			$creative_id = $response['data']['id'] ?? '';
			$message     = __( 'Creative asset uploaded successfully.', 'bushido-almost-famous' );

			set_transient( 'af_creative_success', $message, 30 );

			if ( ! empty( $creative_id ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=af-creatives&creative_id=' . $creative_id ) );
				exit;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=af-creatives' ) );
		exit;
	}

	/**
	 * Fetch all creatives from the API.
	 *
	 * @return array List of creative arrays.
	 */
	public function fetch_creatives(): array {
		$cache_key = $this->cache->build_key( 'creatives', 'list' );
		$cached    = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->client->get( '/assets' );

		if ( isset( $response['error'] ) ) {
			return array();
		}

		$creatives = array();

		foreach ( $response['data'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$asset_id        = (string) ( $asset['id'] ?? '' );
			$platform_status = array();

			if ( '' !== $asset_id ) {
				$status_response = $this->client->get( '/assets/' . $asset_id . '/platform-status' );
				if ( ! isset( $status_response['error'] ) ) {
					$platform_status = is_array( $status_response['data'] ?? null ) ? $status_response['data'] : array();
				}
			}

			$creatives[] = Creative_Assets::transform_asset_to_creative(
				$asset,
				$platform_status,
				Creative_Assets::get_source_meta( $asset_id )
			);
		}

		$this->cache->set( $cache_key, $creatives, 'campaigns' );

		return $creatives;
	}

	/**
	 * Fetch a single creative by ID (Story 4.3 - polling pattern).
	 *
	 * The asset API processes uploads asynchronously. This method polls
	 * GET /assets/{id} to check processing status. The response includes
	 * a 'status' field: 'processing', 'complete', or 'failed'.
	 *
	 * @param string $creative_id The creative ID.
	 * @return array Creative data including per-platform status rows.
	 */
	public function fetch_creative( string $creative_id ): array {
		// Do not cache processing creatives — always fetch fresh.
		$response = $this->client->get( '/assets/' . $creative_id );

		if ( isset( $response['error'] ) ) {
			return array();
		}

		$platform_status = array();
		$status_response = $this->client->get( '/assets/' . $creative_id . '/platform-status' );

		if ( ! isset( $status_response['error'] ) ) {
			$platform_status = is_array( $status_response['data'] ?? null ) ? $status_response['data'] : array();
		}

		$creative = Creative_Assets::transform_asset_to_creative(
			is_array( $response['data'] ?? null ) ? $response['data'] : array(),
			$platform_status,
			Creative_Assets::get_source_meta( $creative_id )
		);

		// Only cache if processing is complete.
		if ( 'complete' === ( $creative['status'] ?? '' ) ) {
			$cache_key = $this->cache->build_key( 'creatives', $creative_id );
			$this->cache->set( $cache_key, $creative, 'campaigns' );
		}

		return $creative;
	}
}
