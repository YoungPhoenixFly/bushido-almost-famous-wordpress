<?php
/**
 * Tests for the Payment DTO.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Dto\Payments\Payment;
use PHPUnit\Framework\TestCase;

final class Test_Payment extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	private function sample(): array {
		return array(
			'id'                    => 'pay_1',
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
			'stripeSessionId'       => 'cs_test_1',
			'stripePaymentIntentId' => 'pi_test_1',
			'externalReference'     => 'wp-test',
			'refundedAt'            => null,
			'refundAmount'          => null,
			'refundReason'          => null,
			'createdAt'             => '2026-04-01T00:00:00Z',
			'updatedAt'             => '2026-04-15T00:00:00Z',
		);
	}

	public function test_round_trip(): void {
		$sample = $this->sample();
		$p      = Payment::from_array( $sample );

		$this->assertSame( 'pay_1', $p->id );
		$this->assertSame( 575.0, $p->totalCharged );
		$this->assertSame( 7, $p->durationDays );
		$this->assertNull( $p->refundedAt );
		$this->assertSame( $sample, $p->to_array() );
	}

	public function test_refunded_populated(): void {
		$sample                 = $this->sample();
		$sample['status']       = 'REFUNDED';
		$sample['refundedAt']   = '2026-04-20T00:00:00Z';
		$sample['refundAmount'] = 575.0;
		$sample['refundReason'] = 'cancelled';

		$p = Payment::from_array( $sample );
		$this->assertSame( 'REFUNDED', $p->status );
		$this->assertSame( 575.0, $p->refundAmount );
		$this->assertSame( $sample, $p->to_array() );
	}

	public function test_duration_days_nullable(): void {
		$sample                 = $this->sample();
		$sample['durationDays'] = null;

		$p = Payment::from_array( $sample );
		$this->assertNull( $p->durationDays );
	}

	public function test_missing_campaign_id_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Payment::from_array( array( 'id' => 'x', 'orgId' => 'y' ) );
	}
}
