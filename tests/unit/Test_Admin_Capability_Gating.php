<?php
/**
 * Tests that every Bushido Almost Famous admin page is gated on an almost_famous_* capability
 * (with manage_options fallback) instead of bare manage_options.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Admin\Accounts;
use AlmostFamous\Admin\Audiences;
use AlmostFamous\Admin\Creatives;
use AlmostFamous\Admin\Dashboard;
use AlmostFamous\Admin\Settings;
use AlmostFamous\Admin\Setup_Wizard;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the menu-registration capability for each admin page.
 */
class Test_Admin_Capability_Gating extends TestCase {

	private Api_Client $api;
	private Api_Cache $cache;
	private Api_Auth $auth;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$this->auth  = new Api_Auth();
		$this->api   = new Api_Client( $this->auth );
		$this->cache = new Api_Cache();
	}

	/**
	 * @param string $menu_slug Slug to find.
	 * @return array<string,mixed>|null
	 */
	private function find_page( string $menu_slug ): ?array {
		foreach ( af_test_get_menu_pages() as $entry ) {
			if ( $entry['menu_slug'] === $menu_slug ) {
				return $entry;
			}
		}
		return null;
	}

	public function test_dashboard_uses_almost_famous_view_campaigns(): void {
		( new Dashboard() )->register_page();

		$entry = $this->find_page( Dashboard::PAGE_SLUG );
		$this->assertNotNull( $entry, 'Dashboard page must register.' );
		$this->assertSame( 'almost_famous_view_campaigns', $entry['capability'] );
	}

	public function test_audiences_uses_almost_famous_view_campaigns(): void {
		( new Audiences( $this->api, $this->cache ) )->register_submenu();
		$entry = $this->find_page( 'almost-famous-audiences' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'almost_famous_view_campaigns', $entry['capability'] );
	}

	public function test_creatives_uses_almost_famous_view_campaigns(): void {
		( new Creatives( $this->api, $this->cache ) )->register_submenu();
		$entry = $this->find_page( 'almost-famous-creatives' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'almost_famous_view_campaigns', $entry['capability'] );
	}

	public function test_settings_uses_almost_famous_manage_settings(): void {
		( new Settings( $this->api ) )->register_page();
		$entry = $this->find_page( Settings::PAGE_SLUG );
		$this->assertNotNull( $entry );
		$this->assertSame( 'almost_famous_manage_settings', $entry['capability'] );
	}

	public function test_setup_wizard_uses_almost_famous_manage_settings(): void {
		( new Setup_Wizard( $this->auth, $this->api ) )->register_page();
		$entry = $this->find_page( Setup_Wizard::PAGE_SLUG );
		$this->assertNotNull( $entry );
		$this->assertSame( 'almost_famous_manage_settings', $entry['capability'] );
	}

	public function test_accounts_uses_almost_famous_manage_accounts(): void {
		( new Accounts( $this->api ) )->register_page();
		$entry = $this->find_page( Accounts::PAGE_SLUG );
		$this->assertNotNull( $entry );
		$this->assertSame( 'almost_famous_manage_accounts', $entry['capability'] );
	}
}
