<?php
/**
 * Tests Admin_Menu wires up every admin page controller and that registering
 * them via init() reaches the underlying add_menu_page / add_submenu_page calls
 * with the right slugs and capabilities.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Admin\Accounts;
use AlmostFamous\Admin\Admin_Menu;
use AlmostFamous\Admin\Dashboard;
use AlmostFamous\Admin\Settings;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Admin_Menu composes child controllers and registers the
 * full menu surface (parent + every submenu) under the correct slugs.
 */
class Test_Admin_Menu extends TestCase {

	private Api_Client $client;
	private Api_Cache $cache;
	private Admin_Menu $menu;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$this->client = new Api_Client( new Api_Auth() );
		$this->cache  = new Api_Cache();
		$this->menu   = new Admin_Menu( $this->client, $this->cache );
	}

	/**
	 * Trigger every child controller's menu registration the way init() would.
	 */
	private function register_all_menus(): void {
		$this->menu->dashboard()->register_page();
		// Notices has no page registration. Creatives, audiences, accounts,
		// settings each expose register_submenu / register_page.
		( new \ReflectionMethod( $this->menu->dashboard(), 'register_page' ) );
		// Access the private collaborators by mirroring Admin_Menu::init().
		$prop = new \ReflectionClass( $this->menu );
		foreach ( array( 'creatives', 'audiences' ) as $key ) {
			$slot = $prop->getProperty( $key );
			$slot->getValue( $this->menu )->register_submenu();
		}
		$accounts = $prop->getProperty( 'accounts' );
		$accounts->getValue( $this->menu )->register_page();

		$settings = $prop->getProperty( 'settings' );
		$settings->getValue( $this->menu )->register_page();
	}

	public function test_constructor_wires_all_controllers(): void {
		$this->assertInstanceOf( Dashboard::class, $this->menu->dashboard() );
		$this->assertNotNull( $this->menu->notices() );
	}

	public function test_top_level_menu_registers_dashboard_slug(): void {
		$this->menu->dashboard()->register_page();

		$top_level = array_values(
			array_filter(
				af_test_get_menu_pages(),
				static fn( array $entry ): bool => 'top' === $entry['type']
			)
		);

		$this->assertCount( 1, $top_level );
		$this->assertSame( Dashboard::PAGE_SLUG, $top_level[0]['menu_slug'] );
		$this->assertSame( 'af_view_campaigns', $top_level[0]['capability'] );
	}

	public function test_register_all_submenus_under_almost_famous_parent(): void {
		$this->register_all_menus();

		$submenus = array_values(
			array_filter(
				af_test_get_menu_pages(),
				static fn( array $entry ): bool => 'sub' === $entry['type'] && 'bushido-almost-famous' === $entry['parent_slug']
			)
		);

		$slugs = array_map( static fn( array $e ): string => $e['menu_slug'], $submenus );

		// Dashboard registers itself as a submenu alias of the top-level.
		$this->assertContains( Dashboard::PAGE_SLUG, $slugs );
		$this->assertContains( 'af-creatives', $slugs );
		$this->assertContains( 'af-audiences', $slugs );
		$this->assertContains( Accounts::PAGE_SLUG, $slugs );
		$this->assertContains( Settings::PAGE_SLUG, $slugs );
	}

	public function test_settings_submenu_uses_manage_settings_capability(): void {
		$this->register_all_menus();

		$settings_entry = null;
		foreach ( af_test_get_menu_pages() as $entry ) {
			if ( Settings::PAGE_SLUG === $entry['menu_slug'] ) {
				$settings_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $settings_entry );
		$this->assertSame( 'af_manage_settings', $settings_entry['capability'] );
	}

	public function test_accounts_submenu_uses_manage_accounts_capability(): void {
		$this->register_all_menus();

		$accounts_entry = null;
		foreach ( af_test_get_menu_pages() as $entry ) {
			if ( Accounts::PAGE_SLUG === $entry['menu_slug'] ) {
				$accounts_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $accounts_entry );
		$this->assertSame( 'af_manage_accounts', $accounts_entry['capability'] );
	}

	public function test_dashboard_returns_same_instance_on_repeated_call(): void {
		$first  = $this->menu->dashboard();
		$second = $this->menu->dashboard();
		$this->assertSame( $first, $second );
	}

	public function test_admin_menu_init_registers_no_pages_immediately(): void {
		// init() only attaches add_action callbacks (the stub returns true).
		// Calling it must not push any pages into the menu list until the
		// callbacks fire — register_all_menus simulates the firing.
		$this->menu->init();
		$this->assertSame( array(), af_test_get_menu_pages() );
	}
}
