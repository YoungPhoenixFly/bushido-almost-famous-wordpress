<?php
/**
 * Tests for the Dashboard admin Home hub controller.
 *
 * Covers:
 *   - Page registers as a top-level menu and Home submenu alias
 *   - enqueue_assets only fires on the hub hook suffix
 *   - render() dies without capability
 *   - Not-connected hub shows the guided connect CTA
 *   - Connected hub shows config links + a way to reach the campaign console
 *   - Console page detection (create form vs. permalink link)
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Dashboard;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard hub controller test.
 */
class Test_Dashboard extends TestCase {

	private Dashboard $dashboard;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$this->dashboard = new Dashboard();
	}

	/**
	 * Capture render() output.
	 */
	private function render_to_string(): string {
		ob_start();
		$this->dashboard->render();
		return (string) ob_get_clean();
	}

	public function test_register_page_creates_top_level_and_submenu_entries(): void {
		$this->dashboard->register_page();

		$pages = af_test_get_menu_pages();
		$this->assertCount( 2, $pages, 'Hub registers one top-level and one submenu alias.' );
		$this->assertSame( 'top', $pages[0]['type'] );
		$this->assertSame( Dashboard::PAGE_SLUG, $pages[0]['menu_slug'] );
		$this->assertSame( 'af_view_campaigns', $pages[0]['capability'] );

		$this->assertSame( 'sub', $pages[1]['type'] );
		$this->assertSame( Dashboard::PAGE_SLUG, $pages[1]['menu_slug'] );
		$this->assertSame( Dashboard::PAGE_SLUG, $pages[1]['parent_slug'] );
		$this->assertSame( 'Home', $pages[1]['menu_title'] );
	}

	public function test_enqueue_assets_skips_other_hooks(): void {
		$this->dashboard->enqueue_assets( 'edit.php' );
		$this->assertNotContains(
			'af-admin',
			af_test_get_enqueued_styles(),
			'Should not enqueue on a non-hub hook.'
		);
	}

	public function test_enqueue_assets_runs_on_hub_hook(): void {
		$this->dashboard->enqueue_assets( 'toplevel_page_' . Dashboard::PAGE_SLUG );
		$this->assertContains( 'af-admin', af_test_get_enqueued_styles() );
	}

	public function test_render_dies_without_capability(): void {
		af_test_set_caps( array() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );

		$this->dashboard->render();
	}

	public function test_is_connected_false_when_setup_incomplete(): void {
		update_option( 'af_setup_complete', false );
		$this->assertFalse( $this->dashboard->is_connected() );
	}

	public function test_render_not_connected_shows_connect_cta(): void {
		update_option( 'af_setup_complete', false );

		$html = $this->render_to_string();

		$this->assertStringContainsString( 'Connect your Bushido account', $html );
		$this->assertStringContainsString( 'page=af-setup-wizard', $html );
		$this->assertStringContainsString( 'https://bushido.is/signup', $html );
		$this->assertStringNotContainsString( 'staging.bushido.is/signup', $html );
		$this->assertStringContainsString( 'utm_source=wordpress-plugin', $html );
		// Not-connected state must not show the console/config surface.
		$this->assertStringNotContainsString( 'Open campaign console', $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_not_connected_honors_app_url_override(): void {
		define( 'AF_API_BASE_URL', 'https://api.example.test/api/v1' );
		define( 'AF_BUSHIDO_APP_URL', 'https://connect.example.test/custom/' );
		update_option( 'af_setup_complete', false );

		$html = $this->render_to_string();

		$this->assertStringContainsString( 'https://connect.example.test/custom/signup?', $html );
		$this->assertStringNotContainsString( 'staging.bushido.is/signup', $html );
	}

	public function test_render_connected_shows_config_links(): void {
		update_option( 'af_setup_complete', true );
		update_option( 'af_api_key', 'stored-encrypted-key' );

		$html = $this->render_to_string();

		$this->assertStringContainsString( 'page=af-audiences', $html );
		$this->assertStringContainsString( 'page=af-creatives', $html );
		$this->assertStringContainsString( 'page=af-pixels', $html );
		$this->assertStringContainsString( 'page=af-accounts', $html );
		$this->assertStringContainsString( 'page=af-settings', $html );
		$this->assertStringContainsString( 'Conversions', $html );
		// Guided connect CTA must not appear once connected.
		$this->assertStringNotContainsString( 'Connect your Bushido account', $html );
	}

	public function test_render_connected_without_console_page_shows_create_form(): void {
		update_option( 'af_setup_complete', true );
		update_option( 'af_api_key', 'stored-encrypted-key' );

		$html = $this->render_to_string();

		$this->assertStringContainsString( 'Create console page', $html );
		$this->assertStringContainsString( 'action=af_create_console_page', $html );
		$this->assertStringNotContainsString( 'Open campaign console', $html );
	}

	public function test_render_connected_with_console_page_links_to_permalink(): void {
		update_option( 'af_setup_complete', true );
		update_option( 'af_api_key', 'stored-encrypted-key' );
		af_test_add_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Campaign Console',
				'post_content' => '[almost-famous-portal]',
			)
		);

		$html = $this->render_to_string();

		$this->assertStringContainsString( 'Open campaign console', $html );
		$this->assertStringNotContainsString( 'Create console page', $html );
	}

	public function test_get_console_url_detects_block_page(): void {
		af_test_add_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Console',
				'post_content' => '<!-- wp:almost-famous/portal /-->',
			)
		);

		$this->assertNotSame( '', $this->dashboard->get_console_url() );
	}

	public function test_get_console_url_empty_without_console_page(): void {
		af_test_add_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'About',
				'post_content' => 'Just a plain page.',
			)
		);

		$this->assertSame( '', $this->dashboard->get_console_url() );
	}

	public function test_is_degraded_true_when_platform_status_degraded(): void {
		set_transient(
			'af_platform_status',
			array( array( 'status' => 'degraded', 'name' => 'meta' ) ),
			60
		);
		$this->assertTrue( $this->dashboard->is_degraded() );
	}

	public function test_is_degraded_false_when_no_transient(): void {
		$this->assertFalse( $this->dashboard->is_degraded() );
	}
}
