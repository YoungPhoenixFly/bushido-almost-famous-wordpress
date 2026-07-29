<?php
/**
 * Shared creative asset helpers.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous;

use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Api_Error;
use AlmostFamous\Enums\Error_Code;

/**
 * Maps AF public assets to the plugin's creative UI model.
 */
class Creative_Assets {

	/**
	 * Option key for locally persisted source metadata.
	 *
	 * @var string
	 */
	private const SOURCE_META_OPTION = 'af_creative_asset_sources';

	/**
	 * Option key for locally persisted approval state.
	 *
	 * @var string
	 */
	private const APPROVALS_OPTION = 'af_creative_asset_approvals';

	/**
	 * Default maximum local creative size (25 MiB).
	 */
	private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

	/**
	 * Upload a local file to the AF asset API and confirm the upload.
	 *
	 * @param Api_Client $client      API client.
	 * @param string     $name        Asset name.
	 * @param string     $file_path   Local file path.
	 * @param string     $mime_type   File MIME type.
	 * @param int        $size        File size in bytes.
	 * @param array      $source_meta Source metadata to store locally.
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object}
	 */
	public static function upload_asset_from_file(
		Api_Client $client,
		string $name,
		string $file_path,
		string $mime_type,
		int $size,
		array $source_meta = array()
	): array {
		if ( '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return self::error_result(
				Error_Code::VALIDATION_ERROR->value,
				__( 'The selected source asset is not available on disk.', 'bushido-almost-famous' ),
				$file_path,
				422
			);
		}

		$type = self::infer_asset_type( $mime_type );
		if ( '' === $type ) {
			return self::error_result(
				Error_Code::VALIDATION_ERROR->value,
				__( 'The selected file type is not supported by the asset API.', 'bushido-almost-famous' ),
				$mime_type,
				422
			);
		}

		$actual_size = filesize( $file_path );
		$max_size    = (int) apply_filters(
			'almost_famous/max_creative_upload_bytes',
			self::MAX_UPLOAD_BYTES
		);
		if (
			false === $actual_size
			|| $size <= 0
			|| $size !== $actual_size
			|| $max_size <= 0
			|| $actual_size > $max_size
		) {
			return self::error_result(
				Error_Code::VALIDATION_ERROR->value,
				__( 'The selected asset size is invalid or exceeds the upload limit.', 'bushido-almost-famous' ),
				(string) $size,
				422
			);
		}

		$create = $client->post(
			'/assets',
			array(
				'name'     => $name,
				'type'     => $type,
				'mimeType' => $mime_type,
				'size'     => $actual_size,
			)
		);

		if ( isset( $create['error'] ) ) {
			return $create;
		}

		$asset      = is_array( $create['data'] ?? null ) ? $create['data'] : array();
		$asset_id   = (string) ( $asset['id'] ?? '' );
		$upload_url = (string) ( $asset['uploadUrl'] ?? '' );

		if ( '' === $asset_id || '' === $upload_url ) {
			self::discard_incomplete_asset( $client, $asset_id );
			return self::error_result(
				Error_Code::API_UNREACHABLE->value,
				__( 'The asset API did not return an upload target.', 'bushido-almost-famous' ),
				wp_json_encode( $asset ),
				502
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a LOCAL media-library file to stream to the presigned S3 URL; wp_remote_get is for remote URLs.
		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			self::discard_incomplete_asset( $client, $asset_id );
			return self::error_result(
				Error_Code::API_UNREACHABLE->value,
				__( 'Unable to read the selected file before upload.', 'bushido-almost-famous' ),
				$file_path,
				500
			);
		}

		$upload_response = wp_remote_request(
			$upload_url,
			array(
				'method'    => 'PUT',
				'timeout'   => 60,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'   => $mime_type,
					'Content-Length' => (string) $actual_size,
				),
				'body'      => $file_contents,
			)
		);

