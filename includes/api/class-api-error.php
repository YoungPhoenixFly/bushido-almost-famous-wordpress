<?php
/**
 * Structured API error handling and error code taxonomy.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

use AlmostFamous\Enums\Error_Code;

/**
 * Represents a structured API error with code, message, detail, and HTTP status.
 *
 * This is the single source of truth for error handling across the entire plugin.
 * All Bushido API errors are mapped to this structure before being returned to consumers.
 */
class Api_Error {

	/**
	 * Constructor.
	 *
	 * @param string $code        Error code from taxonomy (e.g., 'api_unreachable').
	 * @param string $message     Human-readable error message (translatable).
	 * @param string $detail      Technical detail for debugging.
	 * @param int    $http_status HTTP status code.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly string $detail,
		public readonly int $http_status,
	) {
	}

	/**
	 * Create an Api_Error from a Bushido API error response.
	 *
	 * @param int   $status_code HTTP status code from the response.
	 * @param array $body        Decoded JSON response body.
	 * @return self
	 */
	public static function from_response( int $status_code, array $body ): self {
		// Plugin-shaped: { error: { code, message, detail } }.
		$nested = is_array( $body['error'] ?? null ) ? $body['error'] : array();

		// NestJS HttpException shape: { statusCode, message, error, requestId, ... }
		// where `error` is a string like "Unauthorized" and `message` is the human text.
		$has_nest_shape = is_string( $body['error'] ?? null )
			&& is_string( $body['message'] ?? null )
			&& isset( $body['statusCode'] );

		$code = $nested['code'] ?? self::code_from_status( $status_code );

		if ( '' === (string) $code || 'unknown_error' === $code ) {
			$code = self::code_from_status( $status_code );
		}

		$message = $nested['message']
			?? ( $has_nest_shape ? (string) $body['message'] : null )
			?? ( is_string( $body['message'] ?? null ) ? (string) $body['message'] : null )
			?? __( 'An unexpected error occurred.', 'bushido-almost-famous' );

		$detail = $nested['detail']
			?? ( $has_nest_shape ? (string) ( $body['requestId'] ?? '' ) : '' );

		return new self(
			code: (string) $code,
			message: (string) $message,
			detail: (string) $detail,
			http_status: $status_code,
		);
	}

	/**
	 * Check if this error is retriable.
	 *
	 * @return bool True if the error can be retried (api_timeout, rate_limited).
	 */
	public function is_retriable(): bool {
		return in_array(
			$this->code,
			array(
				Error_Code::API_TIMEOUT->value,
				Error_Code::RATE_LIMITED->value,
			),
			true
		);
	}

	/**
	 * Convert to array for WP REST Response.
	 *
	 * @return array{code: string, message: string, detail: string}
	 */
	public function to_array(): array {
		return array(
			'code'    => $this->code,
			'message' => $this->message,
			'detail'  => $this->detail,
		);
	}

	/**
	 * Map HTTP status code to error code when not provided by API.
	 *
	 * @param int $status HTTP status code.
	 * @return string Error code string.
	 */
	private static function code_from_status( int $status ): string {
		return match ( true ) {
			400 === $status => Error_Code::VALIDATION_ERROR->value,
			401 === $status,
			403 === $status => Error_Code::PERMISSION_DENIED->value,
			426 === $status => Error_Code::UPGRADE_REQUIRED->value,
			429 === $status => Error_Code::RATE_LIMITED->value,
			502 === $status => Error_Code::PLATFORM_DEGRADED->value,
			503 === $status => Error_Code::API_UNREACHABLE->value,
			504 === $status => Error_Code::API_TIMEOUT->value,
			default         => 'unknown_error',
		};
	}
}
