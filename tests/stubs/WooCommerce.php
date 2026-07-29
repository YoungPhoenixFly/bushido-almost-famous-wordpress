<?php
/**
 * Sentinel stub for the WooCommerce main class.
 *
 * Woo_Integration::is_available() gates purely on `class_exists( 'WooCommerce' )`,
 * so declaring this class is what makes the integration treat WooCommerce as
 * active. Require it only from tests running in an isolated process —
 * Test_Woo_Integration asserts the opposite (inactive) state and shares the
 * default process.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * Minimal stand-in for WooCommerce's main class.
	 */
	class WooCommerce {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}
