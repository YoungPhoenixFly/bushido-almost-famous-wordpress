<?php
/**
 * Tests Api_Client sends X-API-Key (the backend's expected header) and
 * supports top-level paths that bypass the /public/ auto-prefix.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Api_Error;
use PHPUnit\Framework\TestCase;

class Test_Api_Client_Auth extends TestCase {

	private const TEST_KEY = 'bsh_test_api_key_12345';

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( self::TEST_KEY ) );
	}

	private function client(): Api_Client {
		return new Api_Client( new Api_Auth() );
	}

	private function last_request(): array {
		$requests = af_test_get_http_requests();
		return $requests[ array_key_last( $requests ) ] ?? array();
	}

	public function test_get_sends_x_api_key_header_not_bearer(): void {
		af_test_register_http_response(
			'/campaigns',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'headers'  => array(),
			)
		);

		$this->client()->get( '/campaigns' );

		$headers = $this->last_request()['args']['headers'] ?? array();
		$this->assertArrayHasKey( 'X-API-Key', $headers );
		$this->assertSame( self::TEST_KEY, $headers['X-API-Key'] );
		$this->assertArrayNotHasKey(
			'Authorization',
			$headers,
			'The bushido backend reads x-api-key; sending Authorization: Bearer leaks the key into a header the backend ignores.'
		);
	}

	public function test_get_top_skips_public_prefix(): void {
		af_test_register_http_response(
			'/auth/validate',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'valid' => true, 'keyType' => 'org', 'orgId' => 'org_1' ) ),
				'headers'  => array(),
			)
		);

		$this->client()->get_top( '/auth/validate' );

		$url = (string) ( $this->last_request()['url'] ?? '' );
		$this->assertStringNotContainsString( '/public/', $url );
		$this->assertStringEndsWith( '/auth/validate', $url );
	}

	public function test_get_still_appends_public_prefix(): void {
		af_test_register_http_response(
			'/auth/connect/meta',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'authorizationUrl' => 'https://x' ) ),
				'headers'  => array(),
			)
		);

		$this->client()->post( '/auth/connect/meta', array() );

		$url = (string) ( $this->last_request()['url'] ?? '' );
		$this->assertStringContainsString( '/public/auth/connect/meta', $url );
	}

	public function test_api_error_parses_nestjs_response_shape(): void {
		$nestjs_body = array(
			'statusCode' => 401,
			'message'    => 'Invalid API key',
			'error'      => 'Unauthorized',
			'requestId'  => 'abc',
			'timestamp'  => '2026-05-10T23:00:00.000Z',
			'path'       => '/api/v1/auth/validate',
		);

		$err = Api_Error::from_response( 401, $nestjs_body );

		$this->assertSame( 401, $err->http_status );
		$this->assertSame( 'Invalid API key', $err->message );
		$this->assertSame( 'permission_denied', $err->code );
	}
}
