<?php
/**
 * Incoming webhook REST endpoint with HMAC-SHA256 verification.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Webhooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Auth;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Incoming webhook REST endpoint with HMAC-SHA256 verification.
 *
 * Registers a public REST route that accepts incoming webhooks from the
 * Bushido platform. Verifies HMAC-SHA256 signatures, enforces idempotency,
 * and routes events to the appropriate handler.
 *
 * DORMANT BY DEFAULT: the receiver is fully implemented — HMAC-SHA256 over
 * `timestamp.body`, a ±5 minute replay window, and 24-hour idempotency
 * de-duplication — but no Bushido backend currently emits these events or
 * registers a site's callback URL/secret, so the REST route is not
 * registered unless the `almost_famous/enable_webhooks` filter returns
 * true. Once a backend dispatcher and site-registration flow exist (they
 * must seal the shared secret into the `af_webhook_secret` option via
 * Api_Auth), enable the route with:
 *
 *     add_filter( 'almost_famous/enable_webhooks', '__return_true' );
 */
class Webhook_Receiver {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'almost-famous/v1';

	/**
	 * REST route path.
	 *
	 * @var string
	 */
	private const ROUTE = '/webhooks/incoming';

	/**
	 * Idempotency transient TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	private const IDEMPOTENCY_TTL = 86400;

	/**
	 * Maximum allowed clock skew between sender and receiver (seconds).
	 * Mirrors the Stripe / GitHub webhook contract.
	 *
	 * @var int
	 */
	private const REPLAY_WINDOW_SECONDS = 300;

	/**
	 * Maximum webhook event log entries.
	 *
	 * @var int
	 */
	private const MAX_LOG_ENTRIES = 50;

	/**
	 * Webhook handlers instance.
	 *
	 * @var Webhook_Handlers
	 */
	private Webhook_Handlers $handlers;

