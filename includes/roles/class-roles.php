<?php
/**
 * WordPress role and capability registration for Bushido Admin.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Roles;

/**
 * WordPress role and capability registration for Bushido Admin.
 *
 * Manages creation of the bushido_admin role with appropriate capabilities
 * and provides WP-to-Bushido role mapping via the af_role_mapping option.
 */
class Roles {

	/**
	 * Default WP-to-Bushido role mapping.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_MAPPING = array(
		'administrator' => 'bushido_admin',
		'bushido_admin' => 'bushido_admin',
		'editor'        => 'manager',
		'author'        => 'viewer',
		'contributor'   => 'viewer',
		'subscriber'    => 'viewer',
	);

	/**
	 * All capabilities managed by the plugin.
	 *
	 * @var string[]
	 */
	private const CAPABILITIES = array(
		'af_manage_campaigns',
		'af_manage_settings',
		'af_manage_accounts',
		'af_view_campaigns',
	);

	/**
	 * Register the bushido_admin role and grant capabilities to administrators.
	 *
	 * Called during plugin activation via Activator.
	 *
	 * @return void
	 */
	public static function register_roles(): void {
		// Create the bushido_admin custom role.
		add_role(
			'bushido_admin',
			__( 'Bushido Admin', 'bushido-almost-famous' ),
			array(
				'read'                => true,
				'af_manage_campaigns' => true,
				'af_manage_settings'  => true,
				'af_manage_accounts'  => true,
				'af_view_campaigns'   => true,
			)
		);

		// Grant all AF capabilities to WP administrators.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			foreach ( self::CAPABILITIES as $cap ) {
				$admin_role->add_cap( $cap );
			}
		}

		// Grant view capability to editors.
		$editor_role = get_role( 'editor' );
		if ( $editor_role ) {
			$editor_role->add_cap( 'af_view_campaigns' );
		}

		// Set default role mapping if not already set.
		if ( false === get_option( 'af_role_mapping' ) ) {
			update_option( 'af_role_mapping', self::DEFAULT_MAPPING );
		}
	}

	/**
	 * Remove custom roles and capabilities on uninstall.
	 *
	 * @return void
	 */
	public static function unregister_roles(): void {
		remove_role( 'bushido_admin' );

		// Remove capabilities from all standard roles.
		$standard_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		foreach ( $standard_roles as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role ) {
				foreach ( self::CAPABILITIES as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}

		delete_option( 'af_role_mapping' );
	}

	/**
	 * Get the current WP-to-Bushido role mapping.
	 *
	 * @return array<string, string> Associative array of WP role slug => Bushido role name.
	 */
	public static function get_role_mapping(): array {
		$mapping = get_option( 'af_role_mapping', array() );

		if ( ! is_array( $mapping ) || empty( $mapping ) ) {
			return self::DEFAULT_MAPPING;
		}

		return $mapping;
	}

	/**
	 * Update the WP-to-Bushido role mapping.
	 *
	 * @param array<string, string> $mapping Associative array of WP role slug => Bushido role name.
	 * @return bool True on success.
	 */
	public static function update_role_mapping( array $mapping ): bool {
		// Sanitize mapping values.
		$allowed_bushido_roles = array( 'bushido_admin', 'manager', 'viewer' );
		$sanitized             = array();

		foreach ( $mapping as $wp_role => $bushido_role ) {
			$wp_role_clean      = sanitize_key( $wp_role );
			$bushido_role_clean = sanitize_key( $bushido_role );

			if ( in_array( $bushido_role_clean, $allowed_bushido_roles, true ) ) {
				$sanitized[ $wp_role_clean ] = $bushido_role_clean;
			}
		}

		return update_option( 'af_role_mapping', $sanitized );
	}

	/**
	 * Map a WordPress role slug to its corresponding Bushido role name.
	 *
	 * @param string $wp_role WordPress role slug.
	 * @return string Bushido role name, defaults to 'viewer' if no mapping exists.
	 */
	public static function map_wp_role_to_bushido( string $wp_role ): string {
		$mapping = self::get_role_mapping();
		return $mapping[ $wp_role ] ?? 'viewer';
	}

	/**
	 * Get all plugin capabilities.
	 *
	 * @return string[] Array of capability strings.
	 */
	public static function get_capabilities(): array {
		return self::CAPABILITIES;
	}
}
