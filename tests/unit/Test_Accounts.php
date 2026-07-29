<?php
/**
 * Tests for the Accounts admin controller.
 *
 * Covers menu registration, the OAuth platform-connection reconciliation
 * with the Bushido backend, and the rendered "Platform Connections" table.
 *
 * The OAuth Connect/Disconnect endpoints themselves are covered by
 * Test_Oauth_Controller — this file only verifies the page picks up
 * platform-account data from the `af_accounts` option.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Accounts;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql', int $gmt = 0 ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, ?string $domain = null ): void {
		echo esc_attr( $text );
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {
		echo '<input type="submit" name="' . esc_attr( $name ) . '" value="' . esc_attr( $text ) . '" />';
	}
}

class Test_Accounts extends TestCase {

	private Accounts $accounts;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_POST = array();
		$_GET  = array();

		global $af_test_redirect_throws;
		$af_test_redirect_throws = true;

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test' ) );

		$this->accounts = new Accounts( new Api_Client( $auth ) );
	}

	protected function tearDown(): void {
		global $af_test_redirect_throws;
		$af_test_redirect_throws = false;
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------

	public function test_register_page_uses_manage_accounts_capability(): void {
		$this->accounts->register_page();
		$pages = af_test_get_menu_pages();
		$this->assertSame( 'af_manage_accounts', $pages[0]['capability'] );
		$this->assertSame( Accounts::PAGE_SLUG, $pages[0]['menu_slug'] );
	}

	// -------------------------------------------------------------------
	// Platform reconciliation from /auth/connections
	// -------------------------------------------------------------------

	public function test_reconcile_overwrites_local_state_from_backend_credentials(): void {
		af_test_set_caps( array( 'af_manage_accounts' => true ) );
		update_option(
			'af_accounts',
			array(
				'meta' => array( 'credentialId' => 'old-cred', 'accountId' => 'old', 'accountName' => 'Stale Page', 'status' => 'active', 'connectedAt' => 100 ),
			)
		);
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'credentials' => array(
						array( 'id' => 'cred-new', 'platform' => 'META', 'accountId' => 'act_new', 'accountName' => 'Fresh Page', 'status' => 'active' ),
						array( 'id' => 'cred-tt', 'platform' => 'TIKTOK', 'accountId' => 'act_tt', 'accountName' => 'TT Account', 'status' => 'active' ),
					),
				) ),
				'headers'  => array(),
			)
		);

		$reconciled = $this->accounts->reconcile_platform_accounts();

		$this->assertArrayHasKey( 'meta', $reconciled );
		$this->assertSame( 'cred-new', $reconciled['meta']['credentialId'] );
		$this->assertSame( 'Fresh Page', $reconciled['meta']['accountName'] );
		$this->assertArrayHasKey( 'tiktok', $reconciled );
		$this->assertSame( $reconciled, get_option( 'af_accounts' ) );
	}

	public function test_reconcile_preserves_local_state_on_empty_backend_response(): void {
		af_test_set_caps( array( 'af_manage_accounts' => true ) );
		$local = array(
			'meta' => array( 'credentialId' => 'local-cred', 'accountId' => 'local', 'accountName' => 'Local Page', 'status' => 'active', 'connectedAt' => 0 ),
		);
		update_option( 'af_accounts', $local );
		// No HTTP mock registered → default `{}` response. Local cache must stay.

		$reconciled = $this->accounts->reconcile_platform_accounts();

		$this->assertSame( $local, $reconciled );
		$this->assertSame( $local, get_option( 'af_accounts' ) );
	}

	// -------------------------------------------------------------------
	// render() — Platform Connections table
	// -------------------------------------------------------------------

	public function test_render_reads_platform_accounts_from_af_accounts_option(): void {
		af_test_set_caps( array( 'af_manage_accounts' => true ) );
		update_option(
			'af_accounts',
			array(
				'meta' => array( 'credentialId' => 'cred-1', 'accountId' => 'act_42', 'accountName' => 'My Page', 'status' => 'active' ),
			)
		);

		ob_start();
		try {
			$this->accounts->render();
		} catch ( \Throwable $e ) {
			// render() prints PHP and does not exit; protect against unexpected throws.
		}
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Platform Connections', $html );
		$this->assertStringContainsString( 'My Page', $html );
		$this->assertStringContainsString( 'data-platform="meta"', $html );
	}

	public function test_render_shows_disconnected_label_when_no_platform_linked(): void {
		af_test_set_caps( array( 'af_manage_accounts' => true ) );
		update_option( 'af_accounts', array() );

		ob_start();
		try {
			$this->accounts->render();
		} catch ( \Throwable $e ) {}
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Not connected', $html );
		$this->assertStringContainsString( 'Connect', $html );
	}

	public function test_render_dies_without_capability(): void {
		af_test_set_caps( array() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unauthorized/' );

		$this->accounts->render();
	}
}
