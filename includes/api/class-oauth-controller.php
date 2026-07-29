<?php
/**
 * Backend-bounce OAuth Connect / Callback / Disconnect controller.
 *
 * Browser flow:
 *   1. Admin clicks "Connect Meta" → JS POSTs /almost-famous/v1/oauth/start.
 *   2. Plugin generates a client_state nonce, persists it as a per-user
 *      transient, then POSTs to backend `/auth/connect/:platform` with this
 *      plugin's REST callback URL as redirectUri. Backend responds with the
 *      platform's authorization URL.
 *   3. Plugin appends client_state to that URL and returns it; JS redirects.
 *   4. After consenting on the platform, backend handles the OAuth dance and
 *      302s the browser back to /almost-famous/v1/oauth/callback with
 *      ?status=success&credentialId=...&client_state=...
 *   5. Plugin validates client_state via hash_equals, consumes the transient,
 *      fetches the connection detail from backend, persists it as
 *      af_accounts[platform], and redirects to wp-admin with a success flag.
 *
 * Disconnect calls DELETE /auth/connections/:id, then drops the local row.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

/**
 * REST controller for OAuth-via-backend.
 */
class Oauth_Controller {

	/**
	 * Platforms the backend currently exposes a /auth/connect/{platform} endpoint for.
	 */
	public const SUPPORTED_PLATFORMS = array( 'meta', 'google', 'tiktok', 'spotify' );

	/**
	 * Transient TTL for the per-user CSRF state token (seconds).
	 */
	private const STATE_TTL = 600;

