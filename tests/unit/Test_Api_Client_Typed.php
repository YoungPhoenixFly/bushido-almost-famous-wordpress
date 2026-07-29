<?php
/**
 * Tests for the typed helper methods on Api_Client.
 *
 * These don't modify the base get/post/patch/delete signatures — they just
 * map the array response through DTO factories and swallow Api_Error into
 * null/empty array results so call sites don't have to branch.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Dto\Auth\Connection;
use AlmostFamous\Dto\Payments\Payment;
use PHPUnit\Framework\TestCase;

final class Test_Api_Client_Typed extends TestCase {

	private Api_Client $client;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'bsh_test_key_typed' ) );
		$this->client = new Api_Client( $auth );
	}

	private function connection_row( string $id = 'cred_1', string $platform = 'META' ): array {
		return array(
			'id'                  => $id,
			'platform'            => $platform,
			'accountId'           => 'act_1',
			'accountName'         => 'My Page',
			'status'              => 'active',
			'lastHealthStatus'    => 'healthy',
			'lastHealthCheck'     => '2026-05-01T00:00:00Z',
			'healthCheckError'    => null,
			'consecutiveFailures' => 0,
			'tokenExpiresAt'      => '2026-06-01T00:00:00Z',
			'source'              => 'user',
			'createdAt'           => '2026-04-01T00:00:00Z',
			'updatedAt'           => '2026-04-15T00:00:00Z',
		);
	}

	private function payment_row( string $id = 'pay_1' ): array {
		return array(
			'id'                    => $id,
			'orgId'                 => 'org_1',
			'campaignId'            => 'cmp_1',
			'amount'                => 500.0,
			'serviceFee'            => 75.0,
			'licensingFee'          => 0.0,
			'totalCharged'          => 575.0,
			'currency'              => 'USD',
			'durationType'          => 'FIXED',
			'dailyBudget'           => 75.0,
			'durationDays'          => 7,
			'status'                => 'PAID',
			'stripeSessionId'       => 'cs_1',
			'stripePaymentIntentId' => 'pi_1',
			'externalReference'     => 'wp',
			'refundedAt'            => null,
			'refundAmount'          => null,
			'refundReason'          => null,
			'createdAt'             => '2026-04-01T00:00:00Z',
			'updatedAt'             => '2026-04-15T00:00:00Z',
		);
	}

	public function test_get_connections_unwraps_credentials_envelope(): void {
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'credentials' => array(
						$this->connection_row( 'cred_a', 'META' ),
						$this->connection_row( 'cred_b', 'GOOGLE' ),
					),
				) ),
				'headers'  => array(),
			)
		);

		$conns = $this->client->get_connections();

		$this->assertCount( 2, $conns );
		$this->assertInstanceOf( Connection::class, $conns[0] );
		$this->assertSame( 'META', $conns[0]->platform );
		$this->assertSame( 'GOOGLE', $conns[1]->platform );
	}

	public function test_get_connections_returns_empty_on_error(): void {
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 503 ),
				'body'     => wp_json_encode( array( 'code' => 'unavailable', 'message' => 'x' ) ),
				'headers'  => array(),
			)
		);

		$this->assertSame( array(), $this->client->get_connections() );
	}

	public function test_get_connection_filters_connections_list_by_id(): void {
		// No per-id backend route exists; get_connection() resolves from
		// the GET /auth/connections list.
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'credentials' => array(
							$this->connection_row( 'cred_other', 'META' ),
							$this->connection_row( 'cred_x', 'TIKTOK' ),
						),
					)
				),
				'headers'  => array(),
			)
		);

		$conn = $this->client->get_connection( 'cred_x' );

		$this->assertInstanceOf( Connection::class, $conn );
		$this->assertSame( 'cred_x', $conn->id );
		$this->assertSame( 'TIKTOK', $conn->platform );
	}

	public function test_get_connection_returns_null_when_id_not_in_list(): void {
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array( 'credentials' => array( $this->connection_row( 'cred_other', 'META' ) ) )
				),
				'headers'  => array(),
			)
		);

		$this->assertNull( $this->client->get_connection( 'missing' ) );
	}

	public function test_get_connection_returns_null_on_error(): void {
		af_test_register_http_response(
			'/auth/connections',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'code' => 'boom', 'message' => 'down' ) ),
				'headers'  => array(),
			)
		);

		$this->assertNull( $this->client->get_connection( 'missing' ) );
	}

	public function test_get_payments_returns_typed_dtos(): void {
		af_test_register_http_response(
			'/payments',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array(
					'data' => array( $this->payment_row( 'pay_a' ), $this->payment_row( 'pay_b' ) ),
					'meta' => array( 'total' => 2 ),
				) ),
				'headers'  => array(),
			)
		);

		$payments = $this->client->get_payments();

		$this->assertCount( 2, $payments );
		$this->assertInstanceOf( Payment::class, $payments[0] );
		$this->assertSame( 'pay_a', $payments[0]->id );
		$this->assertSame( 575.0, $payments[0]->totalCharged );
	}

	public function test_get_payments_returns_empty_on_error(): void {
		af_test_register_http_response(
			'/payments',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'code' => 'x', 'message' => 'y' ) ),
				'headers'  => array(),
			)
		);

		$this->assertSame( array(), $this->client->get_payments() );
	}
}