	/**
	 * API authentication handler for decrypting the webhook secret.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * Constructor.
	 *
	 * @param Webhook_Handlers $handlers The webhook event handlers.
	 * @param Api_Auth|null    $auth     Optional API auth handler for decrypting secrets.
	 */
	public function __construct( Webhook_Handlers $handlers, ?Api_Auth $auth = null ) {
		$this->handlers = $handlers;
		$this->auth     = $auth ?? new Api_Auth();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the incoming webhook REST route.
	 *
	 * Self-gates on the `almost_famous/enable_webhooks` filter so the route
	 * stays dormant by default — see the class docblock.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		/**
		 * Filters whether the inbound webhook REST route is registered.
		 *
		 * Off by default: no Bushido backend currently emits these events,
		 * so exposing the endpoint would only add attack surface. Return
		 * true once a backend dispatcher + site registration exist.
		 *
		 * @param bool $enabled Whether to register the route. Default false.
		 */
		if ( ! apply_filters( 'almost_famous/enable_webhooks', false ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * Verifies the HMAC signature, checks idempotency, routes to the
	 * appropriate handler, and logs the event.
	 *
	 * @param WP_REST_Request $request The incoming webhook request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'X-Bushido-Signature' );
		$timestamp = (string) $request->get_header( 'X-Bushido-Timestamp' );

		// Replay protection: every webhook must carry a fresh timestamp.
		if ( '' === $timestamp ) {
			return new WP_REST_Response(
				array(
					'error'   => 'missing_timestamp',
					'message' => __( 'Webhook is missing the X-Bushido-Timestamp header.', 'bushido-almost-famous' ),
				),
				401
			);
		}

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::REPLAY_WINDOW_SECONDS ) {
			return new WP_REST_Response(
				array(
					'error'   => 'replay_window',
					'message' => __( 'Webhook timestamp is outside the allowed window.', 'bushido-almost-famous' ),
				),
				401
			);
		}

		// Verify HMAC signature over timestamp.body.
		if ( ! $this->verify_signature( $timestamp, $body, $signature ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_signature',
					'message' => __( 'Invalid webhook signature.', 'bushido-almost-famous' ),
				),
				401
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'invalid_payload',
					'message' => __( 'Invalid JSON payload.', 'bushido-almost-famous' ),
				),
				400
			);
		}

		$event_type      = sanitize_text_field( $payload['event_type'] ?? '' );
		$idempotency_key = sanitize_text_field( $payload['idempotency_key'] ?? '' );

		if ( empty( $event_type ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'missing_event_type',
					'message' => __( 'Missing event_type in payload.', 'bushido-almost-famous' ),
				),
				400
			);
		}

		// Idempotency check — reject duplicate events.
		if ( ! empty( $idempotency_key ) && $this->is_duplicate( $idempotency_key ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'duplicate_event',
					'message' => __( 'Duplicate webhook event.', 'bushido-almost-famous' ),
				),
				409
			);
		}

		// Log the event.
		$this->log_event( $event_type, $payload );

		/**
		 * Fires when a webhook is received.
		 *
		 * @param string $event_type The webhook event type.
		 * @param array  $payload    The full webhook payload.
		 */
		do_action( 'almost_famous/webhook/received', $event_type, $payload );

		// Route to the appropriate handler.
		try {
			$this->handlers->dispatch( $event_type, $payload );
		} catch ( \Throwable $e ) {
			// Do NOT record the idempotency key on failure — the sender should
			// be able to retry a transiently-failed event. Recording it here
			// would make the retry a 409 and silently drop the event.
			return new WP_REST_Response(
				array(
					'error'   => 'handler_error',
					'message' => __( 'Webhook handler failed.', 'bushido-almost-famous' ),
				),
				500
			);
		}

		// Mark the event as seen only after it was handled successfully, so a
		// retry after a failure is processed rather than deduplicated away.
		if ( ! empty( $idempotency_key ) ) {
			set_transient( 'af_webhook_seen_' . $idempotency_key, true, self::IDEMPOTENCY_TTL );
		}

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'message' => __( 'Webhook processed successfully.', 'bushido-almost-famous' ),
			),
			200
		);
	}

	/**
	 * Verify the HMAC-SHA256 signature over `timestamp.body`.
	 *
	 * @param string $timestamp Validated X-Bushido-Timestamp header value.
	 * @param string $body      Raw request body.
	 * @param string $signature Signature from X-Bushido-Signature header.
	 * @return bool True if the signature matches.
	 */
	private function verify_signature( string $timestamp, string $body, string $signature ): bool {
		if ( '' === $signature ) {
			return false;
		}

		$encrypted_secret = get_option( 'af_webhook_secret', '' );

		if ( empty( $encrypted_secret ) ) {
			return false;
		}

		$secret = $this->auth->decrypt( $encrypted_secret );

		if ( empty( $secret ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Check if a webhook event has already been processed.
	 *
	 * @param string $idempotency_key The unique event key.
	 * @return bool True if the event has already been seen.
	 */
	private function is_duplicate( string $idempotency_key ): bool {
		return false !== get_transient( 'af_webhook_seen_' . $idempotency_key );
	}

	/**
	 * Log a webhook event to the transient-based event log.
	 *
	 * Maintains a rolling log of the last 50 events for diagnostics.
	 *
	 * @param string $event_type The webhook event type.
	 * @param array  $payload    The full webhook payload.
	 * @return void
	 */
	private function log_event( string $event_type, array $payload ): void {
		$log = get_transient( 'af_webhook_event_log' );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Prepend the new event.
		array_unshift(
			$log,
			array(
				'event_type'      => $event_type,
				'idempotency_key' => $payload['idempotency_key'] ?? '',
				'timestamp'       => gmdate( 'c' ),
				'summary'         => wp_json_encode( array_diff_key( $payload, array_flip( array( 'event_type', 'idempotency_key' ) ) ) ),
			)
		);

		// Keep only the latest entries.
		$log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );

		// Store for 7 days.
		set_transient( 'af_webhook_event_log', $log, 7 * DAY_IN_SECONDS );
	}
}
