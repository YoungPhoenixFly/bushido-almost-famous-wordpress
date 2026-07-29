<?php
/**
 * Tests for Admin_Notices controller.
 *
 * Covers:
 *   - check_platform_status reads degraded entries from transient
 *   - check_upgrade_required triggers an error notice when transient set
 *   - display_notices outputs proper HTML markup and filter pass-through
 *   - handle_dismiss requires permissions and writes the dismissal meta
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Admin_Notices;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( string $class, string $fallback = '' ): string {
		$class = preg_replace( '/[^A-Za-z0-9_\-]/', '', $class ) ?? '';
		return '' === $class ? $fallback : $class;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		global $af_test_inline_scripts;
		$af_test_inline_scripts[] = compact( 'handle', 'data', 'position' );
		return true;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action = '', string $field = '_wpnonce', bool $die = true ): int {
		return 1;
	}
}

class Test_Admin_Notices extends TestCase {

	private Admin_Notices $notices;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_POST = array();
		$_GET  = array();

		global $af_test_inline_scripts;
		$af_test_inline_scripts = array();

		$this->notices = new Admin_Notices();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// check_platform_status
	// -------------------------------------------------------------------

	public function test_check_platform_status_noop_without_transient(): void {
		$this->notices->check_platform_status();
		ob_start();
		$this->notices->display_notices();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_check_platform_status_adds_warning_for_degraded(): void {
		set_transient(
			'af_platform_status',
			array(
				array( 'name' => 'Meta', 'status' => 'degraded' ),
				array( 'name' => 'TikTok', 'status' => 'ok' ),
			),
			60
		);
		$this->notices->check_platform_status();

		ob_start();
		$this->notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Meta', $html );
		$this->assertStringNotContainsString( 'TikTok', $html, 'Only degraded platforms should be in the notice.' );
	}

	public function test_check_platform_status_skips_when_dismissed(): void {
		set_transient(
			'af_platform_status',
			array( array( 'name' => 'Meta', 'status' => 'degraded' ) ),
			60
		);

		// Pre-derive the notice ID just like the source.
		$notice_id = 'platform_degraded_' . md5( 'Meta' );
		update_user_meta( get_current_user_id(), 'af_dismissed_notice_' . $notice_id, time() );

		$this->notices->check_platform_status();
		ob_start();
		$this->notices->display_notices();
		$this->assertSame( '', ob_get_clean() );
	}

	// -------------------------------------------------------------------
	// check_upgrade_required
	// -------------------------------------------------------------------

	public function test_check_upgrade_required_renders_error_notice(): void {
		set_transient(
			'af_upgrade_required',
			array(
				'minimum_version' => '2.0.0',
				'message'         => 'Please update.',
			),
			60
		);

		$this->notices->check_upgrade_required();
		ob_start();
		$this->notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Please update.', $html );
	}

	public function test_check_upgrade_required_uses_default_message_when_blank(): void {
		set_transient(
			'af_upgrade_required',
			array( 'minimum_version' => '2.0.0' ),
			60
		);

		$this->notices->check_upgrade_required();
		ob_start();
		$this->notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( '2.0.0', $html );
	}

	public function test_check_upgrade_required_skips_when_dismissed(): void {
		set_transient( 'af_upgrade_required', array( 'message' => 'x' ), 60 );
		update_user_meta( get_current_user_id(), 'af_dismissed_notice_upgrade_required', time() );

		$this->notices->check_upgrade_required();
		ob_start();
		$this->notices->display_notices();
		$this->assertSame( '', ob_get_clean() );
	}

	// -------------------------------------------------------------------
	// dismiss state
	// -------------------------------------------------------------------

	public function test_display_notices_emits_dismissible_class_when_flagged(): void {
		$this->notices->add_notice( 'n1', 'info', 'Hello', true );

		ob_start();
		$this->notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'data-af-notice-id="n1"', $html );
		$this->assertStringContainsString( 'Hello', $html );
	}

	public function test_display_notices_passes_through_filter(): void {
		add_filter(
			'almost_famous/admin/notices',
			static fn( array $list ): array => array_merge(
				$list,
				array(
					array(
						'id'          => 'added_via_filter',
						'type'        => 'success',
						'message'     => 'Filtered in',
						'dismissible' => false,
					),
				)
			)
		);

		ob_start();
		$this->notices->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'added_via_filter', $html );
	}

	// -------------------------------------------------------------------
	// handle_dismiss
	// -------------------------------------------------------------------

	public function test_handle_dismiss_writes_user_meta(): void {
		af_test_set_caps( array( 'af_view_campaigns' => true ) );

		$_POST = array( 'nonce' => 'test-nonce', 'notice_id' => 'foo-bar' );

		try {
			$this->notices->handle_dismiss();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'wp_send_json', $e->getMessage() );
			$this->assertStringContainsString( 'foo-bar', $e->getMessage() );
		}

		$this->assertNotEmpty(
			get_user_meta( get_current_user_id(), 'af_dismissed_notice_foo-bar', true )
		);
	}

	public function test_handle_dismiss_rejects_unauthorized_user(): void {
		af_test_set_caps( array() );

		$_POST = array( 'nonce' => 'test-nonce', 'notice_id' => 'x' );

		try {
			$this->notices->handle_dismiss();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
		}
	}

	public function test_handle_dismiss_rejects_missing_notice_id(): void {
		af_test_set_caps( array( 'af_view_campaigns' => true ) );

		$_POST = array( 'nonce' => 'test-nonce', 'notice_id' => '' );

		try {
			$this->notices->handle_dismiss();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Missing notice ID', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------
	// dismiss script enqueue
	// -------------------------------------------------------------------

	public function test_enqueue_dismiss_script_attaches_inline_on_af_pages(): void {
		$_GET['page'] = 'bushido-almost-famous';
		$this->notices->enqueue_dismiss_script();

		global $af_test_inline_scripts;
		$this->assertNotEmpty( $af_test_inline_scripts );
		$this->assertSame( 'common', $af_test_inline_scripts[0]['handle'] );
		$this->assertStringContainsString( 'af-admin-notice', $af_test_inline_scripts[0]['data'] );
	}

	public function test_enqueue_dismiss_script_skips_non_af_pages_without_notices(): void {
		$_GET['page'] = 'edit.php';
		$this->notices->enqueue_dismiss_script();

		global $af_test_inline_scripts;
		$this->assertSame( array(), $af_test_inline_scripts );
	}
}
