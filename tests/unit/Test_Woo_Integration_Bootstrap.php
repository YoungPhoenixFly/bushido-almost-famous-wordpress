<?php
/**
 * Tests the timing of Woo_Integration's sub-module bootstrap.
 *
 * WooCommerce fires `woocommerce_loaded` from `plugins_loaded` at priority -1,
 * while this plugin boots at the default priority 10. A plain
 * add_action( 'woocommerce_loaded', ... ) registered from register() therefore
 * binds to an action that has already fired and never runs, which silently
 * disables conversion tracking and the order attribution meta box.
 *
 * These tests run in separate processes because they declare the `WooCommerce`
 * sentinel class, whose absence Test_Woo_Integration relies on.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Commerce\Woo_Integration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class Test_Woo_Integration_Bootstrap extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	/**
	 * The real-world ordering: WooCommerce has already fired the action by the
	 * time the plugin registers, so the modules must be initialized inline.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_modules_initialize_when_woocommerce_loaded_already_fired(): void {
		require_once dirname( __DIR__ ) . '/stubs/WooCommerce.php';

		// WooCommerce fires this at plugins_loaded priority -1, before us.
		do_action( 'woocommerce_loaded' );

		$integration = new Woo_Integration();
		$integration->register();

		$this->assertSame(
			1,
			did_action( 'almost_famous/woo/initialized' ),
			'Sub-modules must initialize inline when woocommerce_loaded has already fired.'
		);
		// Conversion_Tracking is what hooks woocommerce_order_status_completed:
		// without it no conversion ever reaches the pixel.
		$this->assertNotNull( $integration->conversion_tracking() );
		$this->assertNotNull( $integration->attribution() );
	}

	/**
	 * If the action has not fired yet, registration must defer rather than
	 * initialize against a half-loaded WooCommerce.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_modules_defer_when_woocommerce_loaded_has_not_fired(): void {
		require_once dirname( __DIR__ ) . '/stubs/WooCommerce.php';

		$integration = new Woo_Integration();
		$integration->register();

		$this->assertSame(
			0,
			did_action( 'almost_famous/woo/initialized' ),
			'Sub-modules must not initialize before woocommerce_loaded fires.'
		);
		$this->assertNull( $integration->conversion_tracking() );
	}
}
