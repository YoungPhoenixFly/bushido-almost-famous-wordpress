<?php
/**
 * Uninstall handler for Bushido Almost Famous plugin.
 *
 * Removes all plugin data from the WordPress database.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the Roles helper so we can strip the af_* capabilities it grants to
// the built-in roles (administrator/editor/…), not just delete bushido_admin.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Remove the bushido_admin role and revoke af_* capabilities from every
 * built-in role. Falls back to remove_role() if the class is unavailable.
 *
 * @return void
 */
function almost_famous_uninstall_cleanup_roles(): void {
	if ( class_exists( '\AlmostFamous\Roles\Roles' ) ) {
		\AlmostFamous\Roles\Roles::unregister_roles();
		return;
	}

	remove_role( 'bushido_admin' );
}

// Remove all af_ prefixed options.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'af_' ) . '%'
	)
);

// Remove all af_ prefixed transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_af_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_af_' ) . '%'
	)
);

// Remove the custom role and revoke granted capabilities from built-in roles.
almost_famous_uninstall_cleanup_roles();

// Multisite: network-level settings (network API key, site-override toggle)
// live in sitemeta, and each subsite carries its own af_* options.
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( 'af_' ) . '%',
			$wpdb->esc_like( '_site_transient_af_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_af_' ) . '%'
		)
	);

	$almost_famous_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $almost_famous_site_ids as $almost_famous_site_id ) {
		switch_to_blog( (int) $almost_famous_site_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'af_' ) . '%',
				$wpdb->esc_like( '_transient_af_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_af_' ) . '%'
			)
		);

		almost_famous_uninstall_cleanup_roles();

		restore_current_blog();
	}
}
