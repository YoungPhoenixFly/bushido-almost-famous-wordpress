<?php
/**
 * Tests for the Connection DTO.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Dto\Auth\Connection;
use PHPUnit\Framework\TestCase;

final class Test_Connection extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	private function sample(): array {
		return array(
			'id'                  => 'cred_abc',
			'platform'            => 'META',
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

	public function test_from_array_populates_every_field(): void {
		$conn = Connection::from_array( $this->sample() );

		$this->assertSame( 'cred_abc', $conn->id );
		$this->assertSame( 'META', $conn->platform );
		$this->assertSame( 'act_1', $conn->accountId );
		$this->assertSame( 'My Page', $conn->accountName );
		$this->assertSame( 'active', $conn->status );
		$this->assertSame( 'healthy', $conn->lastHealthStatus );
		$this->assertSame( '2026-05-01T00:00:00Z', $conn->lastHealthCheck );
		$this->assertNull( $conn->healthCheckError );
		$this->assertSame( 0, $conn->consecutiveFailures );
		$this->assertSame( '2026-06-01T00:00:00Z', $conn->tokenExpiresAt );
		$this->assertSame( 'user', $conn->source );
	}

	public function test_round_trip_to_array_equals_input(): void {
		$sample = $this->sample();
		$this->assertSame( $sample, Connection::from_array( $sample )->to_array() );
	}

	public function test_nullable_account_name_defaults_to_null_when_absent(): void {
		$sample = $this->sample();
		unset( $sample['accountName'] );

		$conn = Connection::from_array( $sample );
		$this->assertNull( $conn->accountName );

		$out = $conn->to_array();
		$this->assertArrayHasKey( 'accountName', $out );
		$this->assertNull( $out['accountName'] );
	}

	public function test_missing_id_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Connection::from_array( array( 'platform' => 'META' ) );
	}

	public function test_missing_platform_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Connection::from_array( array( 'id' => 'cred' ) );
	}
}
