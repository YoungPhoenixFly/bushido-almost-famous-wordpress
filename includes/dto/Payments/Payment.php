<?php
/**
 * Payment DTO — mirrors PaymentSchema in af-contracts/src/schemas/payment.ts.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Dto\Payments;

/**
 * Payment record returned by GET /payments[/{id}].
 */
final class Payment {

	/**
	 * Constructor.
	 *
	 * @param string      $id                    Payment cuid.
	 * @param string      $orgId                 Owning organisation id.
	 * @param string      $campaignId            Linked campaign id.
	 * @param float       $amount                Pre-fee charge amount.
	 * @param float       $serviceFee            Platform service fee.
	 * @param float       $licensingFee          Music licensing fee.
	 * @param float       $totalCharged          Total amount charged to the customer.
	 * @param string      $currency              ISO 4217 currency code.
	 * @param string      $durationType          One of DURATION_TYPE_VALUES.
	 * @param float       $dailyBudget           Configured daily budget.
	 * @param int|null    $durationDays          Fixed-duration day count, or null when open-ended.
	 * @param string      $status                One of PAYMENT_STATUS_VALUES.
	 * @param string|null $stripeSessionId       Stripe checkout session id.
	 * @param string|null $stripePaymentIntentId Stripe payment intent id.
	 * @param string|null $externalReference     Caller-supplied reference string.
	 * @param string|null $refundedAt            ISO timestamp when refunded.
	 * @param float|null  $refundAmount          Refunded amount.
	 * @param string|null $refundReason          Reason supplied with the refund.
	 * @param string      $createdAt             ISO created timestamp.
	 * @param string      $updatedAt             ISO updated timestamp.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $orgId,
		public readonly string $campaignId,
		public readonly float $amount,
		public readonly float $serviceFee,
		public readonly float $licensingFee,
		public readonly float $totalCharged,
		public readonly string $currency,
		public readonly string $durationType,
		public readonly float $dailyBudget,
		public readonly ?int $durationDays,
		public readonly string $status,
		public readonly ?string $stripeSessionId,
		public readonly ?string $stripePaymentIntentId,
		public readonly ?string $externalReference,
		public readonly ?string $refundedAt,
		public readonly ?float $refundAmount,
		public readonly ?string $refundReason,
		public readonly string $createdAt,
		public readonly string $updatedAt
	) {}

	/**
	 * @param array<string, mixed> $data Decoded API payload.
	 * @throws \InvalidArgumentException When id/orgId/campaignId is missing.
	 */
	public static function from_array( array $data ): self {
		$id          = isset( $data['id'] ) ? (string) $data['id'] : '';
		$org_id      = isset( $data['orgId'] ) ? (string) $data['orgId'] : '';
		$campaign_id = isset( $data['campaignId'] ) ? (string) $data['campaignId'] : '';

		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'Payment.id is required.' );
		}
		if ( '' === $org_id ) {
			throw new \InvalidArgumentException( 'Payment.orgId is required.' );
		}
		if ( '' === $campaign_id ) {
			throw new \InvalidArgumentException( 'Payment.campaignId is required.' );
		}

		return new self(
			$id,
			$org_id,
			$campaign_id,
			isset( $data['amount'] ) ? (float) $data['amount'] : 0.0,
			isset( $data['serviceFee'] ) ? (float) $data['serviceFee'] : 0.0,
			isset( $data['licensingFee'] ) ? (float) $data['licensingFee'] : 0.0,
			isset( $data['totalCharged'] ) ? (float) $data['totalCharged'] : 0.0,
			isset( $data['currency'] ) ? (string) $data['currency'] : '',
			isset( $data['durationType'] ) ? (string) $data['durationType'] : '',
			isset( $data['dailyBudget'] ) ? (float) $data['dailyBudget'] : 0.0,
			isset( $data['durationDays'] ) && is_numeric( $data['durationDays'] ) ? (int) $data['durationDays'] : null,
			isset( $data['status'] ) ? (string) $data['status'] : '',
			isset( $data['stripeSessionId'] ) ? (string) $data['stripeSessionId'] : null,
			isset( $data['stripePaymentIntentId'] ) ? (string) $data['stripePaymentIntentId'] : null,
			isset( $data['externalReference'] ) ? (string) $data['externalReference'] : null,
			isset( $data['refundedAt'] ) ? (string) $data['refundedAt'] : null,
			isset( $data['refundAmount'] ) && is_numeric( $data['refundAmount'] ) ? (float) $data['refundAmount'] : null,
			isset( $data['refundReason'] ) ? (string) $data['refundReason'] : null,
			isset( $data['createdAt'] ) ? (string) $data['createdAt'] : '',
			isset( $data['updatedAt'] ) ? (string) $data['updatedAt'] : ''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                    => $this->id,
			'orgId'                 => $this->orgId,
			'campaignId'            => $this->campaignId,
			'amount'                => $this->amount,
			'serviceFee'            => $this->serviceFee,
			'licensingFee'          => $this->licensingFee,
			'totalCharged'          => $this->totalCharged,
			'currency'              => $this->currency,
			'durationType'          => $this->durationType,
			'dailyBudget'           => $this->dailyBudget,
			'durationDays'          => $this->durationDays,
			'status'                => $this->status,
			'stripeSessionId'       => $this->stripeSessionId,
			'stripePaymentIntentId' => $this->stripePaymentIntentId,
			'externalReference'     => $this->externalReference,
			'refundedAt'            => $this->refundedAt,
			'refundAmount'          => $this->refundAmount,
			'refundReason'          => $this->refundReason,
			'createdAt'             => $this->createdAt,
			'updatedAt'             => $this->updatedAt,
		);
	}
}
