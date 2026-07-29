<?php
/**
 * Top-level and submenu registration.
 *
 * Centralizes admin menu registration and wires up the admin page
 * controllers (Dashboard, Admin_Notices, etc).
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Plugin;

/**
 * Top-level and submenu registration.
 *
 * Initializes all admin page controllers and registers their hooks.
 */
class Admin_Menu {

	/**
	 * Dashboard controller.
	 *
	 * @var Dashboard
	 */
	private Dashboard $dashboard;

	/**
	 * Admin notices controller.
	 *
	 * @var Admin_Notices
	 */
	private Admin_Notices $notices;

	/**
	 * Creatives controller.
	 *
	 * @var Creatives
	 */
	private Creatives $creatives;

	/**
	 * Audiences controller.
	 *
	 * @var Audiences
	 */
	private Audiences $audiences;

	/**
	 * Accounts controller.
	 *
	 * @var Accounts
	 */
	private Accounts $accounts;

	/**
	 * Settings controller.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Pixels controller.
	 *
	 * @var Pixels
	 */
	private Pixels $pixels;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client API client.
	 * @param Api_Cache  $cache  API cache.
	 */
	public function __construct( Api_Client $client, Api_Cache $cache ) {
		$this->dashboard = new Dashboard();
		$this->notices   = new Admin_Notices();
		$this->creatives = new Creatives( $client, $cache );
		$this->audiences = new Audiences( $client, $cache );
		$this->accounts  = new Accounts( $client );
		$this->settings  = new Settings( $client, $cache );
		$this->pixels    = new Pixels( $client, $cache );
	}

	/**
	 * Initialize all admin controllers and register their hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->dashboard->init();
		$this->notices->init();
		$this->creatives->init();
		$this->audiences->init();
		$this->accounts->init();
		$this->settings->init();
		$this->pixels->init();
	}

	/**
	 * Get the dashboard controller.
	 *
	 * @return Dashboard
	 */
	public function dashboard(): Dashboard {
		return $this->dashboard;
	}

	/**
	 * Get the admin notices controller.
	 *
	 * @return Admin_Notices
	 */
	public function notices(): Admin_Notices {
		return $this->notices;
	}
}
