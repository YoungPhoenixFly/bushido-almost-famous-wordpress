<?php
/**
 * Tests for plugin activation and basic loading.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test plugin activation basics.
 */
class Test_Plugin_Activation extends TestCase {

	/**
	 * Test that required constants are defined.
	 *
	 * @return void
	 */
	public function test_constants_defined(): void {
		$this->assertTrue( defined( 'ALMOST_FAMOUS_VERSION' ) );
		$this->assertTrue( defined( 'ALMOST_FAMOUS_PLUGIN_DIR' ) );
		$this->assertSame(
			array(
				'api' => 'https://api.almost-famous.backend-bushidoco.de/api/v1',
				'app' => 'https://bushido.is',
			),
			Config::resolve_service_endpoints()
		);
	}

	/**
	 * Test that autoloader resolves plugin classes.
	 *
	 * @return void
	 */
	public function test_autoloader_resolves_classes(): void {
		$this->assertTrue( class_exists( \AlmostFamous\Plugin::class ) );
		$this->assertTrue( class_exists( \AlmostFamous\Activator::class ) );
		$this->assertTrue( class_exists( \AlmostFamous\Deactivator::class ) );
	}

	/**
	 * Test that enum classes are loadable.
	 *
	 * @return void
	 */
	public function test_enums_loadable(): void {
		$this->assertTrue( enum_exists( \AlmostFamous\Enums\Campaign_Status::class ) );
		$this->assertTrue( enum_exists( \AlmostFamous\Enums\Error_Code::class ) );
		$this->assertTrue( enum_exists( \AlmostFamous\Enums\Platform_Type::class ) );
	}
}
