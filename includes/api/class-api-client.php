<?php
/**
 * Bushido API HTTP client wrapper.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

use AlmostFamous\Config;
use AlmostFamous\Enums\Error_Code;

/**
 * Core HTTP client for Bushido OpenAPI communication.
 *
 * All requests go through wp_remote_request with:
 * - X-API-Key: {decrypted_api_key}
 * - X-Bushido-Plugin-Version: {ALMOST_FAMOUS_VERSION}
 * - X-Idempotency-Key: {uuid} (on mutating requests)
 * - Content-Type: application/json
 * - sslverify: true (TLS 1.2+)
 */
class Api_Client {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 15;

	/**
	 * API authentication handler.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * Constructor.
	 *
	 * @param Api_Auth $auth API authentication handler.
	 */
	public function __construct( Api_Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Send a GET request to the Bushido API.
	 *
	 * @param string $endpoint API endpoint path (e.g., '/campaigns').
	 * @param array  $params   Optional query parameters.
	 * @param string $etag     Optional ETag for conditional request (If-None-Match).
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object, not_modified?: bool}
	 */
	public function get( string $endpoint, array $params = array(), string $etag = '' ): array {
		$url = $this->build_url( $endpoint );

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$extra_headers = array();
		if ( '' !== $etag ) {
			$extra_headers['If-None-Match'] = $etag;
		}

		return $this->request( 'GET', $url, array(), $extra_headers );
	}

