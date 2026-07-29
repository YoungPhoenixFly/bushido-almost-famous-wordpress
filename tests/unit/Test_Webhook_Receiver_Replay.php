<?php
/**
 * Tests that the webhook receiver enforces an X-Bushido-Timestamp ±5 min
 * replay window and signs `timestamp.body` rather than `body` alone.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Webhooks\Webhook_Handlers;
use AlmostFamous\Webhooks\Webhook_Receiver;
use PHPUnit\Framework\TestCase;

class Test_Webhook_Receiver_Replay extends TestCase {

	private const SECRET = 'webhook-secret-for-tests';

	private Webhook_Receiver $receiver;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$auth = new Api_Auth();
		update_option( 'af_webhook_secret', $auth->encrypt( self::SECRET ) );

		$handlers       = new Webhook_Handlers( new \AlmostFamous\Api\Api_Cache() );
		$this->receiver = new Webhook_Receiver( $handlers, $auth );
	}

	private function build_request( string $body, ?string $signature, ?string $timestamp ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/almost-famous/v1/webhooks/incoming' );
		$req->set_body( $body );
		if ( null !== $signature ) {
			$req->set_header( 'X-Bushido-Signature', $signature );
		}
		if ( null !== $timestamp ) {
			$req->set_header( 'X-Bushido-Timestamp', $timestamp );
		}
		return $req;
	}

	private function sign( string $timestamp, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );
	}

	public function test_valid_timestamp_and_signature_accepted(): void {
		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated', 'idempotency_key' => 'abc' ) );
		$ts        = (string) time();
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 200, $resp->get_status() );
	}

	public function test_missing_timestamp_header_rejected(): void {
		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$signature = $this->sign( (string) time(), $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, null ) );

		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'missing_timestamp', $resp->get_data()['error'] );
	}

	public function test_old_timestamp_outside_window_rejected(): void {
		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$ts        = (string) ( time() - 700 ); // 11+ minutes old
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'replay_window', $resp->get_data()['error'] );
	}

	public function test_future_timestamp_outside_window_rejected(): void {
		$body      = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$ts        = (string) ( time() + 700 );
		$signature = $this->sign( $ts, $body );

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $signature, $ts ) );

		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'replay_window', $resp->get_data()['error'] );
	}

	public function test_signature_over_unsigned_timestamp_rejected(): void {
		$body         = wp_json_encode( array( 'event_type' => 'campaign.updated' ) );
		$ts           = (string) time();
		$wrong_sig    = hash_hmac( 'sha256', $body, self::SECRET ); // body-only, the old format

		$resp = $this->receiver->handle_webhook( $this->build_request( $body, $wrong_sig, $ts ) );

		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'invalid_signature', $resp->get_data()['error'] );
	}
}
