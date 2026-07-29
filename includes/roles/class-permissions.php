<?php
/**
 * WP role to Bushido role mapping and capability checks.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Roles;

/**
 * WP role to Bushido role mapping and capability checks.
 *
 * Provides convenience methods for checking plugin-specific capabilities
 * and mapping the current user's WP role to a Bushido role.
 */
class Permissions {

	/**
	 * Check if the current user can manage campaigns.
	 *
	 * @return bool True if user has af_manage_campaigns or manage_options.
	 */
	public static function can_manage_campaigns(): bool {
		return current_user_can( 'af_manage_campaigns' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Check if the current user can manage settings.
	 *
	 * @return bool True if user has af_manage_settings or manage_options.
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( 'af_manage_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Check if the current user can view campaigns.
	 *
	 * @return bool True if user has af_view_campaigns, af_manage_campaigns, or manage_options.
	 */
	public static function can_view_campaigns(): bool {
		return current_user_can( 'af_view_campaigns' )
			|| current_user_can( 'af_manage_campaigns' )
			|| current_user_can( 'manage_options' );
	}

	/**
	 * Generic capability check for a Bushido Almost Famous capability.
	 *
	 * @param string $capability WordPress capability string (e.g. 'af_manage_campaigns').
	 * @return bool True if the current user has the given capability.
	 */
	public static function check_permission( string $capability ): bool {
		return current_user_can( $capability ) || current_user_can( 'manage_options' );
	}

	/**
	 * Get the Bushido role for the current WordPress user.
	 *
	 * Reads the user's WP roles, selects the primary one, and maps it
	 * to a Bushido role via the Roles class mapping.
	 *
	 * @return string Bushido role name (e.g. 'bushido_admin', 'manager', 'viewer').
	 */
	public static function get_current_bushido_role(): string {
		$user = wp_get_current_user();

		if ( 0 === $user->ID ) {
			return 'viewer';
		}

		// WordPress stores roles as an array; use the first one.
		$wp_roles = $user->roles;

		if ( empty( $wp_roles ) ) {
			return 'viewer';
		}

		$primary_role = reset( $wp_roles );
		return Roles::map_wp_role_to_bushido( $primary_role );
	}

	/**
	 * Check if the current user has at least one of the given capabilities.
	 *
	 * @param string[] $capabilities Array of capability strings.
	 * @return bool True if the user has at least one.
	 */
	public static function has_any_capability( array $capabilities ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		foreach ( $capabilities as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}
}
