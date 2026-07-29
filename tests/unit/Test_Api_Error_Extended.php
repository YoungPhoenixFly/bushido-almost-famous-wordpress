<?php
/**
 * Extends Test_Api_Error.php with extra parsing-shape coverage, the full
 * Error_Code enum cardinality across code_from_status mapping, and explicit
 * is_retriable() truth/false cases that the base test left out.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Error;
use AlmostFamous\Enums\Error_Code;
use PHPUnit\Framework\TestCase;

class Test_Api_Error_Extended extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	// -----------------------------------------------------------------------
	// Backend shape: { error: { code, message, detail } }
	// -----------------------------------------------------------------------

	public function test_backend_nested_error_shape_is_parsed_fully(): void {
		$body = array(
			'error' => array(
				'code'    => 'tier_insufficient',
				'message' => 'Upgrade your plan',
				'detail'  => 'Pro required for >10 campaigns',
			),
		);

		$err = Api_Error::from_response( 403, $body );

		$this->assertSame( 'tier_insufficient', $err->code );
		$this->assertSame( 'Upgrade your plan', $err->message );
		$this->assertSame( 'Pro required for >10 campaigns', $err->detail );
		$this->assertSame( 403, $err->http_status );
	}

	public function test_backend_nested_shape_with_unknown_code_falls_back_to_status_derivation(): void {
		$body = array(
			'error' => array(
				'code'    => 'unknown_error',
				'message' => 'Something broke',
			),
		);

		$err = Api_Error::from_response( 429, $body );

		// 'unknown_error' should be overridden by status-derived 'rate_limited'.
		$this->assertSame( 'rate_limited', $err->code );
		$this->assertSame( 'Something broke', $err->message );
	}

	public function test_backend_shape_with_empty_code_falls_back_to_status_derivation(): void {
		$body = array(
			'error' => array(
				'code'    => '',
				'message' => 'No code in body',
			),
		);

		$err = Api_Error::from_response( 502, $body );

		$this->assertSame( 'platform_degraded', $err->code );
		$this->assertSame( 'No code in body', $err->message );
	}

	// -----------------------------------------------------------------------
	// NestJS shape: { statusCode, message, error: "Forbidden", requestId, … }
	// -----------------------------------------------------------------------

	public function test_nestjs_shape_message_is_taken_from_top_level_message(): void {
		$body = array(
			'statusCode' => 403,
			'message'    => 'Org access denied',
			'error'      => 'Forbidden',
			'requestId'  => 'req-abc-123',
			'timestamp'  => '2026-05-11T00:00:00.000Z',
		);

		$err = Api_Error::from_response( 403, $body );

		$this->assertSame( 'permission_denied', $err->code );
		$this->assertSame( 'Org access denied', $err->message );
		$this->assertSame( 'req-abc-123', $err->detail, 'NestJS requestId should land in detail for triage.' );
		$this->assertSame( 403, $err->http_status );
	}

	public function test_nestjs_shape_without_request_id_has_empty_detail(): void {
		$body = array(
			'statusCode' => 400,
			'message'    => 'Bad input',
			'error'      => 'Bad Request',
		);

		$err = Api_Error::from_response( 400, $body );

		$this->assertSame( 'validation_error', $err->code );
		$this->assertSame( 'Bad input', $err->message );
		$this->assertSame( '', $err->detail );
	}

	// -----------------------------------------------------------------------
	// is_retriable() — all retriable codes return true,
	// all non-retriable codes return false
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider retriable_codes_provider
	 */
	public function test_is_retriable_true_for_transient_failures( string $code ): void {
		$err = new Api_Error( $code, 'msg', '', 0 );
		$this->assertTrue( $err->is_retriable() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function retriable_codes_provider(): array {
		return array(
			'api_timeout'  => array( Error_Code::API_TIMEOUT->value ),
			'rate_limited' => array( Error_Code::RATE_LIMITED->value ),
		);
	}

	/**
	 * @dataProvider non_retriable_codes_provider
	 */
	public function test_is_retriable_false_for_terminal_failures( string $code ): void {
		$err = new Api_Error( $code, 'msg', '', 0 );
		$this->assertFalse( $err->is_retriable() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function non_retriable_codes_provider(): array {
		return array(
			'permission_denied' => array( Error_Code::PERMISSION_DENIED->value ),
			'validation_error'  => array( Error_Code::VALIDATION_ERROR->value ),
			'upgrade_required'  => array( Error_Code::UPGRADE_REQUIRED->value ),
			'tier_insufficient' => array( Error_Code::TIER_INSUFFICIENT->value ),
			'tier_expired'      => array( Error_Code::TIER_EXPIRED->value ),
			'platform_degraded' => array( Error_Code::PLATFORM_DEGRADED->value ),
			'api_unreachable'   => array( Error_Code::API_UNREACHABLE->value ),
		);
	}

	// -----------------------------------------------------------------------
	// All Error_Code enum cases are reachable via code_from_status — i.e. the
	// taxonomy isn't dead. (TIER_INSUFFICIENT and TIER_EXPIRED are only
	// reachable through the nested-error shape, not status mapping.)
	// -----------------------------------------------------------------------

	public function test_all_status_mapped_error_codes_are_reachable_from_status(): void {
		$mapping = array(
			400 => Error_Code::VALIDATION_ERROR->value,
			401 => Error_Code::PERMISSION_DENIED->value,
			403 => Error_Code::PERMISSION_DENIED->value,
			426 => Error_Code::UPGRADE_REQUIRED->value,
			429 => Error_Code::RATE_LIMITED->value,
			502 => Error_Code::PLATFORM_DEGRADED->value,
			503 => Error_Code::API_UNREACHABLE->value,
			504 => Error_Code::API_TIMEOUT->value,
		);

		foreach ( $mapping as $status => $expected_code ) {
			$err = Api_Error::from_response( $status, array() );
			$this->assertSame( $expected_code, $err->code, "Status {$status} must map to {$expected_code}" );
		}
	}

	public function test_tier_error_codes_reachable_via_backend_body(): void {
		// TIER_INSUFFICIENT and TIER_EXPIRED never come out of status mapping;
		// they only arrive in the nested-error shape. Ensure both are kept
		// verbatim when the backend reports them.
		$insufficient = Api_Error::from_response(
			403,
			array( 'error' => array( 'code' => Error_Code::TIER_INSUFFICIENT->value, 'message' => 'Upgrade required' ) )
		);
		$expired = Api_Error::from_response(
			403,
			array( 'error' => array( 'code' => Error_Code::TIER_EXPIRED->value, 'message' => 'Plan expired' ) )
		);

		$this->assertSame( 'tier_insufficient', $insufficient->code );
		$this->assertSame( 'tier_expired', $expired->code );
	}

	public function test_to_array_round_trips_all_fields(): void {
		$err = Api_Error::from_response(
			502,
			array(
				'error' => array(
					'code'    => 'platform_degraded',
					'message' => 'TikTok is offline',
					'detail'  => 'circuit-breaker open',
				),
			)
		);

		$arr = $err->to_array();
		$this->assertSame( 'platform_degraded', $arr['code'] );
		$this->assertSame( 'TikTok is offline', $arr['message'] );
		$this->assertSame( 'circuit-breaker open', $arr['detail'] );
	}
}
