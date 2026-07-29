<?php
/**
 * Tests for the multi-step Setup_Wizard controller.
 *
 * Covers:
 *   - Step transitions (1 -> 2 -> 3) via process_api_key_step
 *   - API-key validation against /auth/validate (200 -> step 3, 401 -> error redirect)
 *   - Failure cleanup (invalid key removed on connection_failed)
 *   - Activation redirect transient one-shot
 *   - Error-code -> message mapping
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Setup_Wizard;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		global $af_test_doing_ajax;
		return ! empty( $af_test_doing_ajax );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		global $af_test_doing_cron;
		return ! empty( $af_test_doing_cron );
	}
}

/**
 * Tests Setup_Wizard form submissions, redirects, and validation.
 */
class Test_Setup_Wizard extends TestCase {

	private Api_Auth $auth;
	private Api_Client $client;
	private Setup_Wizard $wizard;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_POST = array();
		$_GET  = array();

		global $af_test_doing_ajax, $af_test_doing_cron, $af_test_redirect_throws;
		$af_test_doing_ajax     = false;
		$af_test_doing_cron     = false;
		$af_test_redirect_throws = true;

		$this->auth   = new Api_Auth();
		$this->client = new Api_Client( $this->auth );
		$this->wizard = new Setup_Wizard( $this->auth, $this->client );
	}

	protected function tearDown(): void {
		global $af_test_redirect_throws;
		$af_test_redirect_throws = false;
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	private function last_redirect(): string {
		global $af_test_redirects;
		$last = end( $af_test_redirects );
		return is_array( $last ) ? (string) $last['url'] : '';
	}

	/**
	 * Helper that invokes the private step-2 handler. The global
	 * $af_test_redirect_throws makes wp_safe_redirect throw a tagged
	 * RuntimeException so the source's `exit;` is never reached.
	 */
	private function process_step_2_directly(): void {
		$method = new \ReflectionMethod( $this->wizard, 'process_api_key_step' );
		try {
			$method->invoke( $this->wizard );
		} catch ( \RuntimeException $e ) {
			// Expected — wp_safe_redirect threw to short-circuit exit().
		}
	}

	public function test_get_current_step_clamps_to_range(): void {
		$_GET['step'] = '5';
		$this->assertSame( 3, $this->wizard->get_current_step() );

		$_GET['step'] = '-2';
		$this->assertSame( 1, $this->wizard->get_current_step() );

		unset( $_GET['step'] );
		$this->assertSame( 1, $this->wizard->get_current_step() );
	}

	public function test_register_page_uses_manage_settings_capability(): void {
		$this->wizard->register_page();
		$pages = af_test_get_menu_pages();
		$this->assertNotEmpty( $pages );
		$this->assertSame( 'af_manage_settings', $pages[0]['capability'] );
		$this->assertSame( Setup_Wizard::PAGE_SLUG, $pages[0]['menu_slug'] );
	}

	public function test_step_2_links_to_production_signup_and_api_keys(): void {
		$_GET['step'] = '2';

		ob_start();
		$this->wizard->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://bushido.is/signup?', $html );
		$this->assertStringContainsString( 'https://bushido.is/almost-famous/settings?tab=apikeys', $html );
		$this->assertStringNotContainsString( 'staging.bushido.is/', $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_step_2_links_honor_app_url_override(): void {
		define( 'AF_API_BASE_URL', 'https://api.example.test/api/v1' );
		define( 'AF_BUSHIDO_APP_URL', 'https://connect.example.test/custom/' );
		$_GET['step'] = '2';

		ob_start();
		$this->wizard->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://connect.example.test/custom/signup?', $html );
		$this->assertStringContainsString( 'https://connect.example.test/custom/almost-famous/settings?tab=apikeys', $html );
		$this->assertStringNotContainsString( 'staging.bushido.is/', $html );
	}

	public function test_passes_ssl_check_honours_filter(): void {
		add_filter( 'almost_famous_passes_ssl_check', static fn( bool $v ): bool => false );
		$this->assertFalse( $this->wizard->passes_ssl_check() );
	}

	public function test_step_2_with_valid_key_redirects_to_step_3(): void {
		$_POST['af_api_key'] = 'bsh_validkey_12345';
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'valid' => true ) ),
				'headers'  => array(),
			)
		);

		$this->process_step_2_directly();

		$this->assertStringContainsString( 'step=3', $this->last_redirect() );
		$this->assertStringNotContainsString( 'af_error=', $this->last_redirect() );
		$this->assertNotEmpty( get_option( 'af_api_key', '' ) );
	}

	public function test_step_2_with_401_redirects_to_connection_failed_and_clears_key(): void {
		$_POST['af_api_key'] = 'bsh_badkey';
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 401 ),
				'body'     => wp_json_encode( array(
					'error' => array( 'code' => 'unauthorized', 'message' => 'bad key' ),
				) ),
				'headers'  => array(),
			)
		);

		$this->process_step_2_directly();

		$this->assertStringContainsString( 'af_error=connection_failed', $this->last_redirect() );
		$this->assertSame( '', (string) get_option( 'af_api_key', '' ), 'Invalid key must be cleared.' );
	}

	public function test_step_2_with_empty_key_redirects_with_empty_key_error(): void {
		$_POST['af_api_key'] = '';
		$this->process_step_2_directly();
		$this->assertStringContainsString( 'af_error=empty_key', $this->last_redirect() );
	}

	public function test_step_2_with_invalid_chars_redirects_with_format_error(): void {
		$_POST['af_api_key'] = 'has spaces & weird chars!';
		$this->process_step_2_directly();
		$this->assertStringContainsString( 'af_error=invalid_key_format', $this->last_redirect() );
	}

	public function test_get_error_message_returns_human_string_per_code(): void {
		$_GET['af_error'] = 'connection_failed';
		$this->assertStringContainsString( 'Could not connect', $this->wizard->get_error_message() );

		$_GET['af_error'] = 'empty_key';
		$this->assertStringContainsString( 'Bushido API key', $this->wizard->get_error_message() );

		$_GET['af_error'] = 'unknown_error';
		$this->assertSame( '', $this->wizard->get_error_message() );
	}

	public function test_activation_redirect_only_fires_once(): void {
		set_transient( 'af_activation_redirect', true, 60 );
		af_test_set_caps( array( 'af_manage_settings' => true ) );

		try {
			$this->wizard->maybe_redirect_to_wizard();
		} catch ( \RuntimeException $e ) {
			// Expected — redirect raised to skip exit().
		}

		$this->assertStringContainsString( 'page=' . Setup_Wizard::PAGE_SLUG, $this->last_redirect() );
		$this->assertFalse( get_transient( 'af_activation_redirect' ), 'Transient must be consumed.' );
	}

	public function test_activation_redirect_skipped_when_setup_complete(): void {
		update_option( 'af_setup_complete', true );
		set_transient( 'af_activation_redirect', true, 60 );

		$this->wizard->maybe_redirect_to_wizard();

		global $af_test_redirects;
		$this->assertSame( array(), $af_test_redirects );
		// Transient should still be there — we never reached the consume branch.
		$this->assertNotFalse( get_transient( 'af_activation_redirect' ) );
	}

	public function test_activation_redirect_skipped_during_ajax(): void {
		global $af_test_doing_ajax;
		$af_test_doing_ajax = true;
		set_transient( 'af_activation_redirect', true, 60 );

		$this->wizard->maybe_redirect_to_wizard();

		global $af_test_redirects;
		$this->assertSame( array(), $af_test_redirects );
	}

	public function test_get_connected_platforms_returns_empty_array_when_no_transient(): void {
		$this->assertSame( array(), $this->wizard->get_connected_platforms() );
	}

	public function test_get_connected_platforms_returns_transient_payload(): void {
		set_transient( 'af_wizard_platforms', array( array( 'id' => 'meta' ) ), 60 );
		$this->assertCount( 1, $this->wizard->get_connected_platforms() );
	}
}
