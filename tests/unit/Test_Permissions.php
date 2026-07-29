<?php
/**
 * Tests Permissions cap checks honour the right af_* cap and fall back to
 * manage_options for administrators.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Roles\Permissions;
use AlmostFamous\Roles\Roles;
use PHPUnit\Framework\TestCase;

class Test_Permissions extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	// -----------------------------------------------------------------------
	// can_manage_campaigns()
	// -----------------------------------------------------------------------

	public function test_can_manage_campaigns_requires_specific_cap_or_manage_options(): void {
		af_test_set_caps( array() );
		$this->assertFalse( Permissions::can_manage_campaigns() );

		af_test_set_caps( array( 'af_manage_campaigns' => true ) );
		$this->assertTrue( Permissions::can_manage_campaigns() );

		af_test_set_caps( array( 'manage_options' => true ) );
		$this->assertTrue( Permissions::can_manage_campaigns() );
	}

	// -----------------------------------------------------------------------
	// can_manage_settings()
	// -----------------------------------------------------------------------

	public function test_can_manage_settings_requires_specific_cap_or_manage_options(): void {
		af_test_set_caps( array() );
		$this->assertFalse( Permissions::can_manage_settings() );

		af_test_set_caps( array( 'af_manage_settings' => true ) );
		$this->assertTrue( Permissions::can_manage_settings() );

		af_test_set_caps( array( 'manage_options' => true ) );
		$this->assertTrue( Permissions::can_manage_settings() );
	}

	// -----------------------------------------------------------------------
	// can_view_campaigns()
	// -----------------------------------------------------------------------

	public function test_can_view_campaigns_accepts_view_manage_or_options(): void {
		af_test_set_caps( array() );
		$this->assertFalse( Permissions::can_view_campaigns() );

		af_test_set_caps( array( 'af_view_campaigns' => true ) );
		$this->assertTrue( Permissions::can_view_campaigns() );

		af_test_set_caps( array( 'af_manage_campaigns' => true ) );
		$this->assertTrue( Permissions::can_view_campaigns() );

		af_test_set_caps( array( 'manage_options' => true ) );
		$this->assertTrue( Permissions::can_view_campaigns() );
	}

	// -----------------------------------------------------------------------
	// check_permission()
	// -----------------------------------------------------------------------

	public function test_check_permission_for_arbitrary_capability_with_options_fallback(): void {
		af_test_set_caps( array() );
		$this->assertFalse( Permissions::check_permission( 'af_manage_accounts' ) );

		af_test_set_caps( array( 'af_manage_accounts' => true ) );
		$this->assertTrue( Permissions::check_permission( 'af_manage_accounts' ) );

		af_test_set_caps( array( 'manage_options' => true ) );
		$this->assertTrue( Permissions::check_permission( 'af_manage_accounts' ) );
		// manage_options bypasses unrelated caps as well.
		$this->assertTrue( Permissions::check_permission( 'nonexistent_cap' ) );
	}

	// -----------------------------------------------------------------------
	// has_any_capability()
	// -----------------------------------------------------------------------

	public function test_has_any_capability_returns_true_for_manage_options_admin(): void {
		af_test_set_caps( array( 'manage_options' => true ) );
		$this->assertTrue( Permissions::has_any_capability( array( 'af_view_campaigns' ) ) );
	}

	public function test_has_any_capability_returns_true_when_at_least_one_matches(): void {
		af_test_set_caps( array( 'af_view_campaigns' => true ) );
		$this->assertTrue(
			Permissions::has_any_capability(
				array( 'af_manage_campaigns', 'af_view_campaigns' )
			)
		);
	}

	public function test_has_any_capability_returns_false_when_no_match(): void {
		af_test_set_caps( array() );
		$this->assertFalse(
			Permissions::has_any_capability( array( 'af_manage_campaigns', 'af_view_campaigns' ) )
		);
	}

	// -----------------------------------------------------------------------
	// get_current_bushido_role()
	// -----------------------------------------------------------------------

	public function test_get_current_bushido_role_returns_viewer_for_unknown_role(): void {
		// Default mapping in place.
		Roles::register_roles();

		// wp_get_current_user stub does not surface roles, so the method should
		// take the empty-roles branch and default to 'viewer'. The class accesses
		// the dynamic property, which on the default stub object is missing —
		// suppress just for this read to mimic WP_User semantics where roles is
		// always an array.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				return str_contains( $errstr, 'roles' );
			},
			E_WARNING
		);
		try {
			$role = Permissions::get_current_bushido_role();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'viewer', $role );
	}
}
