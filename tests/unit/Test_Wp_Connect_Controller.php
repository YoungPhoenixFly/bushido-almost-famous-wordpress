<?php
/**
 * Tests for the WordPress-to-Bushido connection controller.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Api\Wp_Connect_Controller;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		unset( $special_chars, $extra_special_chars );
		return substr( str_repeat( 'test-state-', $length ), 0, $length );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		unset( $show, $filter );
		return 'Example Site';
	}
}

/**
 * Controllable setup-delivery client for callback and retry tests.
 */
class Test_Wp_Connect_Api_Client extends Api_Client {

	/**
	 * Exchange response.
	 *
	 * @var array<string, mixed>
	 */
	public array $exchange_result;

	/**
	 * Queued acknowledgement responses.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $ack_results = array();

	/**
	 * Queued abort responses.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $abort_results = array();

	/**
	 * Acknowledgement calls.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $acknowledged = array();

	/**
	 * Abort calls.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $aborted = array();

	/**
	 * Exchange calls.
	 *
	 * @var array<int, string>
	 */
	public array $exchanged = array();

	/**
	 * Current-key probe result used after a terminal acknowledgement response.
	 *
	 * @var array<string, mixed>
	 */
	public array $probe_result = array( 'data' => array( 'valid' => true ), 'status' => 200 );

	public function __construct() {
		parent::__construct( new Api_Auth() );
		$this->exchange_result = array(
			'data'   => array(
				'apiKey'         => 'af_new_plaintext',
				'apiKeyId'       => 'key-new',
				'orgId'          => 'org-new',
				'channelId'      => 'channel-new',
				'channelName'    => 'New Channel',
				'credentialMode' => 'agency',
			),
			'status' => 200,
		);
	}

	public function exchange_setup_code( string $code ): array {
		$this->exchanged[] = $code;
		return $this->exchange_result;
	}

	public function acknowledge_setup_exchange( string $code, string $api_key_id ): array {
		$this->acknowledged[] = array( $code, $api_key_id );
		if ( ! empty( $this->ack_results ) ) {
			return array_shift( $this->ack_results );
		}
		return array( 'data' => array( 'success' => true ), 'status' => 200 );
	}

	public function abort_setup_exchange( string $code, string $api_key_id ): array {
		$this->aborted[] = array( $code, $api_key_id );
		if ( ! empty( $this->abort_results ) ) {
			return array_shift( $this->abort_results );
		}
		return array( 'data' => array( 'success' => true ), 'status' => 200 );
	}

	public function probe_current_key(): array {
		return $this->probe_result;
	}
}

/**
 * Covers the consent-app redirect host defaults and override.
 */
