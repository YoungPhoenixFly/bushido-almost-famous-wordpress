<?php
/**
 * Tests for Creative_Assets helper.
 *
 * Covers:
 *   - upload_asset_from_file end-to-end happy path:
 *       POST /assets   -> get presigned uploadUrl
 *       PUT  uploadUrl -> stream file
 *       POST /assets/{id}/confirm -> finalize
 *     including the local source-metadata persistence in
 *     `af_creative_asset_sources` option.
 *   - upload_asset_from_file error paths (missing file, unsupported MIME,
 *     missing uploadUrl, signed-URL failure, confirm failure).
 *   - update_approval_state approve / approve_all / unapprove persistence
 *     via the `af_creative_asset_approvals` option.
 *   - transform_asset_to_creative shape + status normalization.
 *   - infer_asset_type matrix.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Creative_Assets;
use PHPUnit\Framework\TestCase;

class Test_Creative_Assets extends TestCase {

	private Api_Client $client;
	private string $tmp_file;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test' ) );
		$this->client = new Api_Client( $auth );

		$this->tmp_file = tempnam( sys_get_temp_dir(), 'af-test-' );
		file_put_contents( $this->tmp_file, 'fake png bytes' );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->tmp_file ) ) {
			unlink( $this->tmp_file );
		}
		parent::tearDown();
	}

	private function mock_create_returns_upload_url( string $asset_id = 'asset_42' ): void {
		af_test_register_http_response(
			'/public/assets',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'data' => array(
						'id'        => $asset_id,
						'uploadUrl' => 'https://signed.example/upload/' . $asset_id,
					),
				) ),
				'headers'  => array(),
			)
		);
	}

	private function mock_put_signed_ok( string $asset_id = 'asset_42' ): void {
		af_test_register_http_response(
			'signed.example/upload/' . $asset_id,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
	}

	private function mock_confirm_ok( string $asset_id = 'asset_42' ): void {
		af_test_register_http_response(
			'/assets/' . $asset_id . '/confirm',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => $asset_id, 'status' => 'ready' ) ) ),
				'headers'  => array(),
			)
		);
	}

	private function mock_delete_ok( string $asset_id = 'asset_42' ): void {
		af_test_register_http_response(
			'/assets/' . $asset_id,
			array(
				'response' => array( 'code' => 204 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
	}

	// -------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------

	public function test_infer_asset_type_recognises_image_video_audio(): void {
		$this->assertSame( 'image', Creative_Assets::infer_asset_type( 'image/png' ) );
		$this->assertSame( 'video', Creative_Assets::infer_asset_type( 'video/mp4' ) );
		$this->assertSame( 'audio', Creative_Assets::infer_asset_type( 'audio/mpeg' ) );
		$this->assertSame( '', Creative_Assets::infer_asset_type( 'application/pdf' ) );
	}

	public function test_get_supported_platforms_lists_four(): void {
		$this->assertSame(
			array( 'meta', 'google', 'tiktok', 'spotify' ),
			Creative_Assets::get_supported_platforms()
		);
	}

	public function test_build_format_id_pairs_asset_and_platform(): void {
		$this->assertSame( 'asset_1:meta', Creative_Assets::build_format_id( 'asset_1', 'meta' ) );
	}

	// -------------------------------------------------------------------
	// upload_asset_from_file — happy path
	// -------------------------------------------------------------------

	public function test_upload_asset_from_file_full_three_step_flow(): void {
		$this->mock_create_returns_upload_url();
		$this->mock_put_signed_ok();
		$this->mock_confirm_ok();

		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'My Creative',
			$this->tmp_file,
			'image/png',
			(int) filesize( $this->tmp_file ),
			array( 'source_url' => 'https://cdn.example/file.png', 'attachment_id' => 7 )
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'asset_42', $result['data']['id'] );

		$reqs = af_test_get_http_requests();
		$methods = array_map( static fn( array $r ): string => (string) ( $r['args']['method'] ?? '' ), $reqs );
		$this->assertContains( 'POST', $methods );
		$this->assertContains( 'PUT', $methods );

		// Local source metadata persisted.
		$sources = get_option( 'af_creative_asset_sources', array() );
		$this->assertArrayHasKey( 'asset_42', $sources );
		$this->assertSame( 'My Creative', $sources['asset_42']['name'] );
		$this->assertSame( 'image', $sources['asset_42']['asset_type'] );
		$this->assertSame( 7, $sources['asset_42']['attachment_id'] );
	}

	// -------------------------------------------------------------------
	// upload_asset_from_file — error paths
	// -------------------------------------------------------------------

	public function test_upload_asset_from_file_returns_error_when_file_missing(): void {
		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			'/no/such/file.png',
			'image/png',
			100
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 422, $result['status'] );
	}

	public function test_upload_asset_from_file_rejects_unsupported_mime(): void {
		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			$this->tmp_file,
			'application/pdf',
			100
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 422, $result['status'] );
	}

	public function test_upload_asset_rejects_oversized_file_before_api_or_read(): void {
		add_filter(
			'almost_famous/max_creative_upload_bytes',
			static fn (): int => 1
		);

		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			$this->tmp_file,
			'image/png',
			(int) filesize( $this->tmp_file )
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 422, $result['status'] );
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	public function test_upload_asset_rejects_unverified_size_before_api(): void {
		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			$this->tmp_file,
			'image/png',
			(int) filesize( $this->tmp_file ) + 1
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 422, $result['status'] );
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	public function test_upload_asset_from_file_returns_error_when_create_has_no_upload_url(): void {
		af_test_register_http_response(
			'/public/assets',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 'asset_1' ) ) ),
				'headers'  => array(),
			)
		);

		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			$this->tmp_file,
			'image/png',
			(int) filesize( $this->tmp_file )
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 502, $result['status'] );
	}

	public function test_upload_asset_from_file_returns_error_on_signed_put_failure(): void {
		$this->mock_delete_ok();
		$this->mock_create_returns_upload_url();
		af_test_register_http_response(
			'signed.example/upload/asset_42',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '',
				'headers'  => array(),
			)
		);

		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			$this->tmp_file,
			'image/png',
			(int) filesize( $this->tmp_file )
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 502, $result['status'] );
		$this->assertContains(
			'DELETE',
			array_column( array_column( af_test_get_http_requests(), 'args' ), 'method' )
		);
	}

	public function test_upload_asset_from_file_returns_error_when_confirm_fails(): void {
		// The order of registration matters — wp_remote_request iterates
		// patterns in insertion order and picks the first substring match.
		// We must register the most specific URL (confirm) BEFORE the
		// generic "/public/assets" pattern used by the create call.
		af_test_register_http_response(
			'/asset_42/confirm',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'error' => array( 'code' => 'fail', 'message' => 'no' ) ) ),
				'headers'  => array(),
			)
		);
		$this->mock_delete_ok();
		$this->mock_create_returns_upload_url();
		$this->mock_put_signed_ok();

		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			'x',
			$this->tmp_file,
			'image/png',
			(int) filesize( $this->tmp_file )
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertContains(
			'DELETE',
			array_column( array_column( af_test_get_http_requests(), 'args' ), 'method' )
		);
	}

	// -------------------------------------------------------------------
	// approval state persistence
	// -------------------------------------------------------------------

	public function test_update_approval_state_approve_appends_format_ids(): void {
		Creative_Assets::update_approval_state( 'asset_1', 'approve', array( 'asset_1:meta' ) );
		Creative_Assets::update_approval_state( 'asset_1', 'approve', array( 'asset_1:tiktok' ) );

		$this->assertSame(
			array( 'asset_1:meta', 'asset_1:tiktok' ),
			Creative_Assets::get_approved_formats( 'asset_1' )
		);
	}

	public function test_update_approval_state_approve_all_replaces_list(): void {
		Creative_Assets::update_approval_state( 'asset_1', 'approve', array( 'asset_1:meta' ) );
		Creative_Assets::update_approval_state(
			'asset_1',
			'approve_all',
			array( 'asset_1:meta', 'asset_1:google', 'asset_1:tiktok', 'asset_1:spotify' )
		);

		$this->assertCount( 4, Creative_Assets::get_approved_formats( 'asset_1' ) );
	}

	public function test_update_approval_state_unapprove_removes_targets(): void {
		Creative_Assets::update_approval_state(
			'asset_1',
			'approve',
			array( 'asset_1:meta', 'asset_1:tiktok' )
		);

		Creative_Assets::update_approval_state(
			'asset_1',
			'unapprove',
			array( 'asset_1:meta' )
		);

		$this->assertSame(
			array( 'asset_1:tiktok' ),
			Creative_Assets::get_approved_formats( 'asset_1' )
		);
	}

	public function test_update_approval_state_ignores_unknown_action(): void {
		Creative_Assets::update_approval_state( 'asset_1', 'approve', array( 'asset_1:meta' ) );
		Creative_Assets::update_approval_state( 'asset_1', 'nope', array( 'asset_1:meta' ) );

		$this->assertSame(
			array( 'asset_1:meta' ),
			Creative_Assets::get_approved_formats( 'asset_1' )
		);
	}

	public function test_update_approval_state_noop_on_empty_asset_id(): void {
		Creative_Assets::update_approval_state( '', 'approve', array( 'f1' ) );
		$this->assertSame( array(), Creative_Assets::get_approved_formats( '' ) );
	}

	// -------------------------------------------------------------------
	// source metadata round-trip
	// -------------------------------------------------------------------

	public function test_store_and_get_source_meta_round_trip(): void {
		Creative_Assets::store_source_meta(
			'asset_99',
			array(
				'name'       => 'Hero',
				'source_url' => 'https://cdn.example/asset.png',
				'file_path'  => '/tmp/asset.png',
				'mime_type'  => 'image/png',
				'file_size'  => 12345,
				'asset_type' => 'image',
				'attachment_id' => 7,
			)
		);

		$source = Creative_Assets::get_source_meta( 'asset_99' );
		$this->assertSame( 'Hero', $source['name'] );
		$this->assertSame( 'https://cdn.example/asset.png', $source['source_url'] );
		$this->assertSame( 12345, $source['file_size'] );
		$this->assertSame( 7, $source['attachment_id'] );
	}

	public function test_store_source_meta_noop_on_empty_asset_id(): void {
		Creative_Assets::store_source_meta( '', array( 'name' => 'x' ) );
		$this->assertSame( array(), get_option( 'af_creative_asset_sources', array() ) );
	}

	// -------------------------------------------------------------------
	// transform_asset_to_creative — UI shape
	// -------------------------------------------------------------------

	public function test_transform_asset_to_creative_normalizes_status_and_builds_formats(): void {
		$creative = Creative_Assets::transform_asset_to_creative(
			array(
				'id'     => 'a1',
				'name'   => 'A1',
				'status' => 'processing',
				'type'   => 'image',
			),
			array(
				array( 'platform' => 'meta', 'status' => 'ready' ),
			),
			array( 'source_url' => 'https://cdn/x.png' )
		);

		$this->assertSame( 'a1', $creative['id'] );
		$this->assertSame( 'processing', $creative['status'] );
		$this->assertSame( 4, $creative['total_formats'] );
		// Default format type for meta + image is 'feed'.
		$meta_format = null;
		foreach ( $creative['formats'] as $format ) {
			if ( 'meta' === $format['platform'] ) {
				$meta_format = $format;
				break;
			}
		}
		$this->assertNotNull( $meta_format );
		$this->assertSame( 'feed', $meta_format['format_type'] );
		$this->assertSame( 'ready', $meta_format['status'] );
	}

	public function test_transform_asset_to_creative_marks_approved_formats_from_option(): void {
		Creative_Assets::update_approval_state( 'a1', 'approve', array( 'a1:meta', 'a1:tiktok' ) );

		$creative = Creative_Assets::transform_asset_to_creative(
			array( 'id' => 'a1', 'name' => 'A1', 'status' => 'ready', 'type' => 'video' ),
			array(),
			array()
		);

		$approved = array_filter(
			$creative['formats'],
			static fn( array $f ): bool => ! empty( $f['approved'] )
		);
		$this->assertCount( 2, $approved );
		$this->assertSame( 2, $creative['approved_count'] );
	}

	public function test_transform_asset_to_creative_falls_back_to_draft_for_empty_status(): void {
		$creative = Creative_Assets::transform_asset_to_creative(
			array( 'id' => 'a1', 'name' => 'A1', 'status' => '', 'type' => 'image' ),
			array(),
			array()
		);
		$this->assertSame( 'draft', $creative['status'] );
	}
}
