<?php
/**
 * Tests for Webhook_Receiver behaviour that is NOT covered by
 * Test_Webhook_Receiver_Replay (which exclusively exercises the timestamp/
 * signature replay window). Specifically:
 *
 *  - Dormant-by-default route registration gated on the
 *    `almost_famous/enable_webhooks` filter
 *  - HMAC verification good vs. bad signature (timing-safe equality)
 *  - Idempotency-key dedupe → 409 Conflict on replay
 *  - Malformed JSON → 400 invalid_payload
 *  - Missing event_type → 400 missing_event_type
 *  - Handler exceptions caught → 500
 *  - Rolling 50-entry cap on the event log
 *
 * The request-handling tests call handle_webhook() directly, so they
 * exercise the full verification pipeline regardless of whether the REST
 * route itself is registered.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Webhooks\Webhook_Handlers;
use AlmostFamous\Webhooks\Webhook_Receiver;
use PHPUnit\Framework\TestCase;

class Test_Webhook_Receiver extends TestCase {

	private const SECRET = 'webhook-secret-for-tests';

	private Webhook_Receiver $receiver;
	private Api_Auth $auth;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		$this->auth = new Api_Auth();
		update_option( 'af_webhook_secret', $this->auth->encrypt( self::SECRET ) );
		update_option( 'af_api_key', $this->auth->encrypt_api_key( 'bsh_test_key' ) );

		$handlers       = new Webhook_Handlers( new Api_Cache() );
		$this->receiver = new Webhook_Receiver( $handlers, $this->auth );
	}

	private function build_request( string $body, string $signature, string $timestamp ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/webhooks/incoming' );
		$req->set_body( $body );
		$req->set_header( 'X-Bushido-Signature', $signature );
		$req->set_header( 'X-Bushido-Timestamp', $timestamp );
		return $req;
	}

	private function sign( string $timestamp, string $body, string $secret = self::SECRET ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	// ------------------------------------------------------------------
	// Dormant by default: the REST route only exists when the
	// `almost_famous/enable_webhooks` filter opts in.
	// ------------------------------------------------------------------

	public function test_route_is_not_registered_by_default(): void {
		$this->receiver->register_routes();

		$this->assertArrayNotHasKey(
			'almost-famous/v1/webhooks/incoming',
			af_test_get_rest_routes(),
			'The dormant receiver must not expose a REST route by default.'
		);
	}

	public function test_route_is_registered_when_filter_enables_webhooks(): void {
		add_filter( 'almost_famous/enable_webhooks', '__return_true' );

		$this->receiver->register_routes();

		$routes = af_test_get_rest_routes();
		$this->assertArrayHasKey( 'almost-famous/v1/webhooks/incoming', $routes );
		$this->assertSame( 'POST', $routes['almost-famous/v1/webhooks/incoming']['methods'] );
	}

	public function test_valid_signature_returns_200_ok(): void {
		// campaign.updated has a no-op cache path when account_id/campaign_id are missing.
		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated', 'idempotency_key' => 'evt-ok' ) );
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'ok', $resp->get_data()['status'] );
	}

	public function test_signature_signed_with_wrong_secret_rejected(): void {
		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body, 'a-completely-different-secret' );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'invalid_signature', $resp->get_data()['error'] );
	}

	public function test_missing_secret_option_rejects_all_signatures(): void {
		delete_option( 'af_webhook_secret' );

		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'invalid_signature', $resp->get_data()['error'] );
	}

	public function test_duplicate_idempotency_key_returns_409(): void {
		$body      = wp_json_encode(
			array( 'event_type' => 'campaign.updated', 'idempotency_key' => 'dup-key-1' )
		);
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$first  = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );
		$second = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'duplicate_event', $second->get_data()['error'] );
	}

	public function test_malformed_json_returns_400_invalid_payload(): void {
		$body      = '{this is not json';
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_payload', $resp->get_data()['error'] );
	}

	public function test_missing_event_type_returns_400(): void {
		$body      = wp_json_encode( array( 'idempotency_key' => 'no-type' ) );
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'missing_event_type', $resp->get_data()['error'] );
	}

	public function test_handler_exception_is_caught_and_returns_500(): void {
		// Swap in handlers that throw on dispatch.
		$throwing = new class( new Api_Cache(), new Api_Client( $this->auth ) ) extends Webhook_Handlers {
			public function dispatch( string $event_type, array $payload ): void {
				throw new RuntimeException( 'boom' );
			}
		};

		$receiver = new Webhook_Receiver( $throwing, $this->auth );

		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$resp = $receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 500, $resp->get_status() );
		$this->assertSame( 'handler_error', $resp->get_data()['error'] );
	}

	public function test_failed_event_is_not_marked_seen_so_retry_is_processed(): void {
		// A handler that throws once, then succeeds — mimics a transient
		// failure followed by the sender's retry.
		$flaky = new class( new Api_Cache() ) extends Webhook_Handlers {
			public int $calls = 0;
			public function dispatch( string $event_type, array $payload ): void {
				++$this->calls;
				if ( 1 === $this->calls ) {
					throw new RuntimeException( 'transient' );
				}
			}
		};

		$receiver = new Webhook_Receiver( $flaky, $this->auth );

		$body      = wp_json_encode(
			array( 'event_type' => 'campaign.updated', 'idempotency_key' => 'retry-me' )
		);
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		// First delivery fails (500) and must NOT record the idempotency key.
		$first = $receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );
		$this->assertSame( 500, $first->get_status() );
		$this->assertFalse( get_transient( 'af_webhook_seen_retry-me' ) );

		// The retry is processed (200), not deduplicated to 409.
		$second = $receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );
		$this->assertSame( 200, $second->get_status() );
		$this->assertNotFalse( get_transient( 'af_webhook_seen_retry-me' ) );
		$this->assertSame( 2, $flaky->calls );
	}

	public function test_event_log_caps_at_50_entries(): void {
		// Send 55 unique events; only the most recent 50 should remain.
		for ( $i = 0; $i < 55; $i++ ) {
			$body      = wp_json_encode(
				array(
					'event_type'      => 'campaign.updated',
					'idempotency_key' => 'log-entry-' . $i,
				)
			);
			$ts        = (string) time();
			$signature = $this->sign( $ts, $body );

			$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );
			$this->assertSame( 200, $resp->get_status() );
		}

		$log = get_transient( 'af_webhook_event_log' );
		$this->assertIsArray( $log );
		$this->assertCount( 50, $log );

		// Newest events sit at the head — entry 54 should still be in the log,
		// entry 0 should have been dropped.
		$this->assertSame( 'log-entry-54', $log[0]['idempotency_key'] );
		$keys = array_column( $log, 'idempotency_key' );
		$this->assertNotContains( 'log-entry-0', $keys );
		$this->assertNotContains( 'log-entry-4', $keys );
		$this->assertContains( 'log-entry-5', $keys );
	}
}
