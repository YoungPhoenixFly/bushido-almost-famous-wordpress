<?php
/**
 * Test-only stub for Automattic\WooCommerce\Utilities\OrderUtil where
 * custom_orders_table_usage_is_enabled() always returns true. Used by
 * Test_Attribution to exercise the HPOS screen-ID branch.
 *
 * Loading this file is irreversible (classes cannot be redeclared), so each
 * test process must opt into the HPOS path before any other test loads the
 * legacy stub.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( OrderUtil::class ) ) {
	class OrderUtil {
		public static function custom_orders_table_usage_is_enabled(): bool {
			return true;
		}
	}
}
