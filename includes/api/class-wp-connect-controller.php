<?php
/**
 * WordPress "Connect this site" handshake controller.
 *
 * Browser flow:
 *   1. Admin clicks "Connect this site to Bushido" in the setup wizard.
 *      That link points at /almost-famous/v1/wp-connect/start.
 *   2. start() generates a per-user CSRF state nonce, stores it in a
 *      10-minute transient, then 302s the browser to
 *      the configured Bushido app's /almost-famous/wp-connect page with site_url,
 *      site_name, blog_id, state, and return_url query params.
 *   3. The configured Bushido consent page calls
 *      `trpc.almostFamous.wpConnect.issue` which mints an API key and
 *      writes an AfWpConnectCode row, then redirects the browser to
 *      return_url (= /almost-famous/v1/wp-connect/callback) with the
 *      one-time `code` and the original `state`.
 *   4. callback() validates state against the transient, POSTs the code
 *      to `/api/v1/public/setup/wp-exchange`, durably records the pending
 *      delivery, stores the key and all metadata, and acknowledges it.
 *      Failed acknowledgements and compensating aborts are retried from
 *      the durable record on subsequent WordPress requests.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

use AlmostFamous\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the WP-side of the Connect-WP OAuth-style flow.
 */
class Wp_Connect_Controller {

	/**
	 * Lifetime of the per-user CSRF state token (seconds).
	 */
	private const STATE_TTL = 600;

	/**
	 * Non-autoloaded option containing an encrypted setup code while an
	 * acknowledgement, abort, or local rollback still needs confirmation.
	 */
	private const DELIVERY_OPTION = 'af_wp_connect_pending_delivery';

	/**
	 * Background hook and short-lived mutex for delivery retries.
	 */
	public const DELIVERY_RETRY_HOOK   = 'almost_famous_retry_wp_delivery';
	private const DELIVERY_LOCK_OPTION = 'af_wp_connect_delivery_lock';
	private const DELIVERY_RETRY_DELAY = 60;

	/**
	 * All local values that form one connection and must commit together.
	 *
	 * @var array<int, string>
	 */
	private const CONNECTION_OPTIONS = array(
		'af_api_key',
		'af_setup_complete',
		'af_org_id',
		'af_org_channel_id',
		'af_org_channel_name',
		'af_org_credential_mode',
	);

	/**
	 * API client used to call /setup/wp-exchange after the bounce-back.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Auth helper used to encrypt and persist the returned API key.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * Prevent recursive retry helpers from reacquiring the cross-request lock.
	 *
	 * @var bool
	 */
	private bool $retrying = false;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api  Bushido API client (used for the exchange call).
	 * @param Api_Auth   $auth API auth / storage helper.
	 */
	public function __construct( Api_Client $api, Api_Auth $auth ) {
		$this->api  = $api;
		$this->auth = $auth;
	}

	/**
	 * Allow the Bushido app host as a wp_safe_redirect() destination.
	 *
	 * @param array<int, string> $hosts Allowed redirect hosts.
	 * @return array<int, string>
	 */
	public function allow_app_redirect_host( array $hosts ): array {
		$app_host = wp_parse_url( Config::resolve_bushido_app_url(), PHP_URL_HOST );

		if ( is_string( $app_host ) && '' !== $app_host && ! in_array( $app_host, $hosts, true ) ) {
			$hosts[] = $app_host;
		}

		return $hosts;
	}