class Test_Wp_Connect_Controller extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();

		global $af_test_redirect_throws;
		$af_test_redirect_throws = true;
	}

	protected function tearDown(): void {
		global $af_test_redirect_throws;
		$af_test_redirect_throws = false;

		parent::tearDown();
	}

	private function controller(): Wp_Connect_Controller {
		$auth = new Api_Auth();
		return new Wp_Connect_Controller( new Api_Client( $auth ), $auth );
	}

	private function start_and_get_redirect(): string {
		try {
			$this->controller()->start();
		} catch ( RuntimeException $exception ) {
			$this->assertStringStartsWith( 'af_test_redirect:', $exception->getMessage() );
		}

		global $af_test_redirects;
		$redirect = end( $af_test_redirects );
		$this->assertIsArray( $redirect );

		return (string) $redirect['url'];
	}

	public function test_default_app_redirect_host_is_production(): void {
		$hosts = $this->controller()->allow_app_redirect_host( array( 'example.test' ) );

		$this->assertContains( 'bushido.is', $hosts );
		$this->assertNotContains( 'staging.bushido.is', $hosts );
	}

	public function test_start_redirects_to_production_consent_url(): void {
		$url = $this->start_and_get_redirect();

		$this->assertStringStartsWith( 'https://bushido.is/almost-famous/wp-connect?', $url );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'https://example.test', $query['site_url'] ?? null );
		$this->assertSame( 'Example Site', $query['site_name'] ?? null );
		$this->assertSame( 'https://example.test/wp-json/almost-famous/v1/wp-connect/callback', $query['return_url'] ?? null );
		$this->assertNotSame( '', $query['state'] ?? '' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_app_url_constant_overrides_default_redirect_host(): void {
		define( 'AF_API_BASE_URL', 'https://api.example.test/api/v1' );
		define( 'AF_BUSHIDO_APP_URL', 'https://connect.example.test/custom/' );

		$controller = $this->controller();
		$hosts      = $controller->allow_app_redirect_host( array() );

		$this->assertContains( 'connect.example.test', $hosts );
		$this->assertNotContains( 'staging.bushido.is', $hosts );

		$url = $this->start_and_get_redirect();
		$this->assertStringStartsWith( 'https://connect.example.test/custom/almost-famous/wp-connect?', $url );
	}

	public function test_transient_exchange_failure_keeps_encrypted_pre_io_retry_state(): void {
		$api = new class() extends Test_Wp_Connect_Api_Client {
			public bool $saw_durable_record = false;

			public function exchange_setup_code( string $code ): array {
				$this->saw_durable_record = is_array( get_option( 'af_wp_connect_pending_delivery', false ) );
				$this->exchanged[]        = $code;
				if ( 1 === count( $this->exchanged ) ) {
					return array(
						'error'  => (object) array( 'message' => 'temporarily unavailable' ),
						'status' => 503,
					);
				}
				return $this->exchange_result;
			}
		};
		$state = 'retryable-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );
		$auth = new Api_Auth();
		$controller = new Wp_Connect_Controller( $api, $auth );

		$this->invoke_callback( $controller, 'code-value', $state );

		$pending = get_option( 'af_wp_connect_pending_delivery', false );
		$this->assertTrue( $api->saw_durable_record );
		$this->assertIsArray( $pending );
		$this->assertSame( 'exchange', $pending['action'] );
		$this->assertNotSame( 'code-value', $pending['encrypted_code'] );
		$this->assertSame( 'code-value', $auth->decrypt( $pending['encrypted_code'] ) );
		$this->assertFalse( get_transient( 'af_wp_connect_state_' . md5( $state ) ) );
		$this->assertFalse( get_option( 'af_setup_complete', false ) );

		$this->assertTrue( $controller->retry_pending_delivery() );
		$this->assertSame( array( 'code-value', 'code-value' ), $api->exchanged );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
		$this->assertSame( 'af_new_plaintext', $auth->decrypt_api_key() );
	}

	public function test_terminal_ack_restores_previous_key_when_delivered_key_is_revoked(): void {
		$api               = new Test_Wp_Connect_Api_Client();
		$api->probe_result = array(
			'error'  => (object) array( 'message' => 'invalid key' ),
			'status' => 401,
		);
		$api->ack_results = array(
			array(
				'error'  => (object) array( 'message' => 'gone' ),
				'status' => 410,
			),
		);
		$auth = new Api_Auth();
		$this->assertTrue( $auth->store_api_key( 'af_previous_key' ) );
		update_option( 'af_setup_complete', true );
		update_option( 'af_org_id', 'org-old' );
		update_option( 'af_org_channel_id', 'channel-old' );
		update_option( 'af_org_channel_name', 'Old Channel' );
		update_option( 'af_org_credential_mode', 'own' );
		$state = 'terminal-ack-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$this->invoke_callback(
			new Wp_Connect_Controller( $api, $auth ),
			'terminal-ack-code',
			$state
		);

		$this->assertSame( 'af_previous_key', $auth->decrypt_api_key() );
		$this->assertSame( 'org-old', get_option( 'af_org_id' ) );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
	}

	public function test_terminal_ack_keeps_new_key_when_current_key_probe_succeeds(): void {
		$api              = new Test_Wp_Connect_Api_Client();
		$api->ack_results = array(
			array(
				'error'  => (object) array( 'message' => 'tombstone expired' ),
				'status' => 410,
			),
		);
		$auth  = new Api_Auth();
		$state = 'terminal-ack-probe-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$this->invoke_callback(
			new Wp_Connect_Controller( $api, $auth ),
			'terminal-ack-probe-code',
			$state
		);

		$this->assertSame( 'af_new_plaintext', $auth->decrypt_api_key() );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
	}

	public function test_storage_failure_aborts_exact_exchange_and_fails_closed(): void {
		$api = new class() extends Api_Client {
			public array $aborted = array();

			public function __construct() {
				parent::__construct( new Api_Auth() );
			}

			public function exchange_setup_code( string $code ): array {
				unset( $code );
				return array(
					'data'   => array(
						'apiKey'        => 'af_plaintext',
						'apiKeyId'      => 'key-123',
						'orgId'         => 'org-1',
						'channelId'     => 'channel-1',
						'channelName'   => 'Example',
						'credentialMode' => 'agency',
					),
					'status' => 200,
				);
			}

			public function abort_setup_exchange( string $code, string $api_key_id ): array {
				$this->aborted = array( $code, $api_key_id );
				return array( 'data' => array( 'success' => true ), 'status' => 200 );
			}
		};
		$auth = new class() extends Api_Auth {
			public function store_api_key( string $key ): bool {
				unset( $key );
				return false;
			}
		};
		$state = 'storage-failure-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$this->invoke_callback( new Wp_Connect_Controller( $api, $auth ), 'consumed-code', $state );

		$this->assertSame( array( 'consumed-code', 'key-123' ), $api->aborted );
		$this->assertFalse( get_transient( 'af_wp_connect_state_' . md5( $state ) ) );
		$this->assertFalse( get_option( 'af_setup_complete', false ) );
	}

	public function test_success_persists_all_metadata_and_acknowledges_exact_delivery(): void {
		$api   = new Test_Wp_Connect_Api_Client();
		$auth  = new Api_Auth();
		$state = 'successful-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$this->invoke_callback( new Wp_Connect_Controller( $api, $auth ), 'delivered-code', $state );

		$this->assertSame( array( array( 'delivered-code', 'key-new' ) ), $api->acknowledged );
		$this->assertSame( 'af_new_plaintext', $auth->decrypt_api_key() );
		$this->assertTrue( get_option( 'af_setup_complete' ) );
		$this->assertSame( 'org-new', get_option( 'af_org_id' ) );
		$this->assertSame( 'channel-new', get_option( 'af_org_channel_id' ) );
		$this->assertSame( 'New Channel', get_option( 'af_org_channel_name' ) );
		$this->assertSame( 'agency', get_option( 'af_org_credential_mode' ) );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
		$this->assertFalse( get_transient( 'af_wp_connect_state_' . md5( $state ) ) );
	}

	public function test_acknowledgement_response_loss_keeps_encrypted_retry_until_confirmed(): void {
		$api = new Test_Wp_Connect_Api_Client();
		$api->ack_results = array(
			array(
				'error'  => (object) array( 'message' => 'temporarily unavailable' ),
				'status' => 503,
			),
			array( 'data' => array( 'success' => true ), 'status' => 200 ),
		);
		$auth  = new Api_Auth();
		$state = 'ack-retry-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$controller = new Wp_Connect_Controller( $api, $auth );
		$this->invoke_callback( $controller, 'ack-retry-code', $state );

		$pending = get_option( 'af_wp_connect_pending_delivery', false );
		$this->assertIsArray( $pending );
		$this->assertSame( 'ack', $pending['action'] );
		$this->assertNotSame( 'ack-retry-code', $pending['encrypted_code'] );
		$this->assertSame( 'ack-retry-code', $auth->decrypt( $pending['encrypted_code'] ) );
		$this->assertFalse( get_transient( 'af_wp_connect_state_' . md5( $state ) ) );

		$this->assertTrue( $controller->retry_pending_delivery() );
		$this->assertCount( 2, $api->acknowledged );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
		$this->assertSame( 'af_new_plaintext', $auth->decrypt_api_key() );
	}

	public function test_metadata_failure_aborts_and_restores_previous_connection(): void {
		$api  = new Test_Wp_Connect_Api_Client();
		$auth = new Api_Auth();
		update_option( 'af_api_key', $auth->encrypt_api_key( 'af_previous_key' ) );
		update_option( 'af_setup_complete', true );
		update_option( 'af_org_id', 'org-old' );
		update_option( 'af_org_channel_id', 'channel-old' );
		update_option( 'af_org_channel_name', 'Old Channel' );
		update_option( 'af_org_credential_mode', 'own' );
		af_test_fail_option_updates( 'af_org_channel_name' );

		$state = 'metadata-failure-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );
		$this->invoke_callback( new Wp_Connect_Controller( $api, $auth ), 'metadata-code', $state );

		$this->assertSame( array( array( 'metadata-code', 'key-new' ) ), $api->aborted );
		$this->assertSame( 'af_previous_key', $auth->decrypt_api_key() );
		$this->assertTrue( get_option( 'af_setup_complete' ) );
		$this->assertSame( 'org-old', get_option( 'af_org_id' ) );
		$this->assertSame( 'channel-old', get_option( 'af_org_channel_id' ) );
		$this->assertSame( 'Old Channel', get_option( 'af_org_channel_name' ) );
		$this->assertSame( 'own', get_option( 'af_org_credential_mode' ) );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
	}

	public function test_abort_response_loss_keeps_retry_then_restores_previous_connection(): void {
		$api = new Test_Wp_Connect_Api_Client();
		$api->abort_results = array(
			array(
				'error'  => (object) array( 'message' => 'temporarily unavailable' ),
				'status' => 503,
			),
			array( 'data' => array( 'success' => true ), 'status' => 200 ),
		);
		$auth = new class() extends Api_Auth {
			public function store_api_key( string $key ): bool {
				unset( $key );
				return false;
			}
		};
		$state = 'abort-retry-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$controller = new Wp_Connect_Controller( $api, $auth );
		$this->invoke_callback( $controller, 'abort-retry-code', $state );

		$pending = get_option( 'af_wp_connect_pending_delivery', false );
		$this->assertIsArray( $pending );
		$this->assertSame( 'abort', $pending['action'] );
		$this->assertFalse( get_transient( 'af_wp_connect_state_' . md5( $state ) ) );

		$this->assertTrue( $controller->retry_pending_delivery() );
		$this->assertCount( 2, $api->aborted );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
		$this->assertFalse( get_option( 'af_api_key', false ) );
	}

	public function test_delivery_record_failure_compensates_before_any_local_write(): void {
		$api   = new Test_Wp_Connect_Api_Client();
		$auth  = new Api_Auth();
		$state = 'delivery-storage-failure';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );
		af_test_fail_option_updates( 'af_wp_connect_pending_delivery' );

		$this->invoke_callback( new Wp_Connect_Controller( $api, $auth ), 'delivery-code', $state );

		$this->assertSame( array(), $api->exchanged );
		$this->assertSame( array(), $api->aborted );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
		$this->assertFalse( get_option( 'af_api_key', false ) );
		$this->assertSame( '1', get_transient( 'af_wp_connect_state_' . md5( $state ) ) );
	}

	public function test_missing_required_exchange_ids_abort_without_replacing_previous_key(): void {
		$api = new Test_Wp_Connect_Api_Client();
		unset( $api->exchange_result['data']['orgId'] );
		$auth = new Api_Auth();
		$this->assertTrue( $auth->store_api_key( 'af_previous_key' ) );
		update_option( 'af_setup_complete', true );
		update_option( 'af_org_id', 'org-old' );
		update_option( 'af_org_channel_id', 'channel-old' );
		update_option( 'af_org_channel_name', 'Old Channel' );
		update_option( 'af_org_credential_mode', 'own' );

		$state = 'missing-id-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );
		$this->invoke_callback( new Wp_Connect_Controller( $api, $auth ), 'missing-id-code', $state );

		$this->assertSame( array( array( 'missing-id-code', 'key-new' ) ), $api->aborted );
		$this->assertSame( 'af_previous_key', $auth->decrypt_api_key() );
		$this->assertSame( 'org-old', get_option( 'af_org_id' ) );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
	}

	public function test_delivery_encryption_failure_makes_no_http_request(): void {
		$api = new Test_Wp_Connect_Api_Client();
		$auth = new class() extends Api_Auth {
			public function encrypt( string $plaintext ): string {
				unset( $plaintext );
				return '';
			}
		};
		$state = 'encryption-failure-state';
		set_transient( 'af_wp_connect_state_' . md5( $state ), '1', 600 );

		$this->invoke_callback( new Wp_Connect_Controller( $api, $auth ), 'never-sent-code', $state );

		$this->assertSame( array(), $api->exchanged );
		$this->assertFalse( get_option( 'af_wp_connect_pending_delivery', false ) );
		$this->assertSame( '1', get_transient( 'af_wp_connect_state_' . md5( $state ) ) );
	}

	public function test_expired_delivery_lock_is_reacquired_in_same_retry(): void {
		update_option(
			'af_wp_connect_delivery_lock',
			array(
				'token'      => 'stale-owner',
				'expires_at' => time() - 1,
			)
		);

		$this->assertTrue( $this->controller()->retry_pending_delivery() );
		$this->assertFalse( get_option( 'af_wp_connect_delivery_lock', false ) );
	}

	public function test_malformed_delivery_lock_is_recovered_in_same_retry(): void {
		update_option( 'af_wp_connect_delivery_lock', 'corrupt-lock-data' );

		$this->assertTrue( $this->controller()->retry_pending_delivery() );
		$this->assertFalse( get_option( 'af_wp_connect_delivery_lock', false ) );
	}

	private function invoke_callback( Wp_Connect_Controller $controller, string $code, string $state ): void {
		$request = new WP_REST_Request();
		$request->set_param( 'code', $code );
		$request->set_param( 'state', $state );

		try {
			$controller->callback( $request );
		} catch ( RuntimeException $exception ) {
			$this->assertStringStartsWith( 'af_test_redirect:', $exception->getMessage() );
		}
	}
}
