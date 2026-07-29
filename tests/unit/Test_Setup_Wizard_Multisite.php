<?php
/**
 * Multisite scoping tests for the Setup_Wizard.
 *
 * The wizard's completion flag (`af_setup_complete`) is a per-site option:
 * every subsite runs its own connect flow and mints its own per-site API
 * key. A network-level (site option) flag must never suppress a subsite's
 * wizard, and one subsite completing setup must not mark the network done.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Setup_Wizard;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests Setup_Wizard behavior on multisite installs.
 */
class Test_Setup_Wizard_Multisite extends TestCase {

	private Setup_Wizard $wizard;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_POST = array();
		$_GET  = array();

		global $af_test_doing_ajax, $af_test_doing_cron, $af_test_redirect_throws;
		$af_test_doing_ajax      = false;
		$af_test_doing_cron      = false;
		$af_test_redirect_throws = true;

		af_test_set_multisite( true );

		$auth         = new Api_Auth();
		$this->wizard = new Setup_Wizard( $auth, new Api_Client( $auth ) );
	}

	protected function tearDown(): void {
		global $af_test_redirect_throws;
		$af_test_redirect_throws = false;
		af_test_set_multisite( false );
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	public function test_network_level_flag_does_not_suppress_subsite_wizard(): void {
		// A network admin completing setup elsewhere writes a SITE option,
		// never the per-site flag this subsite consults.
		update_site_option( 'af_setup_complete', true );
		set_transient( 'af_activation_redirect', true, 60 );
		af_test_set_caps( array( 'af_manage_settings' => true ) );

		try {
			$this->wizard->maybe_redirect_to_wizard();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_safe_redirect threw to short-circuit exit().
		}

		global $af_test_redirects;
		$this->assertNotEmpty(
			$af_test_redirects,
			'A subsite without its own af_setup_complete must still get the wizard.'
		);
		$this->assertStringContainsString(
			'page=' . Setup_Wizard::PAGE_SLUG,
			(string) $af_test_redirects[0]['url']
		);
	}

	public function test_per_site_flag_suppresses_wizard_on_multisite(): void {
		update_option( 'af_setup_complete', true );
		set_transient( 'af_activation_redirect', true, 60 );
		af_test_set_caps( array( 'af_manage_settings' => true ) );

		$this->wizard->maybe_redirect_to_wizard();

		global $af_test_redirects;
		$this->assertSame( array(), $af_test_redirects );
	}

	public function test_subsite_completion_does_not_touch_network_options(): void {
		af_test_set_caps( array( 'af_manage_settings' => true ) );

		$method = new \ReflectionMethod( $this->wizard, 'complete_wizard' );
		try {
			$method->invoke( $this->wizard );
		} catch ( \RuntimeException $e ) {
			// Expected — wp_safe_redirect threw to short-circuit exit().
		}

		$this->assertTrue( (bool) get_option( 'af_setup_complete' ) );
		$this->assertFalse(
			(bool) get_site_option( 'af_setup_complete' ),
			'Completing one subsite must not mark the whole network as set up.'
		);
	}
}