		if ( is_wp_error( $upload_response ) ) {
			self::discard_incomplete_asset( $client, $asset_id );
			return self::error_result(
				Error_Code::API_UNREACHABLE->value,
				__( 'The asset file upload failed.', 'bushido-almost-famous' ),
				$upload_response->get_error_message(),
				503
			);
		}

		$upload_status = (int) wp_remote_retrieve_response_code( $upload_response );
		if ( $upload_status < 200 || $upload_status >= 300 ) {
			self::discard_incomplete_asset( $client, $asset_id );
			return self::error_result(
				Error_Code::API_UNREACHABLE->value,
				__( 'The asset storage service rejected the upload.', 'bushido-almost-famous' ),
				(string) $upload_status,
				502
			);
		}

		$confirm        = $client->post( '/assets/' . $asset_id . '/confirm', array() );
		$confirm_status = (int) ( $confirm['status'] ?? 0 );
		if (
			isset( $confirm['error'] )
			|| $confirm_status < 200
			|| $confirm_status >= 300
		) {
			self::discard_incomplete_asset( $client, $asset_id );
			return $confirm;
		}

		self::store_source_meta(
			$asset_id,
			array_merge(
				$source_meta,
				array(
					'name'       => $name,
					'file_path'  => $file_path,
					'mime_type'  => $mime_type,
					'file_size'  => $actual_size,
					'asset_type' => $type,
				)
			)
		);

