<?php
/**
 * Plugin deactivation handler.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous;

/**
 * Handles plugin deactivation — cleanup transients.
 */
class Deactivator {

	/**
	 * Run deactivation cleanup.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::cleanup_transients();

		/**
		 * Fires after the Bushido Almost Famous plugin is deactivated.
		 */
		do_action( 'almost_famous/plugin/deactivated' );
	}

	/**
	 * Remove all af_ prefixed transients.
	 *
	 * @return void
	 */
	private static function cleanup_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_af_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_af_' ) . '%'
			)
		);
	}
}
