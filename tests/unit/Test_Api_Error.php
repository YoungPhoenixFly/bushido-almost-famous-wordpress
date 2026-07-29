<?php
/**
 * Tests for Api_Error error taxonomy and handling.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Error;
use PHPUnit\Framework\TestCase;

/**
 * Test Api_Error class.
 */
class Test_Api_Error extends TestCase {

	/**
	 * Test from_response maps known error codes.
	 *
	 * @return void
	 */
	public function test_from_response_maps_known_error(): void {
		$body  = array(
			'error' => array(
				'code'    => 'tier_insufficient',
				'message' => 'Upgrade required',
				'detail'  => 'Pro subscription needed',
			),
		);
		$error = Api_Error::from_response( 403, $body );

		$this->assertSame( 'tier_insufficient', $error->code );
		$this->assertSame( 'Upgrade required', $error->message );
		$this->assertSame( 'Pro subscription needed', $error->detail );
		$this->assertSame( 403, $error->http_status );
	}

	/**
	 * Test from_response falls back to status-based code.
	 *
	 * @return void
	 */
	public function test_from_response_falls_back_to_status_code(): void {
		$error = Api_Error::from_response( 429, array() );

		$this->assertSame( 'rate_limited', $error->code );
		$this->assertSame( 429, $error->http_status );
	}

	/**
	 * Test status code to error code mapping.
	 *
	 * @dataProvider status_code_provider
	 *
	 * @param int    $status   HTTP status code.
	 * @param string $expected Expected error code.
	 * @return void
	 */
	public function test_status_code_mapping( int $status, string $expected ): void {
		$error = Api_Error::from_response( $status, array() );
		$this->assertSame( $expected, $error->code );
	}

	/**
	 * Data provider for status code mapping.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function status_code_provider(): array {
		return array(
			'400 → validation_error'  => array( 400, 'validation_error' ),
			'403 → permission_denied' => array( 403, 'permission_denied' ),
			'426 → upgrade_required'  => array( 426, 'upgrade_required' ),
			'429 → rate_limited'      => array( 429, 'rate_limited' ),
			'502 → platform_degraded' => array( 502, 'platform_degraded' ),
			'503 → api_unreachable'   => array( 503, 'api_unreachable' ),
			'504 → api_timeout'       => array( 504, 'api_timeout' ),
			'500 → unknown_error'     => array( 500, 'unknown_error' ),
		);
	}

	/**
	 * Test is_retriable returns true for retriable errors.
	 *
	 * @return void
	 */
	public function test_is_retriable_for_timeout(): void {
		$error = new Api_Error( 'api_timeout', 'Timeout', '', 504 );
		$this->assertTrue( $error->is_retriable() );
	}

	/**
	 * Test is_retriable returns true for rate limited.
	 *
	 * @return void
	 */
	public function test_is_retriable_for_rate_limited(): void {
		$error = new Api_Error( 'rate_limited', 'Too many requests', '', 429 );
		$this->assertTrue( $error->is_retriable() );
	}

	/**
	 * Test is_retriable returns false for non-retriable errors.
	 *
	 * @return void
	 */
	public function test_is_not_retriable_for_permission_denied(): void {
		$error = new Api_Error( 'permission_denied', 'Forbidden', '', 403 );
		$this->assertFalse( $error->is_retriable() );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$error  = new Api_Error( 'api_unreachable', 'Down', 'Connection refused', 503 );
		$result = $error->to_array();

		$this->assertSame( 'api_unreachable', $result['code'] );
		$this->assertSame( 'Down', $result['message'] );
		$this->assertSame( 'Connection refused', $result['detail'] );
	}
}