		return $confirm;
	}

	/**
	 * Best-effort compensation for an asset record whose upload handshake did
	 * not complete. The original lifecycle error remains the caller's result.
	 *
	 * @param Api_Client $client   API client.
	 * @param string     $asset_id Incomplete remote asset ID.
	 * @return void
	 */
	private static function discard_incomplete_asset( Api_Client $client, string $asset_id ): void {
		if ( '' === $asset_id ) {
			return;
		}

		$client->delete( '/assets/' . rawurlencode( $asset_id ) );
	}

	/**
	 * Convert an AF asset into the legacy creative UI shape.
	 *
	 * @param array $asset            AF asset payload.
	 * @param array $platform_status  Per-platform status rows.
	 * @param array $source_meta      Local source metadata.
	 * @return array
	 */
	public static function transform_asset_to_creative( array $asset, array $platform_status = array(), array $source_meta = array() ): array {
		$asset_id   = (string) ( $asset['id'] ?? '' );
		$asset_type = (string) ( $asset['type'] ?? ( $source_meta['asset_type'] ?? '' ) );
		$statuses   = self::normalize_platform_statuses( $platform_status );
		$approvals  = self::get_approved_formats( $asset_id );
		$formats    = array();

		foreach ( self::get_supported_platforms() as $platform ) {
			$platform_row = $statuses[ $platform ] ?? array(
				'platform' => $platform,
				'status'   => 'pending',
				'error'    => null,
			);
			$format_id    = self::build_format_id( $asset_id, $platform );
			$format_type  = self::get_default_format_type( $platform, $asset_type );

			$formats[] = array(
				'id'              => $format_id,
				'platform'        => $platform,
				'format_type'     => $format_type,
				'preview_url'     => $source_meta['source_url'] ?? '',
				'approved'        => in_array( $format_id, $approvals, true ),
				'status'          => $platform_row['status'] ?? 'pending',
				'platformAssetId' => $platform_row['platformAssetId'] ?? null,
				'uploadedAt'      => $platform_row['uploadedAt'] ?? null,
				'expiresAt'       => $platform_row['expiresAt'] ?? null,
				'error'           => $platform_row['error'] ?? null,
			);
		}

		$approved_count = count(
			array_filter(
				$formats,
				static fn( array $format ): bool => ! empty( $format['approved'] )
			)
		);

		return array(
			'id'             => $asset_id,
			'name'           => $asset['name'] ?? ( $source_meta['name'] ?? __( 'Untitled Creative', 'bushido-almost-famous' ) ),
			'status'         => self::normalize_creative_status( (string) ( $asset['status'] ?? '' ) ),
			'type'           => $asset_type,
			'mime_type'      => $asset['mimeType'] ?? ( $source_meta['mime_type'] ?? '' ),
			'file_size'      => $asset['fileSize'] ?? ( $source_meta['file_size'] ?? null ),
			'source_url'     => $source_meta['source_url'] ?? '',
			'thumbnail_url'  => $source_meta['source_url'] ?? '',
			'attachment_id'  => $source_meta['attachment_id'] ?? 0,
			'formats'        => $formats,
			'approved_count' => $approved_count,
			'total_formats'  => count( $formats ),
			'created_at'     => $asset['createdAt'] ?? '',
			'updated_at'     => $asset['updatedAt'] ?? '',
		);
	}

	/**
	 * Store local source metadata for an AF asset.
	 *
	 * @param string $asset_id Asset ID.
	 * @param array  $source   Source metadata.
	 * @return void
	 */
	public static function store_source_meta( string $asset_id, array $source ): void {
		if ( '' === $asset_id ) {
			return;
		}

		$sources              = self::get_source_map();
		$sources[ $asset_id ] = self::sanitize_source_meta( $source );

		update_option( self::SOURCE_META_OPTION, $sources, false );
	}

	/**
	 * Get local source metadata for an AF asset.
	 *
	 * @param string $asset_id Asset ID.
	 * @return array
	 */
	public static function get_source_meta( string $asset_id ): array {
		$sources = self::get_source_map();
		$source  = $sources[ $asset_id ] ?? array();

		return is_array( $source ) ? $source : array();
	}

	/**
	 * Apply a local approval action to an asset's formats.
	 *
	 * @param string $asset_id   Asset ID.
	 * @param string $action     Approval action.
	 * @param array  $format_ids Format IDs.
	 * @return void
	 */
	public static function update_approval_state( string $asset_id, string $action, array $format_ids = array() ): void {
		if ( '' === $asset_id ) {
			return;
		}

		$approvals         = self::get_approval_map();
		$current_approvals = self::get_approved_formats( $asset_id );

		switch ( $action ) {
			case 'approve_all':
				$current_approvals = $format_ids;
				break;
			case 'approve':
				$current_approvals = array_values( array_unique( array_merge( $current_approvals, $format_ids ) ) );
				break;
			case 'unapprove':
				$current_approvals = array_values( array_diff( $current_approvals, $format_ids ) );
				break;
			default:
				return;
		}

		$approvals[ $asset_id ] = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $current_approvals )
			)
		);

		update_option( self::APPROVALS_OPTION, $approvals, false );
	}

	/**
	 * Get the supported creative platforms.
	 *
	 * @return string[]
	 */
	public static function get_supported_platforms(): array {
		return array( 'meta', 'google', 'tiktok', 'spotify' );
	}

	/**
	 * Build the stable format ID for an asset/platform pair.
	 *
	 * @param string $asset_id  Asset ID.
	 * @param string $platform  Platform slug.
	 * @return string
	 */
	public static function build_format_id( string $asset_id, string $platform ): string {
		return $asset_id . ':' . $platform;
	}

	/**
	 * Infer the AF asset kind from the MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	public static function infer_asset_type( string $mime_type ): string {
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return 'image';
		}

		if ( str_starts_with( $mime_type, 'video/' ) ) {
			return 'video';
		}

		if ( str_starts_with( $mime_type, 'audio/' ) ) {
			return 'audio';
		}

		return '';
	}

	/**
	 * Get locally approved format IDs for an asset.
	 *
	 * @param string $asset_id Asset ID.
	 * @return array
	 */
	public static function get_approved_formats( string $asset_id ): array {
		$approvals = self::get_approval_map();
		$formats   = $approvals[ $asset_id ] ?? array();

		return is_array( $formats ) ? array_values( array_map( 'sanitize_text_field', $formats ) ) : array();
	}

	/**
	 * Create an Api_Client-style error result.
	 *
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @param string $detail      Technical detail.
	 * @param int    $http_status HTTP status.
	 * @return array
	 */
	private static function error_result( string $code, string $message, string $detail, int $http_status ): array {
		return array(
			'error'  => new Api_Error( $code, $message, $detail, $http_status ),
			'status' => $http_status,
		);
	}

	/**
	 * Normalize an asset status into the creative UI status model.
	 *
	 * @param string $status Raw asset status.
	 * @return string
	 */
	private static function normalize_creative_status( string $status ): string {
		$status = strtolower( $status );

		return match ( $status ) {
			'pending', 'uploading', 'processing' => 'processing',
			'ready', 'confirmed', 'complete'     => 'complete',
			''                                   => 'draft',
			default                              => $status,
		};
	}

	/**
	 * Normalize platform status rows into a keyed map.
	 *
	 * @param array $rows Raw platform status rows.
	 * @return array
	 */
	private static function normalize_platform_statuses( array $rows ): array {
		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['platform'] ) ) {
				continue;
			}

			$platform                = sanitize_key( (string) $row['platform'] );
			$normalized[ $platform ] = array(
				'platform'        => $platform,
				'status'          => strtolower( (string) ( $row['status'] ?? 'pending' ) ),
				'platformAssetId' => $row['platformAssetId'] ?? null,
				'uploadedAt'      => $row['uploadedAt'] ?? null,
				'expiresAt'       => $row['expiresAt'] ?? null,
				'error'           => $row['error'] ?? null,
			);
		}

		return $normalized;
	}

	/**
	 * Get the default creative format type for an asset/platform pair.
	 *
	 * @param string $platform   Platform slug.
	 * @param string $asset_type Asset kind.
	 * @return string
	 */
	private static function get_default_format_type( string $platform, string $asset_type ): string {
		$asset_type = strtolower( $asset_type );

		if ( 'video' === $asset_type ) {
			return match ( $platform ) {
				'meta'    => 'reels',
				'google'  => 'video',
				'tiktok'  => 'topview',
				'spotify' => 'overlay',
				default   => 'video',
			};
		}

		if ( 'audio' === $asset_type ) {
			return match ( $platform ) {
				'spotify' => 'overlay',
				default   => 'display',
			};
		}

		return match ( $platform ) {
			'meta'    => 'feed',
			'google'  => 'banner',
			'tiktok'  => 'infeed',
			'spotify' => 'display',
			default   => 'display',
		};
	}

	/**
	 * Read the local source metadata map.
	 *
	 * @return array
	 */
	private static function get_source_map(): array {
		$sources = get_option( self::SOURCE_META_OPTION, array() );
		return is_array( $sources ) ? $sources : array();
	}

	/**
	 * Read the local approval map.
	 *
	 * @return array
	 */
	private static function get_approval_map(): array {
		$approvals = get_option( self::APPROVALS_OPTION, array() );
		return is_array( $approvals ) ? $approvals : array();
	}

	/**
	 * Sanitize persisted source metadata.
	 *
	 * @param array $source Raw source metadata.
	 * @return array
	 */
	private static function sanitize_source_meta( array $source ): array {
		return array(
			'name'          => sanitize_text_field( (string) ( $source['name'] ?? '' ) ),
			'source_url'    => esc_url_raw( (string) ( $source['source_url'] ?? '' ) ),
			'file_path'     => sanitize_text_field( (string) ( $source['file_path'] ?? '' ) ),
			'mime_type'     => sanitize_text_field( (string) ( $source['mime_type'] ?? '' ) ),
			'file_size'     => absint( $source['file_size'] ?? 0 ),
			'asset_type'    => sanitize_key( (string) ( $source['asset_type'] ?? '' ) ),
			'attachment_id' => absint( $source['attachment_id'] ?? 0 ),
		);
	}
}
