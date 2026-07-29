<?php
/**
 * Tests for AlmostFamous\Roles\Roles activation / deactivation behavior and
 * the af_role_mapping option store.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Roles\Roles;
use PHPUnit\Framework\TestCase;

class Test_Roles extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	public function test_register_roles_creates_bushido_admin_with_all_caps(): void {
		Roles::register_roles();

		$role = get_role( 'bushido_admin' );
		$this->assertNotNull( $role );

		$this->assertTrue( $role->has_cap( 'read' ) );
		foreach ( Roles::get_capabilities() as $cap ) {
			$this->assertTrue(
				$role->has_cap( $cap ),
				"bushido_admin should have $cap"
			);
		}
	}

	public function test_register_roles_grants_all_caps_to_administrator(): void {
		// Pre-seed admin role.
		add_role( 'administrator', 'Administrator', array( 'read' => true ) );

		Roles::register_roles();

		$admin = get_role( 'administrator' );
		$this->assertNotNull( $admin );
		foreach ( Roles::get_capabilities() as $cap ) {
			$this->assertTrue(
				$admin->has_cap( $cap ),
				"administrator should have $cap after register_roles"
			);
		}
	}

	public function test_register_roles_grants_view_capability_to_editor(): void {
		add_role( 'editor', 'Editor', array( 'read' => true ) );

		Roles::register_roles();

		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$this->assertTrue( $editor->has_cap( 'af_view_campaigns' ) );
		$this->assertFalse(
			$editor->has_cap( 'af_manage_settings' ),
			'Editor should not get the manage settings cap.'
		);
	}

	public function test_register_roles_seeds_default_role_mapping_when_unset(): void {
		Roles::register_roles();

		$mapping = get_option( 'af_role_mapping' );
		$this->assertIsArray( $mapping );
		$this->assertSame( 'bushido_admin', $mapping['administrator'] );
		$this->assertSame( 'manager', $mapping['editor'] );
		$this->assertSame( 'viewer', $mapping['subscriber'] );
	}

	public function test_register_roles_does_not_overwrite_existing_mapping(): void {
		update_option(
			'af_role_mapping',
			array( 'administrator' => 'viewer' )
		);

		Roles::register_roles();

		$this->assertSame(
			array( 'administrator' => 'viewer' ),
			get_option( 'af_role_mapping' )
		);
	}

	public function test_unregister_roles_removes_role_and_revokes_caps(): void {
		add_role( 'administrator', 'Administrator', array( 'read' => true ) );
		add_role( 'editor', 'Editor', array( 'read' => true ) );

		Roles::register_roles();
		Roles::unregister_roles();

		$this->assertNull( get_role( 'bushido_admin' ) );

		$admin = get_role( 'administrator' );
		$this->assertNotNull( $admin );
		foreach ( Roles::get_capabilities() as $cap ) {
			$this->assertFalse(
				$admin->has_cap( $cap ),
				"administrator should not retain $cap after unregister"
			);
		}

		$editor = get_role( 'editor' );
		$this->assertFalse( $editor->has_cap( 'af_view_campaigns' ) );

		$this->assertFalse( get_option( 'af_role_mapping' ) );
	}

	public function test_get_role_mapping_falls_back_to_defaults_when_unset_or_invalid(): void {
		// Unset → defaults returned.
		$defaults = Roles::get_role_mapping();
		$this->assertSame( 'bushido_admin', $defaults['administrator'] );

		// Set to a non-array → defaults returned.
		update_option( 'af_role_mapping', 'not-an-array' );
		$this->assertSame( $defaults, Roles::get_role_mapping() );

		// Set to empty array → defaults returned.
		update_option( 'af_role_mapping', array() );
		$this->assertSame( $defaults, Roles::get_role_mapping() );
	}

	public function test_update_role_mapping_sanitizes_and_drops_invalid_targets(): void {
		Roles::update_role_mapping(
			array(
				'administrator' => 'bushido_admin',
				'editor'        => 'MANAGER',         // sanitized to lowercase
				'author'        => 'rogue',           // dropped
				'BadKey!'       => 'viewer',           // sanitized key
			)
		);

		$stored = get_option( 'af_role_mapping' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'bushido_admin', $stored['administrator'] );
		$this->assertSame( 'manager', $stored['editor'] );
		$this->assertArrayNotHasKey( 'author', $stored );
		$this->assertSame( 'viewer', $stored['badkey'] );
	}

	public function test_map_wp_role_to_bushido_uses_mapping_with_viewer_fallback(): void {
		update_option(
			'af_role_mapping',
			array(
				'administrator' => 'bushido_admin',
				'editor'        => 'manager',
			)
		);

		$this->assertSame( 'bushido_admin', Roles::map_wp_role_to_bushido( 'administrator' ) );
		$this->assertSame( 'manager', Roles::map_wp_role_to_bushido( 'editor' ) );
		$this->assertSame( 'viewer', Roles::map_wp_role_to_bushido( 'unknown_role' ) );
	}
}