	/**
	 * Bushido API HTTP client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $api;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $api Bushido API client.
	 */
	public function __construct( Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Register the three REST routes under almost-famous/v1/oauth/*.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'almost-famous/v1',
			'/oauth/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'can_manage_accounts' ),
				'args'                => array(
					'platform' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'almost-famous/v1',
			'/oauth/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'callback' ),
				// Authenticated via the signed client_state nonce, not WP auth.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'almost-famous/v1',
			'/oauth/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'can_manage_accounts' ),
				'args'                => array(
					'platform' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback for start/disconnect: requires af_manage_accounts
	 * (with manage_options falling through for full admins).
	 *
	 * @return bool
	 */
	public function can_manage_accounts(): bool {
		return current_user_can( 'af_manage_accounts' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Begin the OAuth flow for a given platform.
	 *
	 * @param \WP_REST_Request $req REST request.
	 * @return \WP_REST_Response Authorization URL plus the embedded client_state.
	 */
	public function start( \WP_REST_Request $req ): \WP_REST_Response {
		$platform = strtolower( (string) $req->get_param( 'platform' ) );

		if ( ! in_array( $platform, self::SUPPORTED_PLATFORMS, true ) ) {
			return new \WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'invalid_platform',
						'message' => 'Unsupported platform.',
					),
				),
				400
			);
		}

		$state   = bin2hex( random_bytes( 16 ) );
		$user_id = get_current_user_id();

		set_transient(
			'af_oauth_state_' . $user_id,
			array(
				'state'    => $state,
				'platform' => $platform,
			),
			self::STATE_TTL
		);

		/*
		 * Backend enum is uppercase per the public PLATFORM_VALUES contract.
		 *
		 * We deliberately do NOT pass a `redirectUri` to the backend. Doing so
		 * would require every customer's WordPress origin to be added to the
		 * backend's ALLOWED_REDIRECT_URIS allowlist (env var, set at deploy
		 * time) — which doesn't scale to a publicly-distributed plugin.
		 *
		 * Without redirectUri the backend completes OAuth and lands the user
		 * on its own dashboard with `?connected=<platform>&credentialId=...`.
		 * The user clicks "Return to your site" there to come back to wp-admin;
		 * the Accounts page reconciles its local state against
		 * /auth/connections on every render, so the newly-connected platform
		 * shows up regardless of which path the user took back.
		 */
		$result = $this->api->post(
			'/auth/connect/' . strtoupper( $platform ),
			array()
		);

		if ( isset( $result['error'] ) && $result['error'] instanceof Api_Error ) {
			$err = $result['error'];
			return new \WP_REST_Response(
				array(
					'error' => array(
						'code'    => $err->code,
						'message' => $err->message,
					),
				),
				$err->http_status > 0 ? $err->http_status : 502
			);
		}

		$data     = $result['data'] ?? array();
		$auth_url = is_array( $data ) ? (string) ( $data['authorizationUrl'] ?? '' ) : '';

		if ( '' === $auth_url ) {
			return new \WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'no_auth_url',
						'message' => 'Backend returned no authorization URL.',
					),
				),
				502
			);
		}

		$separator = str_contains( $auth_url, '?' ) ? '&' : '?';
		$auth_url .= $separator . 'client_state=' . rawurlencode( $state );

		return new \WP_REST_Response( array( 'authorizationUrl' => $auth_url ), 200 );
	}

	/**
	 * Handle the redirect-back from the backend after the user finishes OAuth.
	 *
	 * Always returns a 302 redirect to wp-admin so the browser keeps a familiar
	 * URL — success or failure detail is encoded in the query string for the
	 * Accounts page to render as an admin notice.
	 *
	 * @param \WP_REST_Request $req REST request.
	 * @return \WP_REST_Response 302 redirect to wp-admin.
	 */
	public function callback( \WP_REST_Request $req ): \WP_REST_Response {
		$user_id   = get_current_user_id();
		$stored    = get_transient( 'af_oauth_state_' . $user_id );
		$admin_url = admin_url( 'admin.php?page=af-accounts' );

		if ( ! is_array( $stored ) || empty( $stored['state'] ) ) {
			return $this->redirect( add_query_arg( 'af_connect_error', 'missing_state', $admin_url ) );
		}

		// Consume the state immediately to prevent replay regardless of outcome.
		delete_transient( 'af_oauth_state_' . $user_id );

		$client_state = (string) $req->get_param( 'client_state' );
		if ( ! hash_equals( (string) $stored['state'], $client_state ) ) {
			return $this->redirect( add_query_arg( 'af_connect_error', 'invalid_state', $admin_url ) );
		}

		$status = (string) $req->get_param( 'status' );
		if ( 'success' !== $status ) {
			$reason = (string) $req->get_param( 'error' );
			$reason = '' !== $reason ? $reason : 'oauth_failed';
			return $this->redirect( add_query_arg( 'af_connect_error', $reason, $admin_url ) );
		}

		$credential_id = (string) $req->get_param( 'credentialId' );
		if ( '' === $credential_id ) {
			return $this->redirect( add_query_arg( 'af_connect_error', 'missing_credential', $admin_url ) );
		}

		// The backend has no GET /auth/connections/{id} route; get_connection()
		// resolves the credential from the connections list.
		$conn     = $this->api->get_connection( $credential_id );
		$platform = strtolower( (string) ( $conn?->platform ?? $stored['platform'] ?? '' ) );

		if ( '' === $platform ) {
			return $this->redirect( add_query_arg( 'af_connect_error', 'backend_error', $admin_url ) );
		}

		$accounts              = (array) get_option( 'af_accounts', array() );
		$accounts[ $platform ] = array(
			'credentialId' => $credential_id,
			'accountId'    => (string) ( $conn?->accountId ?? '' ),
			'accountName'  => (string) ( $conn?->accountName ?? '' ),
			'status'       => '' !== (string) ( $conn?->status ?? '' ) ? (string) $conn->status : 'active',
			'connectedAt'  => time(),
		);
		update_option( 'af_accounts', $accounts );

		return $this->redirect( add_query_arg( 'af_connected', $platform, $admin_url ) );
	}

	/**
	 * Revoke a platform connection both at the backend and locally.
	 *
	 * @param \WP_REST_Request $req REST request.
	 * @return \WP_REST_Response
	 */
	public function disconnect( \WP_REST_Request $req ): \WP_REST_Response {
		$platform = strtolower( (string) $req->get_param( 'platform' ) );
		$accounts = (array) get_option( 'af_accounts', array() );

		if ( ! isset( $accounts[ $platform ] ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$credential_id = (string) ( $accounts[ $platform ]['credentialId'] ?? '' );
		if ( '' !== $credential_id ) {
			$result = $this->api->delete( '/auth/connections/' . $credential_id );

			if ( isset( $result['error'] ) && $result['error'] instanceof Api_Error ) {
				$err = $result['error'];
				return new \WP_REST_Response(
					array(
						'error' => array(
							'code'    => $err->code,
							'message' => $err->message,
						),
					),
					$err->http_status > 0 ? $err->http_status : 502
				);
			}
		}

		unset( $accounts[ $platform ] );
		update_option( 'af_accounts', $accounts );

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Build a 302 redirect response carrying a Location header.
	 *
	 * @param string $url Target URL.
	 * @return \WP_REST_Response
	 */
	private function redirect( string $url ): \WP_REST_Response {
		$resp = new \WP_REST_Response( null, 302 );
		$resp->header( 'Location', $url );
		return $resp;
	}
}