	/**
	 * Register the two REST routes under almost-famous/v1/wp-connect/*.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// wp_safe_redirect() only allows same-host destinations by default, so
		// without this the redirect to the Bushido consent page is silently
		// rewritten to wp-admin and the one-click connect flow dead-ends.
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_app_redirect_host' ) );

		register_rest_route(
			'almost-famous/v1',
			'/wp-connect/start',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'can_manage_setup' ),
			)
		);

		register_rest_route(
			'almost-famous/v1',
			'/wp-connect/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'callback' ),
				// Authenticated via the per-user state transient set in
				// start(); WP cookie-auth's `_wpnonce` requirement would
				// reject the redirect from the Bushido app (which never sees
				// a WP nonce). The handler verifies state with
				// hash_equals before touching anything.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission gate. Only admins (manage_options) can initiate or
	 * complete the WP-connect handshake — the resulting API key controls
	 * billing, ad spend, and platform credentials.
	 *
	 * @return bool
	 */
	public function can_manage_setup(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Start the handshake — set the CSRF state and bounce to the
	 * Bushido consent page.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->retry_pending_delivery();
		if ( false !== get_option( self::DELIVERY_OPTION, false ) ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => 'delivery_confirmation_pending',
				)
			);
			return;
		}

		$state = wp_generate_password( 32, false );
		// Store a marker keyed on the random state. The state value is itself
		// the secret — finding the transient means whoever holds the state
		// is the legitimate caller. Keyed on `state` (not user id) so the
		// callback dispatch — which the WP REST router runs without a fully-
		// initialised user context — can still consume it.
		set_transient(
			$this->state_transient_key( $state ),
			'1',
			self::STATE_TTL
		);

		$site_url   = home_url();
		$site_name  = get_bloginfo( 'name' );
		$blog_id    = is_multisite() ? get_current_blog_id() : null;
		$return_url = rest_url( 'almost-famous/v1/wp-connect/callback' );

		$params = array(
			'site_url'   => $site_url,
			'site_name'  => $site_name,
			'state'      => $state,
			'return_url' => $return_url,
		);
		if ( null !== $blog_id ) {
			$params['blog_id'] = (string) $blog_id;
		}

		$destination = trailingslashit( Config::resolve_bushido_app_url() ) . 'almost-famous/wp-connect';
		$destination = add_query_arg( array_map( 'rawurlencode', $params ), $destination );

		// Note: add_query_arg double-encodes; we URL-decoded keys back by
		// using rawurlencode above and then letting add_query_arg add the
		// query string. Strip the extra layer to keep the query readable
		// on the Bushido side.
		$destination = $this->normalize_query_args( $destination, $params );

		wp_safe_redirect( $destination, 302, 'almost-famous-plugin' );
		exit;
	}

	/**
	 * Handle the bounce-back. Verify state, exchange the code, persist
	 * the API key, then send the admin to the wizard's confirmation step.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return void
	 */
	public function callback( \WP_REST_Request $request ): void {
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );
		$error = (string) $request->get_param( 'error' );

		if ( '' !== $error ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'cancelled',
					'af_setup_error' => $error,
				)
			);
			return;
		}

		if ( '' === $code || '' === $state ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => 'missing_parameters',
				)
			);
			return;
		}

		$key      = $this->state_transient_key( $state );
		$expected = get_transient( $key );

		if ( false === $expected ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => 'invalid_state',
				)
			);
			return;
		}

		// A setup code is itself a short-lived credential. Encrypt it before
		// contacting the backend so a delivered exchange can always be placed
		// in durable retry state without ever storing the code in plaintext.
		$encrypted_code = $this->auth->encrypt( $code );
		if ( '' === $encrypted_code ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => 'delivery_encryption_failed',
				)
			);
			return;
		}

		$this->retry_pending_delivery();
		if ( false !== get_option( self::DELIVERY_OPTION, false ) ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => 'delivery_confirmation_pending',
				)
			);
			return;
		}

		// Persist the encrypted code and previous local connection before the
		// first exchange request. If the HTTP response or PHP request is lost,
		// init can replay the same server delivery without losing the old key.
		$delivery = $this->new_delivery_state( 'exchange', $encrypted_code, '' );
		if ( ! $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => 'delivery_state_storage_failed',
				)
			);
			return;
		}

		delete_transient( $key );
		$acknowledged    = $this->retry_pending_delivery();
		$pending         = get_option( self::DELIVERY_OPTION, false );
		$committed       = true === get_option( 'af_setup_complete', false )
			&& '' !== $this->auth->decrypt_api_key();
		$credential_mode = (string) get_option( 'af_org_credential_mode', '' );

		if ( ! $committed ) {
			$this->redirect_to_wizard(
				array(
					'af_setup'       => 'error',
					'af_setup_error' => is_array( $pending ) && 'exchange' === ( $pending['action'] ?? '' )
						? 'exchange_retry_pending'
						: 'delivery_confirmation_pending',
				)
			);
			return;
		}

		delete_transient( $key );

		$this->redirect_to_wizard(
			array(
				'af_setup'           => 'success',
				'af_credential_mode' => $credential_mode,
				'af_setup_sync'      => $acknowledged ? 'confirmed' : 'pending',
			)
		);
	}

	/**
	 * Retry an unfinished acknowledgement, compensation, or local rollback.
	 *
	 * This method is safe to call repeatedly. Backend acknowledgement and
	 * abort endpoints are idempotent, and local restoration verifies every
	 * resulting option before deleting the durable delivery record.
	 *
	 * @return bool True when no retry work remains.
	 */
	public function retry_pending_delivery(): bool {
		if ( $this->retrying ) {
			return $this->retry_pending_delivery_unlocked();
		}

		$lock_token = wp_generate_uuid4();
		$lock       = array(
			'token'      => $lock_token,
			'expires_at' => time() + 120,
		);
		if ( ! add_option( self::DELIVERY_LOCK_OPTION, $lock, '', 'no' ) ) {
			$existing_lock = get_option( self::DELIVERY_LOCK_OPTION, array() );
			if (
				! is_array( $existing_lock )
				|| '' === (string) ( $existing_lock['token'] ?? '' )
				|| (int) ( $existing_lock['expires_at'] ?? 0 ) <= time()
			) {
				// Re-acquire immediately after removing a stale or malformed
				// lease. Invalid option data must not permanently block setup.
				if ( delete_option( self::DELIVERY_LOCK_OPTION ) ) {
					add_option( self::DELIVERY_LOCK_OPTION, $lock, '', 'no' );
				}
			}
		}
		$stored_lock = get_option( self::DELIVERY_LOCK_OPTION, array() );
		if (
			! is_array( $stored_lock )
			|| ! hash_equals( $lock_token, (string) ( $stored_lock['token'] ?? '' ) )
		) {
			$this->schedule_pending_delivery_retry();
			return false;
		}

		$this->retrying = true;
		try {
			$complete = $this->retry_pending_delivery_unlocked();
			if ( ! $complete ) {
				$this->schedule_pending_delivery_retry();
			} else {
				wp_clear_scheduled_hook( self::DELIVERY_RETRY_HOOK );
			}
			return $complete;
		} finally {
			$this->retrying = false;
			$stored_lock    = get_option( self::DELIVERY_LOCK_OPTION, array() );
			if ( is_array( $stored_lock ) && hash_equals( $lock_token, (string) ( $stored_lock['token'] ?? '' ) ) ) {
				delete_option( self::DELIVERY_LOCK_OPTION );
			}
		}
	}

	/**
	 * Queue retry work without performing network I/O on a public request.
	 *
	 * @return void
	 */
	public function schedule_pending_delivery_retry(): void {
		if (
			false !== get_option( self::DELIVERY_OPTION, false )
			&& false === wp_next_scheduled( self::DELIVERY_RETRY_HOOK )
		) {
			wp_schedule_single_event(
				time() + self::DELIVERY_RETRY_DELAY,
				self::DELIVERY_RETRY_HOOK
			);
		}
	}

	/**
	 * Process a retry while the durable cross-request mutex is held.
	 *
	 * @return bool True when no retry work remains.
	 */
	private function retry_pending_delivery_unlocked(): bool {
		$delivery = get_option( self::DELIVERY_OPTION, false );
		if ( false === $delivery ) {
			return true;
		}
		if (
			! is_array( $delivery )
			|| ! isset( $delivery['action'], $delivery['encrypted_code'], $delivery['api_key_id'], $delivery['previous'] )
			|| ! is_string( $delivery['action'] )
			|| ! is_string( $delivery['encrypted_code'] )
			|| ! is_string( $delivery['api_key_id'] )
			|| ! is_array( $delivery['previous'] )
		) {
			return false;
		}

		$action = $delivery['action'];
		if ( 'exchange' === $action ) {
			return $this->finish_exchange( $delivery );
		}
		if ( 'commit' === $action ) {
			return $this->finish_local_commit( $delivery );
		}
		if ( 'restore' === $action ) {
			return $this->finish_local_restore( $delivery );
		}
		if ( ! in_array( $action, array( 'ack', 'abort' ), true ) ) {
			return false;
		}

		$code = $this->auth->decrypt( $delivery['encrypted_code'] );
		if ( '' === $code || '' === $delivery['api_key_id'] ) {
			return false;
		}

		if ( 'ack' === $action ) {
			$result = $this->api->acknowledge_setup_exchange( $code, $delivery['api_key_id'] );
			if ( ! $this->is_confirmed( $result ) ) {
				if ( $this->is_terminal_gone( $result ) ) {
					$probe        = $this->api->probe_current_key();
					$probe_status = (int) ( $probe['status'] ?? 0 );
					if ( ! isset( $probe['error'] ) && $probe_status >= 200 && $probe_status < 300 ) {
						return $this->delete_option_verified( self::DELIVERY_OPTION );
					}
					if ( ! in_array( $probe_status, array( 401, 403 ), true ) ) {
						return false;
					}
					$delivery['action'] = 'restore';
					if ( ! $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
						return false;
					}
					return $this->finish_local_restore( $delivery );
				}
				return false;
			}
			return $this->delete_option_verified( self::DELIVERY_OPTION );
		}

		$result = $this->api->abort_setup_exchange( $code, $delivery['api_key_id'] );
		if ( ! $this->is_confirmed( $result ) ) {
			if ( ! $this->is_terminal_gone( $result ) ) {
				return false;
			}
		}

		$delivery['action'] = 'restore';
		if ( ! $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
			return false;
		}
		return $this->finish_local_restore( $delivery );
	}

	/**
	 * Replay the setup exchange and durably seal its response before touching
	 * any local connection option.
	 *
	 * @param array<string, mixed> $delivery Durable pre-exchange record.
	 * @return bool True only when the complete delivery reaches acknowledgement.
	 */
	private function finish_exchange( array $delivery ): bool {
		$code = $this->auth->decrypt( $delivery['encrypted_code'] );
		if ( '' === $code ) {
			return false;
		}

		$result = $this->api->exchange_setup_code( $code );
		if ( isset( $result['error'] ) ) {
			return false;
		}

		$data            = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$api_key         = (string) ( $data['apiKey'] ?? '' );
		$api_key_id      = (string) ( $data['apiKeyId'] ?? '' );
		$org_id          = (string) ( $data['orgId'] ?? '' );
		$channel_id      = (string) ( $data['channelId'] ?? '' );
		$channel_name    = (string) ( $data['channelName'] ?? '' );
		$credential_mode = (string) ( $data['credentialMode'] ?? '' );

		if (
			'' === $api_key
			|| '' === $api_key_id
			|| '' === $org_id
			|| '' === $channel_id
			|| ! in_array( $credential_mode, array( 'agency', 'own' ), true )
		) {
			if ( '' !== $api_key_id ) {
				$delivery['api_key_id'] = $api_key_id;
				$delivery['action']     = 'abort';
				if ( $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
					return $this->retry_pending_delivery();
				}
			}
			return false;
		}

		$encrypted_api_key = $this->auth->encrypt( $api_key );
		if ( '' === $encrypted_api_key ) {
			$delivery['api_key_id'] = $api_key_id;
			$delivery['action']     = 'abort';
			if ( $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
				return $this->retry_pending_delivery();
			}
			return false;
		}

		$delivery['action']            = 'commit';
		$delivery['api_key_id']        = $api_key_id;
		$delivery['encrypted_api_key'] = $encrypted_api_key;
		$delivery['metadata']          = array(
			'af_setup_complete'      => true,
			'af_org_id'              => $org_id,
			'af_org_channel_id'      => $channel_id,
			'af_org_channel_name'    => $channel_name,
			'af_org_credential_mode' => $credential_mode,
		);
		if ( ! $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
			return false;
		}

		return $this->finish_local_commit( $delivery );
	}

	/**
	 * Persist the sealed key and complete metadata, then switch the durable
	 * state to acknowledgement. Any local failure compensates only the pending
	 * server key and restores the prior connection.
	 *
	 * @param array<string, mixed> $delivery Durable commit record.
	 * @return bool True only after backend acknowledgement is confirmed.
	 */
	private function finish_local_commit( array $delivery ): bool {
		if (
			! isset( $delivery['encrypted_api_key'], $delivery['metadata'] )
			|| ! is_string( $delivery['encrypted_api_key'] )
			|| ! is_array( $delivery['metadata'] )
			|| '' === $delivery['api_key_id']
		) {
			return false;
		}

		$required_metadata = array(
			'af_setup_complete',
			'af_org_id',
			'af_org_channel_id',
			'af_org_channel_name',
			'af_org_credential_mode',
		);
		foreach ( $required_metadata as $option_name ) {
			if ( ! array_key_exists( $option_name, $delivery['metadata'] ) ) {
				return false;
			}
		}
		if (
			'' === (string) $delivery['metadata']['af_org_id']
			|| '' === (string) $delivery['metadata']['af_org_channel_id']
			|| ! in_array( $delivery['metadata']['af_org_credential_mode'], array( 'agency', 'own' ), true )
		) {
			return $this->abort_and_restore( $delivery );
		}

		$api_key = $this->auth->decrypt( $delivery['encrypted_api_key'] );
		if ( '' === $api_key || ! $this->auth->store_api_key( $api_key ) ) {
			return $this->abort_and_restore( $delivery );
		}

		foreach ( $required_metadata as $option_name ) {
			if ( ! $this->persist_option( $option_name, $delivery['metadata'][ $option_name ] ) ) {
				return $this->abort_and_restore( $delivery );
			}
		}

		$delivery['action'] = 'ack';
		unset( $delivery['encrypted_api_key'], $delivery['metadata'] );
		if ( ! $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
			$this->restore_local_connection( $delivery );
			return false;
		}

		return $this->retry_pending_delivery();
	}

	/**
	 * Switch a failed local commit to durable abort and restore the old local
	 * connection. The server still has the old key as current until this abort
	 * is confirmed, so the restored key cannot be one retired by this flow.
	 *
	 * @param array<string, mixed> $delivery Durable delivery record.
	 * @return bool True when compensation and restoration complete.
	 */
	private function abort_and_restore( array $delivery ): bool {
		$delivery['action'] = 'abort';
		unset( $delivery['encrypted_api_key'], $delivery['metadata'] );
		if ( ! $this->persist_option( self::DELIVERY_OPTION, $delivery ) ) {
			$this->restore_local_connection( $delivery );
			return false;
		}
		if ( ! $this->restore_local_connection( $delivery ) ) {
			return false;
		}
		return $this->retry_pending_delivery();
	}

	/**
	 * Create a durable delivery record, including the previous connection.
	 *
	 * @param string $action         Initial action (`abort` before commit).
	 * @param string $encrypted_code Authenticated ciphertext for setup code.
	 * @param string $api_key_id     Exact delivered key identifier.
	 * @return array<string, mixed>
	 */
	private function new_delivery_state( string $action, string $encrypted_code, string $api_key_id ): array {
		return array(
			'version'        => 1,
			'action'         => $action,
			'encrypted_code' => $encrypted_code,
			'api_key_id'     => $api_key_id,
			'previous'       => $this->capture_local_connection(),
		);
	}

	/**
	 * Capture raw local connection values so a failed reconnect can restore
	 * the old encrypted key without exposing its plaintext.
	 *
	 * @return array<string, array{exists: bool, value: mixed}>
	 */
	private function capture_local_connection(): array {
		$snapshot = array();
		$missing  = new \stdClass();
		foreach ( self::CONNECTION_OPTIONS as $option_name ) {
			$value                    = get_option( $option_name, $missing );
			$snapshot[ $option_name ] = array(
				'exists' => $missing !== $value,
				'value'  => $missing === $value ? null : $value,
			);
		}
		return $snapshot;
	}

	/**
	 * Restore the local connection snapshot after a confirmed abort.
	 *
	 * @param array<string, mixed> $delivery Durable delivery record.
	 * @return bool True when rollback and delivery-record removal complete.
	 */
	private function finish_local_restore( array $delivery ): bool {
		if ( ! $this->restore_local_connection( $delivery ) ) {
			return false;
		}
		return $this->delete_option_verified( self::DELIVERY_OPTION );
	}

	/**
	 * Restore the previous local connection without removing retry state.
	 *
	 * @param array<string, mixed> $delivery Durable delivery record.
	 * @return bool True when every previous option is restored.
	 */
	private function restore_local_connection( array $delivery ): bool {
		foreach ( self::CONNECTION_OPTIONS as $option_name ) {
			$entry = $delivery['previous'][ $option_name ] ?? null;
			if ( ! is_array( $entry ) || ! array_key_exists( 'exists', $entry ) || ! array_key_exists( 'value', $entry ) ) {
				return false;
			}
			$restored = true === $entry['exists']
				? $this->persist_option( $option_name, $entry['value'] )
				: $this->delete_option_verified( $option_name );
			if ( ! $restored ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Persist an option and distinguish "unchanged" from an actual failure.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Expected value.
	 * @return bool True when the exact value is stored.
	 */
	private function persist_option( string $option_name, mixed $value ): bool {
		if ( update_option( $option_name, $value, false ) ) {
			return true;
		}
		$missing = new \stdClass();
		return get_option( $option_name, $missing ) === $value;
	}

	/**
	 * Delete an option and verify absence when WordPress reports no change.
	 *
	 * @param string $option_name Option name.
	 * @return bool True when the option is absent.
	 */
	private function delete_option_verified( string $option_name ): bool {
		if ( delete_option( $option_name ) ) {
			return true;
		}
		$missing = new \stdClass();
		return get_option( $option_name, $missing ) === $missing;
	}

	/**
	 * Determine whether a public delivery endpoint confirmed success.
	 *
	 * @param array<string, mixed> $result API-client response envelope.
	 * @return bool True only for an error-free 2xx response.
	 */
	private function is_confirmed( array $result ): bool {
		$status = (int) ( $result['status'] ?? 0 );
		return ! isset( $result['error'] ) && $status >= 200 && $status < 300;
	}

	/**
	 * A terminal gone response means the server can no longer accept this
	 * delivery transition and local state must be reconciled.
	 *
	 * @param array<string, mixed> $result API-client response envelope.
	 * @return bool True for HTTP 410.
	 */
	private function is_terminal_gone( array $result ): bool {
		return 410 === (int) ( $result['status'] ?? 0 );
	}

	/**
	 * Transient key for a CSRF state value.
	 *
	 * The state itself is the secret (32 chars from wp_generate_password),
	 * so storing a marker keyed on the state — rather than a per-user
	 * lookup — lets the REST callback consume it without depending on
	 * WP cookie-auth being initialised on that request.
	 *
	 * @param string $state The state nonce.
	 * @return string
	 */
	private function state_transient_key( string $state ): string {
		return 'af_wp_connect_state_' . md5( $state );
	}

	/**
	 * Normalize the consent URL so query args end up properly encoded
	 * exactly once.
	 *
	 * @param string $url    Output from add_query_arg.
	 * @param array  $params Original params (already rawurlencoded once).
	 * @return string
	 */
	private function normalize_query_args( string $url, array $params ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) ) {
			return $url;
		}

		$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$base  = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$base .= ':' . (string) $parts['port'];
		}
		return $base . $parts['path'] . '?' . $query;
	}

	/**
	 * Redirect the browser to the setup wizard step 3 with the supplied
	 * query args.
	 *
	 * @param array $args Query args to append.
	 * @return void
	 */
	private function redirect_to_wizard( array $args ): void {
		$base = admin_url( 'admin.php?page=af-setup-wizard&step=3' );
		$url  = add_query_arg( $args, $base );
		wp_safe_redirect( $url, 302, 'almost-famous-plugin' );
		exit;
	}
}
