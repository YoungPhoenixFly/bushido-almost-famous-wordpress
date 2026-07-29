<?php
/**
 * Tests for the Creatives admin controller.
 *
 * Covers:
 *   - handle_upload() nonce + permission gates, missing-source error, and
 *     the real upload pipeline (asset create → S3 PUT → confirm) via a
 *     media-library attachment
 *   - fetch_creative caches only when status === 'complete'
 *   - fetch_creatives error handling
 *   - the fabricated format/approval/regenerate machinery is gone
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Creatives;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Af_Creatives_Wpdb' ) ) {
	final class Af_Creatives_Wpdb {
		public string $options = 'wp_options';
		public function esc_like( string $text ): string { return $text; }
		public function prepare( string $q, mixed ...$a ): string { return $q; }
		public function get_col( string $q ): array { return array(); }
	}
}

// Attachment stubs for the media-library upload path. The controlling
// globals let each test point the attachment at a real temp file.
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $attachment_id ): string {
		global $af_test_attachment_url;
		return (string) ( $af_test_attachment_url ?? '' );
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( int $attachment_id ): string {
		global $af_test_attachment_file;
		return (string) ( $af_test_attachment_file ?? '' );
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( int $post_id ): string {
		global $af_test_attachment_mime;
		return (string) ( $af_test_attachment_mime ?? '' );
	}
}

class Test_Creatives extends TestCase {

	private Creatives $creatives;
	private Api_Client $client;
	private Api_Cache $cache;
	private string $tmp_file = '';

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_POST = array();
		$_GET  = array();

		global $af_test_redirect_throws, $wpdb;
		$af_test_redirect_throws = true;
		$wpdb                    = new Af_Creatives_Wpdb();

		global $af_test_attachment_url, $af_test_attachment_file, $af_test_attachment_mime;
		$af_test_attachment_url  = '';
		$af_test_attachment_file = '';
		$af_test_attachment_mime = '';

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test' ) );

		$this->client    = new Api_Client( $auth );
		$this->cache     = new Api_Cache();
		$this->creatives = new Creatives( $this->client, $this->cache );
	}

	protected function tearDown(): void {
		global $af_test_redirect_throws;
		$af_test_redirect_throws = false;
		$_POST = array();
		$_GET  = array();

		if ( '' !== $this->tmp_file && file_exists( $this->tmp_file ) ) {
			unlink( $this->tmp_file );
		}

		parent::tearDown();
	}

	private function mock_ok( string $needle, mixed $data = array() ): void {
		af_test_register_http_response(
			$needle,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => $data ) ),
				'headers'  => array(),
			)
		);
	}

	private function mock_status_404( string $needle ): void {
		af_test_register_http_response(
			$needle,
			array(
				'response' => array( 'code' => 404 ),
				'body'     => wp_json_encode( array(
					'error' => array( 'code' => 'not_found', 'message' => 'no' ),
				) ),
				'headers'  => array(),
			)
		);
	}

	/**
	 * Point the attachment stubs at a real temp file and mock the
	 * three-step asset pipeline (create → S3 PUT → confirm).
	 *
	 * The confirm mock is registered FIRST — the stub HTTP transport
	 * matches needles in registration order and the confirm URL also
	 * contains the create needle.
	 *
	 * @param string $asset_id Asset id returned by the pipeline.
	 */
	private function arm_attachment_upload( string $asset_id ): void {
		$this->tmp_file = (string) tempnam( sys_get_temp_dir(), 'af-test-' );
		file_put_contents( $this->tmp_file, 'fake png bytes' );

		global $af_test_attachment_url, $af_test_attachment_file, $af_test_attachment_mime;
		$af_test_attachment_url  = 'https://example.com/wp-content/uploads/cover.png';
		$af_test_attachment_file = $this->tmp_file;
		$af_test_attachment_mime = 'image/png';

		$this->mock_ok( '/assets/' . $asset_id . '/confirm', array( 'id' => $asset_id, 'status' => 'ready' ) );
		$this->mock_ok(
			'/public/assets',
			array(
				'id'        => $asset_id,
				'uploadUrl' => 'https://signed.example/upload/' . $asset_id,
			)
		);
		af_test_register_http_response(
			'signed.example/upload/' . $asset_id,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
	}

	// -------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------

	public function test_register_submenu_uses_view_capability(): void {
		$this->creatives->register_submenu();
		$pages = af_test_get_menu_pages();
		$this->assertSame( 'af_view_campaigns', $pages[0]['capability'] );
		$this->assertSame( 'af-creatives', $pages[0]['menu_slug'] );
	}

	// -------------------------------------------------------------------
	// handle_upload — gates
	// -------------------------------------------------------------------

	public function test_handle_upload_noop_without_nonce_field(): void {
		$this->creatives->handle_upload();

		global $af_test_redirects;
		$this->assertSame( array(), $af_test_redirects );
	}

	public function test_handle_upload_dies_on_invalid_nonce(): void {
		$_POST = array(
			'af_creative_upload_nonce' => 'bogus',
			'af_creative_name'         => 'Cover',
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Security check failed/' );

		$this->creatives->handle_upload();
	}

	public function test_handle_upload_dies_without_capability(): void {
		af_test_set_caps( array() );

		$_POST = array(
			'af_creative_upload_nonce' => 'test-nonce',
			'af_creative_name'         => 'Cover',
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Permission denied/' );

		$this->creatives->handle_upload();
	}

	public function test_handle_upload_errors_when_no_source_asset_provided(): void {
		af_test_set_caps( array( 'af_manage_campaigns' => true ) );

		$_POST = array(
			'af_creative_upload_nonce' => 'test-nonce',
			'af_creative_name'         => 'Cover',
		);

		try {
			$this->creatives->handle_upload();
			$this->fail( 'Expected the redirect to throw.' );
		} catch ( \RuntimeException $e ) {
			// redirect throw.
		}

		$this->assertSame( 'No source asset provided.', get_transient( 'af_creative_error' ) );

		global $af_test_redirects;
		$this->assertStringContainsString( 'page=af-creatives', $af_test_redirects[0]['url'] );
	}

	// -------------------------------------------------------------------
	// handle_upload — real pipeline via media-library attachment
	// -------------------------------------------------------------------

	public function test_handle_upload_runs_asset_pipeline_and_redirects_to_detail(): void {
		af_test_set_caps( array( 'af_manage_campaigns' => true ) );
		$this->arm_attachment_upload( 'asset_7' );

		$_POST = array(
			'af_creative_upload_nonce' => 'test-nonce',
			'af_creative_name'         => 'Summer Cover',
			'af_source_attachment_id'  => '7',
		);

		try {
			$this->creatives->handle_upload();
			$this->fail( 'Expected the redirect to throw.' );
		} catch ( \RuntimeException $e ) {
			// redirect throw.
		}

		// The pipeline hit create, the signed S3 URL, and confirm.
		$urls = array_column( af_test_get_http_requests(), 'url' );
		$this->assertNotEmpty( preg_grep( '#/public/assets$#', $urls ), 'Asset create must be called.' );
		$this->assertNotEmpty( preg_grep( '#signed\.example/upload/asset_7#', $urls ), 'Signed S3 PUT must be called.' );
		$this->assertNotEmpty( preg_grep( '#/assets/asset_7/confirm$#', $urls ), 'Confirm must be called.' );

		$this->assertSame( 'Creative asset uploaded successfully.', get_transient( 'af_creative_success' ) );

		global $af_test_redirects;
		$this->assertStringContainsString( 'creative_id=asset_7', $af_test_redirects[0]['url'] );
	}

	// -------------------------------------------------------------------
	// fetch_creative caching
	// -------------------------------------------------------------------

	public function test_fetch_creative_does_not_cache_processing_status(): void {
		$this->mock_ok( '/assets/asset_42', array( 'id' => 'asset_42', 'status' => 'processing' ) );
		$this->mock_ok( '/assets/asset_42/platform-status', array() );

		$this->creatives->fetch_creative( 'asset_42' );

		$cache_key = $this->cache->build_key( 'creatives', 'asset_42' );
		$this->assertNull( $this->cache->get( $cache_key ), 'Processing creatives must not be cached.' );
	}

	public function test_fetch_creative_caches_complete_status(): void {
		$this->mock_ok( '/assets/asset_99', array( 'id' => 'asset_99', 'status' => 'ready' ) );
		$this->mock_ok( '/assets/asset_99/platform-status', array() );

		$this->creatives->fetch_creative( 'asset_99' );

		$cache_key = $this->cache->build_key( 'creatives', 'asset_99' );
		$this->assertNotNull( $this->cache->get( $cache_key ), 'Complete creatives should be cached.' );
	}

	public function test_fetch_creative_returns_empty_on_error(): void {
		$this->mock_status_404( '/assets/missing' );
		$this->assertSame( array(), $this->creatives->fetch_creative( 'missing' ) );
	}

	public function test_fetch_creative_carries_platform_status_rows(): void {
		$this->mock_ok( '/assets/asset_5/platform-status', array(
			array( 'platform' => 'meta', 'status' => 'uploaded' ),
		) );
		$this->mock_ok( '/assets/asset_5', array( 'id' => 'asset_5', 'status' => 'ready' ) );

		$creative = $this->creatives->fetch_creative( 'asset_5' );

		$meta_rows = array_values(
			array_filter(
				$creative['formats'] ?? array(),
				static fn( array $row ): bool => 'meta' === ( $row['platform'] ?? '' )
			)
		);
		$this->assertSame( 'uploaded', $meta_rows[0]['status'] );
	}

	// -------------------------------------------------------------------
	// fetch_creatives list
	// -------------------------------------------------------------------

	public function test_fetch_creatives_returns_empty_on_error(): void {
		$this->mock_status_404( '/assets' );
		$this->assertSame( array(), $this->creatives->fetch_creatives() );
	}

	// -------------------------------------------------------------------
	// Removed machinery — fabricated formats / approvals / regenerate
	// -------------------------------------------------------------------

	public function test_fabricated_format_and_approval_machinery_is_gone(): void {
		$this->assertFalse( method_exists( Creatives::class, 'handle_approval' ), 'The local approval workflow gated nothing and must stay removed.' );
		$this->assertFalse( method_exists( Creatives::class, 'handle_regenerate' ), 'Regenerate just re-uploaded the source and must stay removed.' );
		$this->assertFalse( method_exists( Creatives::class, 'get_format_label' ), 'Fabricated per-platform format labels must stay removed.' );
		$this->assertFalse( method_exists( Creatives::class, 'get_preview_frame_class' ), 'Fabricated preview mockup frames must stay removed.' );
	}
}
