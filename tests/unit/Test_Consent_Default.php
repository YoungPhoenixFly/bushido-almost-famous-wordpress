<?php
/**
 * Tests that the consent integration defaults to "denied" when no CMP plugin
 * is active, switchable on by the almost_famous_default_consent filter.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Consent\Consent_Integration;
use PHPUnit\Framework\TestCase;

class Test_Consent_Default extends TestCase {

	private Consent_Integration $consent;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$this->consent = new Consent_Integration( new Api_Client( new Api_Auth() ) );

		// Ensure $_COOKIE never bleeds across tests.
		$_COOKIE = array();
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		parent::tearDown();
	}

	public function test_no_cmp_defaults_to_no_consent(): void {
		$this->assertFalse( $this->consent->has_consent() );
	}

	public function test_opt_in_filter_overrides_default(): void {
		add_filter(
			'almost_famous_default_consent',
			static function (): bool {
				return true;
			}
		);
		$this->assertTrue( $this->consent->has_consent() );
	}

	public function test_filter_returning_false_keeps_default_denial(): void {
		add_filter(
			'almost_famous_default_consent',
			static function (): bool {
				return false;
			}
		);
		$this->assertFalse( $this->consent->has_consent() );
	}

	public function test_settings_checkbox_opts_into_assumed_consent(): void {
		update_option( 'af_assume_consent_no_cmp', '1' );
		$this->assertTrue( $this->consent->has_consent() );
	}

	public function test_filter_can_override_settings_checkbox(): void {
		// The filter receives the option-derived default and wins.
		update_option( 'af_assume_consent_no_cmp', '1' );
		add_filter(
			'almost_famous_default_consent',
			static function (): bool {
				return false;
			}
		);
		$this->assertFalse( $this->consent->has_consent() );
	}
}
