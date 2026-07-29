<?php
/**
 * Connection DTO — mirrors ConnectionSchema in af-contracts/src/schemas/credential.ts.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Dto\Auth;

/**
 * Immutable value object describing a platform credential.
 */
final class Connection {

	/**
	 * Constructor.
	 *
	 * @param string      $id                   Credential row id.
	 * @param string      $platform             Platform value — one of META, GOOGLE, SPOTIFY, TIKTOK.
	 * @param string|null $accountId            Selected ad account id, or null when not yet picked.
	 * @param string|null $accountName          Human-readable account label.
	 * @param string      $status               Credential status string.
	 * @param string|null $lastHealthStatus     Last health-check result string.
	 * @param string|null $lastHealthCheck      ISO timestamp for last health check.
	 * @param string|null $healthCheckError     Optional last health-check error message.
	 * @param int|null    $consecutiveFailures  Consecutive failure count.
	 * @param string|null $tokenExpiresAt       ISO timestamp for token expiry.
	 * @param string      $source               Credential source string (e.g. 'user', 'system').
	 * @param string      $createdAt            ISO created timestamp.
	 * @param string      $updatedAt            ISO updated timestamp.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $platform,
		public readonly ?string $accountId,
		public readonly ?string $accountName,
		public readonly string $status,
		public readonly ?string $lastHealthStatus,
		public readonly ?string $lastHealthCheck,
		public readonly ?string $healthCheckError,
		public readonly ?int $consecutiveFailures,
		public readonly ?string $tokenExpiresAt,
		public readonly string $source,
		public readonly string $createdAt,
		public readonly string $updatedAt
	) {}

	/**
	 * Build a Connection from a wp_remote_request-decoded body.
	 *
	 * @param array<string, mixed> $data Decoded API payload.
	 * @throws \InvalidArgumentException When required fields are missing.
	 */
	public static function from_array( array $data ): self {
		$id       = isset( $data['id'] ) ? (string) $data['id'] : '';
		$platform = isset( $data['platform'] ) ? (string) $data['platform'] : '';

		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'Connection.id is required.' );
		}
		if ( '' === $platform ) {
			throw new \InvalidArgumentException( 'Connection.platform is required.' );
		}

		return new self(
			$id,
			$platform,
			isset( $data['accountId'] ) ? (string) $data['accountId'] : null,
			isset( $data['accountName'] ) ? (string) $data['accountName'] : null,
			isset( $data['status'] ) ? (string) $data['status'] : '',
			isset( $data['lastHealthStatus'] ) ? (string) $data['lastHealthStatus'] : null,
			isset( $data['lastHealthCheck'] ) ? (string) $data['lastHealthCheck'] : null,
			isset( $data['healthCheckError'] ) ? (string) $data['healthCheckError'] : null,
			isset( $data['consecutiveFailures'] ) ? (int) $data['consecutiveFailures'] : null,
			isset( $data['tokenExpiresAt'] ) ? (string) $data['tokenExpiresAt'] : null,
			isset( $data['source'] ) ? (string) $data['source'] : '',
			isset( $data['createdAt'] ) ? (string) $data['createdAt'] : '',
			isset( $data['updatedAt'] ) ? (string) $data['updatedAt'] : ''
		);
	}

	/**
	 * Serialize to the wire-format array (camelCase keys).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                  => $this->id,
			'platform'            => $this->platform,
			'accountId'           => $this->accountId,
			'accountName'         => $this->accountName,
			'status'              => $this->status,
			'lastHealthStatus'    => $this->lastHealthStatus,
			'lastHealthCheck'     => $this->lastHealthCheck,
			'healthCheckError'    => $this->healthCheckError,
			'consecutiveFailures' => $this->consecutiveFailures,
			'tokenExpiresAt'      => $this->tokenExpiresAt,
			'source'              => $this->source,
			'createdAt'           => $this->createdAt,
			'updatedAt'           => $this->updatedAt,
		);
	}
}