	/**
	 * Send a GET to a top-level Bushido API path (no /public/ auto-prefix).
	 *
	 * Use for endpoints that live at `/api/v1/<path>` directly such as
	 * `/auth/validate` or `/health`. Regular org-scoped routes should keep
	 * using get() so the /public/ prefix is added.
	 *
	 * @param string $endpoint Path relative to /api/v1 (e.g. '/auth/validate').
	 * @param array  $params   Optional query parameters.
	 * @return array Same envelope as get().
	 */
	public function get_top( string $endpoint, array $params = array() ): array {
		$url = $this->build_top_url( $endpoint );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}
		return $this->request( 'GET', $url );
	}

	/**
	 * Probe whether the currently stored API key is still accepted.
	 *
	 * Unlike get_own_org(), this retains the HTTP status so recovery code can
	 * distinguish an invalid credential from a transient network failure.
	 *
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object}
	 */
	public function probe_current_key(): array {
		return $this->get_top( '/auth/validate' );
	}

	/**
	 * Send a POST request to the Bushido API.
	 *
	 * @param string $endpoint        API endpoint path.
	 * @param array  $data            Request body data.
	 * @param string $idempotency_key Optional stable idempotency key.
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object}
	 */
	public function post( string $endpoint, array $data = array(), string $idempotency_key = '' ): array {
		$url     = $this->build_url( $endpoint );
		$headers = '' !== $idempotency_key
			? array( 'X-Idempotency-Key' => $idempotency_key )
			: array();
		return $this->request( 'POST', $url, $data, $headers );
	}

	/**
	 * Exchange a WordPress setup code for an API key.
	 *
	 * Posts to the public, unauthenticated `/setup/wp-exchange` endpoint.
	 * The code itself is the credential — no API key is required and the
	 * normal `request()` flow can't be reused because it short-circuits
	 * to 403 when no key is stored locally.
	 *
	 * @param string $code The one-time setup code from the consent page.
	 * @return array{data?: array, error?: Api_Error, status: int}
	 */
	public function exchange_setup_code( string $code ): array {
		$url      = $this->build_url( '/setup/wp-exchange' );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'             => 'application/json',
					'Accept'                   => 'application/json',
					'X-Bushido-Plugin-Version' => ALMOST_FAMOUS_VERSION,
				),
				'body'    => wp_json_encode( array( 'code' => $code ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'error'  => new Api_Error(
					Error_Code::API_UNREACHABLE->value,
					__( 'Could not reach the Bushido API to complete setup.', 'bushido-almost-famous' ),
					$response->get_error_message(),
					503
				),
				'status' => 503,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $body ) ) {
			return array(
				'data'   => $body,
				'status' => $status,
			);
		}

		$message = is_array( $body ) && isset( $body['message'] )
			? (string) $body['message']
			: __( 'Setup exchange failed.', 'bushido-almost-famous' );

		return array(
			'error'  => new Api_Error( 'setup_exchange_failed', $message, '', $status ),
			'status' => $status,
		);
	}

	/**
	 * Compensate a setup exchange whose plaintext key could not be persisted.
	 *
	 * @param string $code       Consumed one-time setup code.
	 * @param string $api_key_id Exact key returned by the exchange.
	 * @return array{data?: array, error?: Api_Error, status: int}
	 */
	public function abort_setup_exchange( string $code, string $api_key_id ): array {
		return $this->confirm_setup_exchange(
			'/setup/wp-abort',
			$code,
			$api_key_id,
			'setup_abort_failed',
			__( 'Could not compensate the failed setup exchange.', 'bushido-almost-famous' ),
			__( 'Failed to compensate the setup exchange.', 'bushido-almost-famous' )
		);
	}

	/**
	 * Confirm that a setup exchange was stored durably by WordPress.
	 *
	 * @param string $code       Delivered setup code.
	 * @param string $api_key_id Exact key returned by the exchange.
	 * @return array{data?: array, error?: Api_Error, status: int}
	 */
	public function acknowledge_setup_exchange( string $code, string $api_key_id ): array {
		return $this->confirm_setup_exchange(
			'/setup/wp-ack',
			$code,
			$api_key_id,
			'setup_acknowledgement_failed',
			__( 'Could not confirm the completed setup exchange.', 'bushido-almost-famous' ),
			__( 'Failed to confirm the completed setup exchange.', 'bushido-almost-famous' )
		);
	}

	/**
	 * Send an idempotent setup acknowledgement or compensation request.
	 *
	 * @param string $endpoint            Public setup endpoint.
	 * @param string $code                Delivered setup code.
	 * @param string $api_key_id          Exact key returned by the exchange.
	 * @param string $error_code          Error code for non-2xx responses.
	 * @param string $unreachable_message User-facing network error.
	 * @param string $failure_message     User-facing server error.
	 * @return array{data?: array, error?: Api_Error, status: int}
	 */
	private function confirm_setup_exchange(
		string $endpoint,
		string $code,
		string $api_key_id,
		string $error_code,
		string $unreachable_message,
		string $failure_message
	): array {
		$url      = $this->build_url( $endpoint );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'             => 'application/json',
					'Accept'                   => 'application/json',
					'X-Bushido-Plugin-Version' => ALMOST_FAMOUS_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'code'     => $code,
						'apiKeyId' => $api_key_id,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'error'  => new Api_Error(
					Error_Code::API_UNREACHABLE->value,
					$unreachable_message,
					$response->get_error_message(),
					503
				),
				'status' => 503,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $body ) ) {
			return array(
				'data'   => $body,
				'status' => $status,
			);
		}

		return array(
			'error'  => new Api_Error(
				$error_code,
				$failure_message,
				'',
				$status
			),
			'status' => $status,
		);
	}

	/**
	 * Send a PATCH request to the Bushido API.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $data     Request body data.
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object}
	 */
	public function patch( string $endpoint, array $data = array() ): array {
		$url = $this->build_url( $endpoint );
		return $this->request( 'PATCH', $url, $data );
	}

	/**
	 * Send a DELETE request to the Bushido API.
	 *
	 * @param string $endpoint API endpoint path.
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object}
	 */
	public function delete( string $endpoint ): array {
		$url = $this->build_url( $endpoint );
		return $this->request( 'DELETE', $url );
	}

	// ---------------------------------------------------------------------
	// Typed helpers (additive on top of get/post/patch/delete).
	//
	// Each helper calls the underlying array-returning method, swallows
	// Api_Error into null/[] so call sites don't have to branch on
	// $response['error'] before mapping, and returns DTOs the dashboard
	// or admin pages can pass around without re-reading wire keys.
	// ---------------------------------------------------------------------

	/**
	 * List platform connections as typed DTOs.
	 *
	 * @return array<int, \AlmostFamous\Dto\Auth\Connection>
	 */
	public function get_connections(): array {
		$response = $this->get( '/auth/connections' );

		if ( isset( $response['error'] ) ) {
			return array();
		}

		$data = $response['data'] ?? null;

		// The contract response is `{ credentials: Connection[] }`. Unwrap if so.
		if ( is_array( $data ) && isset( $data['credentials'] ) && is_array( $data['credentials'] ) ) {
			$rows = $data['credentials'];
		} else {
			$rows = $this->extract_list( $data );
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$out[] = \AlmostFamous\Dto\Auth\Connection::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
	}

	/**
	 * Fetch a single connection by credential id as a typed DTO.
	 *
	 * The backend exposes no `GET /auth/connections/{id}` route — only the
	 * list — so this filters the list response by id.
	 *
	 * @param string $credential_id Credential cuid.
	 * @return \AlmostFamous\Dto\Auth\Connection|null
	 */
	public function get_connection( string $credential_id ): ?\AlmostFamous\Dto\Auth\Connection {
		foreach ( $this->get_connections() as $connection ) {
			if ( $connection->id === $credential_id ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * List payments as typed DTOs.
	 *
	 * @param array<string, mixed> $params Optional query parameters.
	 * @param bool|null            $failed Out-param set to true when the API
	 *                                     fetch errored (as distinct from a
	 *                                     genuinely empty payments list).
	 * @return array<int, \AlmostFamous\Dto\Payments\Payment>
	 */
	public function get_payments( array $params = array(), ?bool &$failed = null ): array {
		$failed   = false;
		$response = $this->get( '/payments', $params );

		if ( isset( $response['error'] ) ) {
			$failed = true;
			return array();
		}

		$rows = $this->extract_list( $response['data'] ?? null );

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$out[] = \AlmostFamous\Dto\Payments\Payment::from_array( $row );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $out;
	}

	/**
	 * Fetch the org tied to the current API key (GET /orgs/me).
	 *
	 * @return array{id?: string, name?: string, credentialMode?: string, status?: string, metadata?: array}
	 */
	public function get_own_org(): array {
		$response = $this->get_top( '/public/orgs/me' );

		if ( isset( $response['error'] ) ) {
			return array();
		}

		$data = $response['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * List system credentials (Bushido shared platform credentials).
	 *
	 * Used by the Accounts page to render the agency-mode banner that
	 * lists which platforms are pre-connected without the user's own
	 * OAuth. The backend has no single org-facing system-credentials
	 * route, so this aggregates `GET /auth/credentials/{platform}` —
	 * whose response carries a `systemCredentials` array — across the
	 * four platforms, cached for 15 minutes (shared credentials change
	 * rarely).
	 *
	 * @return array<int, array{id?: string, platform?: string, status?: string}>
	 */
	public function list_system_credentials(): array {
		$cached = get_transient( 'af_system_credentials' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = array();
		foreach ( array( 'META', 'GOOGLE', 'TIKTOK', 'SPOTIFY' ) as $platform ) {
			$response = $this->get( '/auth/credentials/' . $platform );

			if ( isset( $response['error'] ) ) {
				continue;
			}

			$data   = is_array( $response['data'] ?? null ) ? $response['data'] : array();
			$system = isset( $data['systemCredentials'] ) && is_array( $data['systemCredentials'] )
				? $data['systemCredentials']
				: array();

			foreach ( $system as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
		}

		set_transient( 'af_system_credentials', $rows, 15 * MINUTE_IN_SECONDS );

		return $rows;
	}

	/**
	 * List pixels for the current org (GET /pixels).
	 *
	 * Returns raw response rows. Used by conversion tracking to pick a
	 * default pixel for CAPI events.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_pixels(): array {
		$response = $this->get( '/pixels' );

		if ( isset( $response['error'] ) ) {
			return array();
		}

		return $this->extract_list( $response['data'] ?? null );
	}

	/**
	 * Fetch the org-effective AF runtime config (GET /config), cached.
	 *
	 * Carries `serviceFeeRate`, `licensingFeeBreakdown`,
	 * `platformMinimumDailyBudget` (keyed by UI platform slug), and
	 * `audienceCapabilities`. These replace values a client would
	 * otherwise hardcode.
	 *
	 * @return array<string, mixed> Config array, or empty array when unavailable.
	 */
	public function get_af_config(): array {
		$cached = get_transient( 'af_runtime_config' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->get( '/config' );

		if ( isset( $response['error'] ) || ! is_array( $response['data'] ?? null ) ) {
			return array();
		}

		set_transient( 'af_runtime_config', $response['data'], HOUR_IN_SECONDS );

		return $response['data'];
	}

	/**
	 * Extract a list of rows from the underlying response payload, accepting
	 * both flat (`[item, item]`) and paginated (`{data: [...], meta: ...}`)
	 * shapes. The base HTTP layer unwraps the outer `data` key once, but the
	 * demo-mode fixtures and a handful of legacy endpoints still double-wrap.
	 *
	 * @param mixed $payload Response data value from a typed helper.
	 * @return array<int, mixed>
	 */
	private function extract_list( mixed $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		// Paginated `{ data: [...], meta: {...} }` envelope still present.
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			return array_values( $payload['data'] );
		}

		return array_values( $payload );
	}

	/**
	 * Execute an HTTP request to the Bushido API.
	 *
	 * @param string $method        HTTP method (GET, POST, PATCH, DELETE).
	 * @param string $url           Full request URL.
	 * @param array  $data          Optional request body data for POST/PATCH.
	 * @param array  $extra_headers Optional extra request headers.
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: object}
	 */
	private function request( string $method, string $url, array $data = array(), array $extra_headers = array() ): array {
		$mock_response = apply_filters( 'almost_famous/api/mock_response', null, $method, $url, $data, $extra_headers );

		if ( is_array( $mock_response ) ) {
			return $mock_response;
		}

		if ( $this->is_demo_mode_request() ) {
			return $this->get_demo_response( $method, $url, $data );
		}

		$api_key = $this->auth->decrypt_api_key();

		if ( empty( $api_key ) ) {
			$error = new Api_Error(
				Error_Code::API_UNREACHABLE->value,
				__( 'API key not configured or decryption failed.', 'bushido-almost-famous' ),
				'',
				403
			);

			return array(
				'error'  => $error,
				'status' => 403,
			);
		}

		$headers = array(
			'X-API-Key'                => $api_key,
			'X-Bushido-Plugin-Version' => ALMOST_FAMOUS_VERSION,
			'Content-Type'             => 'application/json',
			'Accept'                   => 'application/json',
		);

		// Site-level attribution: identify which WP install fired this
		// request. Lets backend support / abuse triage trace any request
		// back to a specific site, and on multisite distinguishes between
		// subsites within the same network.
		$headers['X-AF-Site-URL'] = home_url();
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$headers['X-AF-Blog-ID'] = (string) get_current_blog_id();
		}

		$headers = array_merge( $headers, $extra_headers );

		// Idempotency key for mutating requests.
		if (
			in_array( $method, array( 'POST', 'PATCH', 'DELETE' ), true )
			&& empty( $headers['X-Idempotency-Key'] )
		) {
			$headers['X-Idempotency-Key'] = wp_generate_uuid4();
		}

		$request_args = array(
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => self::TIMEOUT,
			'sslverify' => true,
		);

		if ( ! empty( $data ) ) {
			$request_args['body'] = wp_json_encode( $data );
		}

		/**
		 * Filter API request arguments before sending.
		 *
		 * @param array  $request_args The wp_remote_request arguments.
		 * @param string $url          The full request URL.
		 * @param string $method       The HTTP method.
		 */
		$request_args = apply_filters( 'almost_famous/api/request_args', $request_args, $url, $method );

		$response = wp_remote_request( $url, $request_args );

		// Network-level failure (DNS, timeout, connection refused).
		if ( is_wp_error( $response ) ) {
			$error = new Api_Error(
				Error_Code::API_UNREACHABLE->value,
				__( 'Could not connect to Bushido API.', 'bushido-almost-famous' ),
				$response->get_error_message(),
				503
			);

			return array(
				'error'  => $error,
				'status' => 503,
			);
		}

		$status_code   = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$headers_out   = wp_remote_retrieve_headers( $response );

		if ( ! is_array( $response_body ) ) {
			$response_body = array();
		}

		// Handle 304 Not Modified — return early with not_modified flag.
		if ( 304 === $status_code ) {
			return array(
				'data'         => null,
				'status'       => 304,
				'not_modified' => true,
				'headers'      => $headers_out,
			);
		}

		// Handle 426 Upgrade Required — set transient for admin notice.
		if ( 426 === $status_code ) {
			set_transient( 'af_upgrade_required', $response_body, DAY_IN_SECONDS );
		}

		// Error responses (4xx, 5xx).
		if ( $status_code >= 400 ) {
			$error = Api_Error::from_response( $status_code, $response_body );

			return array(
				'error'  => $error,
				'status' => $status_code,
			);
		}

		$result = array(
			'data'    => $response_body['data'] ?? $response_body,
			'status'  => $status_code,
			'headers' => $headers_out,
		);

		/**
		 * Filter API response after receiving.
		 *
		 * @param array  $result The processed response array.
		 * @param string $url    The request URL.
		 * @param string $method The HTTP method.
		 */
		return apply_filters( 'almost_famous/api/response', $result, $url, $method );
	}

	/**
	 * Build the full API URL from an endpoint path.
	 *
	 * @param string $endpoint API endpoint (e.g., '/campaigns').
	 * @return string Full URL.
	 */
	private function build_url( string $endpoint ): string {
		$base = $this->base_with_api_version();

		if ( ! str_ends_with( $base, '/public' ) ) {
			$base .= '/public';
		}

		return $base . '/' . ltrim( $endpoint, '/' );
	}

	/**
	 * Build a URL for a top-level path (no /public/ prefix).
	 *
	 * @param string $endpoint Path relative to /api/v1.
	 * @return string Full URL.
	 */
	private function build_top_url( string $endpoint ): string {
		$base = $this->base_with_api_version();
		if ( str_ends_with( $base, '/public' ) ) {
			$base = substr( $base, 0, -strlen( '/public' ) );
		}
		return $base . '/' . ltrim( $endpoint, '/' );
	}

	/**
	 * Resolve the API base URL and guarantee it ends at /api/vN
	 * (without /public). Shared between build_url and build_top_url.
	 *
	 * @return string
	 */
	private function base_with_api_version(): string {
		$base = defined( 'AF_API_BASE_URL' ) ? AF_API_BASE_URL : Config::resolve_api_base_url();
		$base = rtrim( (string) $base, '/' );
		$path = wp_parse_url( $base, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			$base .= '/api/v1';
		}

		return $base;
	}

	/**
	 * Whether the current request should use in-process demo fixtures.
	 *
	 * The `X-AF-Demo-Mode` request header is honored ONLY for administrators.
	 * Api_Cache is a shared (not per-user) transient store, so accepting the
	 * header from anonymous callers would let anyone poison the fabricated
	 * fixtures into the cache that logged-in admins read. Site-wide demo mode
	 * (option / env, set by the operator) still applies to everyone.
	 *
	 * @return bool
	 */
	private function is_demo_mode_request(): bool {
		if ( Config::is_demo_mode_enabled() ) {
			return true;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$header = isset( $_SERVER['HTTP_X_AF_DEMO_MODE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_AF_DEMO_MODE'] ) )
			: '';

		return '1' === $header;
	}

	/**
	 * Return a deterministic demo response for local portal testing.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full request URL.
	 * @param array  $data   Request body.
	 * @return array{data?: mixed, error?: Api_Error, status: int, headers?: array<string, string>}
	 */
	private function get_demo_response( string $method, string $url, array $data = array() ): array {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = preg_replace( '#^/api/v1/public#', '', $path ) ?? $path;

		$campaign = $this->get_demo_campaign();
		$payment  = $this->get_demo_payment( $campaign['id'] );

		if ( 'GET' === $method && '/campaigns' === $path ) {
			return $this->demo_success(
				array(
					'data' => array( $campaign ),
					'meta' => array(
						'total' => 1,
						'page'  => 1,
						'limit' => 20,
					),
				)
			);
		}

		if ( 'POST' === $method && '/campaigns' === $path ) {
			$created_id = 'cmp_demo_' . substr( md5( wp_json_encode( $data ) . gmdate( 'c' ) ), 0, 8 );
			$created    = array_merge(
				$campaign,
				array(
					'id'                => $created_id,
					'name'              => $data['name'] ?? $campaign['name'],
					'objective'         => strtoupper( (string) ( $data['objective'] ?? $campaign['objective'] ) ),
					'creationMode'      => strtoupper( (string) ( $data['creationMode'] ?? $campaign['creationMode'] ) ),
					'budgetTotal'       => isset( $data['budget'] ) ? (float) $data['budget'] : $campaign['budgetTotal'],
					'budgetDaily'       => isset( $data['budgetDaily'] ) ? (float) $data['budgetDaily'] : $campaign['budgetDaily'],
					'currency'          => $data['currency'] ?? $campaign['currency'],
					'startDate'         => $data['startDate'] ?? $campaign['startDate'],
					'endDate'           => $data['endDate'] ?? $campaign['endDate'],
					'targeting'         => $data['targeting'] ?? $campaign['targeting'],
					'campaignPlatforms' => $this->get_demo_campaign_platforms( $created_id, $data['platformAllocations'] ?? array() ),
					'status'            => 'DRAFT',
				)
			);

			return $this->demo_success( $created, 201 );
		}

		if ( preg_match( '#^/campaigns/([^/]+)$#', $path, $matches ) ) {
			$campaign_id = $matches[1];

			if ( 'GET' === $method ) {
				return $this->demo_success( $this->get_demo_campaign( $campaign_id ) );
			}

			if ( 'PATCH' === $method ) {
				return $this->demo_success(
					array_merge( $this->get_demo_campaign( $campaign_id ), $data )
				);
			}
		}

		if ( preg_match( '#^/campaigns/([^/]+)/platforms$#', $path, $matches ) && 'GET' === $method ) {
			return $this->demo_success( $this->get_demo_campaign_platforms( $matches[1] ) );
		}

		if ( preg_match( '#^/campaigns/([^/]+)/metrics$#', $path, $matches ) && 'GET' === $method ) {
			return $this->demo_success( array( 'data' => $this->get_demo_metrics( $matches[1] ) ) );
		}

		if ( preg_match( '#^/campaigns/([^/]+)/metrics/comparison$#', $path, $matches ) && 'GET' === $method ) {
			return $this->demo_success( $this->get_demo_metrics_comparison( $matches[1] ) );
		}

		if ( preg_match( '#^/campaigns/([^/]+)/launch$#', $path, $matches ) && 'POST' === $method ) {
			$launched           = $this->get_demo_campaign( $matches[1] );
			$launched['status'] = 'ACTIVE';
			return $this->demo_success( $launched );
		}

		if ( preg_match( '#^/campaigns/([^/]+)/pause$#', $path, $matches ) && 'POST' === $method ) {
			$paused           = $this->get_demo_campaign( $matches[1] );
			$paused['status'] = 'PAUSED';
			return $this->demo_success( $paused );
		}

		if ( preg_match( '#^/campaigns/([^/]+)/resume$#', $path, $matches ) && 'POST' === $method ) {
			$resumed           = $this->get_demo_campaign( $matches[1] );
			$resumed['status'] = 'ACTIVE';
			return $this->demo_success( $resumed );
		}

		if ( preg_match( '#^/campaigns/([^/]+)/duplicate$#', $path, $matches ) && 'POST' === $method ) {
			$duplicate           = $this->get_demo_campaign( $matches[1] );
			$duplicate['id']     = 'cmp_demo_copy';
			$duplicate['name']  .= ' (Copy)';
			$duplicate['status'] = 'DRAFT';
			return $this->demo_success( $duplicate, 201 );
		}

		if ( preg_match( '#^/campaigns/([^/]+)$#', $path, $matches ) && 'DELETE' === $method ) {
			$archived           = $this->get_demo_campaign( $matches[1] );
			$archived['status'] = 'ARCHIVED';
			return $this->demo_success( $archived );
		}

		if ( 'POST' === $method && '/payments/checkout' === $path ) {
			$success_url = isset( $data['successUrl'] ) && is_string( $data['successUrl'] ) ? $data['successUrl'] : home_url( '/' );
			return $this->demo_success(
				array(
					'checkoutUrl' => add_query_arg( 'af_demo_checkout', '1', $success_url ),
					'sessionId'   => 'cs_demo_123',
					'paymentId'   => $payment['id'],
				),
				201
			);
		}

		if ( 'GET' === $method && '/payments' === $path ) {
			return $this->demo_success(
				array(
					'data' => array( $payment ),
					'meta' => array(
						'total' => 1,
						'page'  => 1,
						'limit' => 20,
					),
				)
			);
		}

		if ( preg_match( '#^/payments/([^/]+)$#', $path, $matches ) && 'GET' === $method ) {
			return $this->demo_success( $this->get_demo_payment( $campaign['id'], $matches[1] ) );
		}

		if ( preg_match( '#^/payments/([^/]+)/refund$#', $path, $matches ) && 'POST' === $method ) {
			$refunded                 = $this->get_demo_payment( $campaign['id'], $matches[1] );
			$refunded['status']       = 'REFUNDED';
			$refunded['refundAmount'] = $refunded['totalCharged'];
			$refunded['refundReason'] = $data['reason'] ?? 'Demo refund requested';
			$refunded['refundedAt']   = gmdate( 'c' );
			return $this->demo_success( $refunded );
		}

		if ( 'GET' === $method && '/platforms/insights/summary' === $path ) {
			return $this->demo_success(
				array(
					'aggregate' => array(
						'totalSpend'       => 640.0,
						'totalImpressions' => 182340,
						'totalClicks'      => 6040,
						'totalConversions' => 348,
						'totalReach'       => 120540,
					),
					'platforms' => array(
						array(
							'platform'         => 'META',
							'totalSpend'       => 280.0,
							'totalImpressions' => 82000,
							'totalClicks'      => 2840,
							'totalConversions' => 162,
							'totalReach'       => 59000,
						),
						array(
							'platform'         => 'SPOTIFY',
							'totalSpend'       => 210.0,
							'totalImpressions' => 62400,
							'totalClicks'      => 1820,
							'totalConversions' => 104,
							'totalReach'       => 40320,
						),
						array(
							'platform'         => 'TIKTOK',
							'totalSpend'       => 150.0,
							'totalImpressions' => 37940,
							'totalClicks'      => 1380,
							'totalConversions' => 82,
							'totalReach'       => 21220,
						),
					),
				)
			);
		}

		if ( 'GET' === $method && '/metrics/summary' === $path ) {
			return $this->demo_success(
				array(
					'data' => array(
						'totalSpend'       => 640.0,
						'totalImpressions' => 182340,
						'totalClicks'      => 6040,
						'totalConversions' => 348,
						'totalReach'       => 120540,
						'avgCtr'           => 3.31,
						'avgCpc'           => 0.11,
						'avgCpm'           => 3.51,
						'activeCampaigns'  => 1,
					),
				)
			);
		}

		if ( 'GET' === $method && '/audiences' === $path ) {
			return $this->demo_success( array() );
		}

		$error = new Api_Error(
			Error_Code::UNKNOWN_ERROR->value,
			__( 'This demo endpoint is not implemented.', 'bushido-almost-famous' ),
			$path,
			404
		);

		return array(
			'error'  => $error,
			'status' => 404,
		);
	}

	/**
	 * Create a demo success payload.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status.
	 * @return array{data: mixed, status: int, headers: array<string, string>}
	 */
	private function demo_success( mixed $data, int $status = 200 ): array {
		return array(
			'data'    => $data,
			'status'  => $status,
			'headers' => array(
				'etag' => '"' . md5( (string) wp_json_encode( $data ) ) . '"',
			),
		);
	}

	/**
	 * Build a demo campaign.
	 *
	 * @param string|null $campaign_id Optional campaign ID override.
	 * @return array<string, mixed>
	 */
	private function get_demo_campaign( ?string $campaign_id = null ): array {
		$id = null !== $campaign_id && '' !== $campaign_id ? $campaign_id : 'cmp_demo_001';

		return array(
			'id'                => $id,
			'orgId'             => 'org_demo_001',
			'name'              => 'Demo Release Push',
			'objective'         => 'AWARENESS',
			'creationMode'      => 'MANUAL',
			'status'            => 'ACTIVE',
			'budgetTotal'       => 500.0,
			'budgetDaily'       => 75.0,
			'currency'          => 'USD',
			'startDate'         => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			'endDate'           => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
			'targeting'         => array(
				'countries' => array( 'US', 'CA', 'GB' ),
			),
			'config'            => array(
				'creativePreset' => 'short-form-video',
			),
			'campaignPlatforms' => $this->get_demo_campaign_platforms( $id ),
			'createdAt'         => gmdate( 'c', strtotime( '-4 days' ) ),
			'updatedAt'         => gmdate( 'c', strtotime( '-2 hours' ) ),
		);
	}

	/**
	 * Build demo campaign platform allocations.
	 *
	 * @param string               $campaign_id Campaign ID.
	 * @param array<string, mixed> $allocations Optional custom allocations.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_demo_campaign_platforms( string $campaign_id, array $allocations = array() ): array {
		$default_allocations = array(
			'META'    => 50,
			'SPOTIFY' => 30,
			'TIKTOK'  => 20,
		);

		if ( ! empty( $allocations ) ) {
			$default_allocations = array();
			foreach ( $allocations as $platform => $allocation ) {
				$default_allocations[ strtoupper( (string) $platform ) ] = (float) $allocation;
			}
		}

		$rows = array();
		foreach ( $default_allocations as $platform => $allocation ) {
			$rows[] = array(
				'id'                 => strtolower( $platform ) . '_row_' . substr( md5( $campaign_id . $platform ), 0, 6 ),
				'campaignId'         => $campaign_id,
				'platform'           => $platform,
				'credentialId'       => 'cred_' . strtolower( $platform ),
				'platformCampaignId' => 'ext_' . strtolower( $platform ) . '_123',
				'budgetAllocation'   => $allocation,
				'platformStatus'     => 'ACTIVE',
				'syncError'          => null,
				'lastSyncedAt'       => gmdate( 'c', strtotime( '-2 hours' ) ),
				'archivedAt'         => null,
				'createdAt'          => gmdate( 'c', strtotime( '-4 days' ) ),
				'updatedAt'          => gmdate( 'c', strtotime( '-2 hours' ) ),
			);
		}

		return $rows;
	}

	/**
	 * Build demo payment details.
	 *
	 * @param string      $campaign_id Campaign ID.
	 * @param string|null $payment_id  Optional payment ID override.
	 * @return array<string, mixed>
	 */
	private function get_demo_payment( string $campaign_id, ?string $payment_id = null ): array {
		return array(
			'id'                    => null !== $payment_id && '' !== $payment_id ? $payment_id : 'pay_demo_001',
			'orgId'                 => 'org_demo_001',
			'campaignId'            => $campaign_id,
			'amount'                => 500.0,
			'serviceFee'            => 75.0,
			'licensingFee'          => 0.0,
			'totalCharged'          => 575.0,
			'currency'              => 'USD',
			'durationType'          => 'FIXED',
			'dailyBudget'           => 75.0,
			'durationDays'          => 7,
			'status'                => 'PAID',
			'stripeSessionId'       => 'cs_test_demo_123',
			'stripePaymentIntentId' => 'pi_demo_123',
			'externalReference'     => 'wp-demo-checkout',
			'refundedAt'            => null,
			'refundAmount'          => null,
			'refundReason'          => null,
			'createdAt'             => gmdate( 'c', strtotime( '-1 day' ) ),
			'updatedAt'             => gmdate( 'c', strtotime( '-1 hour' ) ),
		);
	}

	/**
	 * Build demo metrics data points.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array<int, array<string, int|float|string>>
	 */
	private function get_demo_metrics( string $campaign_id ): array {
		unset( $campaign_id );

		return array(
			array(
				'date'                 => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
				'impressions'          => 52840,
				'clicks'               => 1720,
				'spend'                => 180.0,
				'conversions'          => 92,
				'reach'                => 34020,
				'ctr'                  => 3.26,
				'cpc'                  => 0.10,
				'cpm'                  => 3.41,
				'videoViews'           => 12800,
				'videoCompletions'     => 7420,
				'streamingConversions' => 54,
				'frequency'            => 1.55,
			),
			array(
				'date'                 => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				'impressions'          => 61740,
				'clicks'               => 2095,
				'spend'                => 215.0,
				'conversions'          => 121,
				'reach'                => 40210,
				'ctr'                  => 3.39,
				'cpc'                  => 0.10,
				'cpm'                  => 3.48,
				'videoViews'           => 15240,
				'videoCompletions'     => 8345,
				'streamingConversions' => 68,
				'frequency'            => 1.53,
			),
			array(
				'date'                 => gmdate( 'Y-m-d' ),
				'impressions'          => 67760,
				'clicks'               => 2225,
				'spend'                => 245.0,
				'conversions'          => 135,
				'reach'                => 46310,
				'ctr'                  => 3.28,
				'cpc'                  => 0.11,
				'cpm'                  => 3.62,
				'videoViews'           => 16690,
				'videoCompletions'     => 9050,
				'streamingConversions' => 76,
				'frequency'            => 1.46,
			),
		);
	}

	/**
	 * Build demo platform comparison metrics.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_demo_metrics_comparison( string $campaign_id ): array {
		unset( $campaign_id );

		return array(
			'META'    => array(
				'totals'     => array(
					'impressions' => 82000,
					'clicks'      => 2840,
					'spend'       => 280.0,
					'conversions' => 162,
					'reach'       => 59000,
					'ctr'         => 3.46,
					'cpc'         => 0.10,
					'cpm'         => 3.41,
				),
				'dataPoints' => array(),
			),
			'SPOTIFY' => array(
				'totals'     => array(
					'impressions' => 62400,
					'clicks'      => 1820,
					'spend'       => 210.0,
					'conversions' => 104,
					'reach'       => 40320,
					'ctr'         => 2.92,
					'cpc'         => 0.12,
					'cpm'         => 3.37,
				),
				'dataPoints' => array(),
			),
			'TIKTOK'  => array(
				'totals'     => array(
					'impressions' => 37940,
					'clicks'      => 1380,
					'spend'       => 150.0,
					'conversions' => 82,
					'reach'       => 21220,
					'ctr'         => 3.64,
					'cpc'         => 0.11,
					'cpm'         => 3.95,
				),
				'dataPoints' => array(),
			),
		);
	}
}
