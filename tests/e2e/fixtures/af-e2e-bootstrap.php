<?php
/**
 * Deterministic wp-env-only behavior for browser tests.
 *
 * @package AlmostFamous
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'almost_famous_passes_ssl_check', '__return_true' );

/**
 * Seed a connected plugin state without storing plaintext credentials.
 *
 * @param string $credential_mode Organization credential mode.
 * @return void
 */
function af_e2e_seed_connected_site( string $credential_mode = 'agency' ): void {
	$mode = in_array( $credential_mode, array( 'agency', 'own' ), true )
		? $credential_mode
		: 'agency';

	$auth = new \AlmostFamous\Api\Api_Auth();
	if ( ! $auth->store_api_key( 'af_e2e_test_key_0000000000000000' ) ) {
		throw new RuntimeException( 'Unable to seed the E2E API key.' );
	}

	update_option( 'af_setup_complete', true );
	update_option( 'af_org_id', 'org_e2e' );
	update_option( 'af_org_channel_id', 'channel_e2e' );
	update_option( 'af_org_channel_name', 'E2E Channel' );
	update_option( 'af_org_credential_mode', $mode );
	delete_option( 'af_accounts' );
	delete_transient( 'af_activation_redirect' );
	delete_transient( 'af_system_credentials' );
	delete_transient( 'af_creatives_list' );
	delete_transient( 'af_campaigns_cmp_e2e_analytics' );
	delete_transient( 'af_campaigns_cmp_e2e_block' );
	delete_option( 'af_e2e_uploaded_asset' );
}

/**
 * Reset the plugin to a deterministic pre-connection state.
 *
 * @return void
 */
function af_e2e_reset_connection(): void {
	( new \AlmostFamous\Api\Api_Auth() )->delete_api_key();

	foreach (
		array(
			'af_setup_complete',
			'af_org_id',
			'af_org_channel_id',
			'af_org_channel_name',
				'af_org_credential_mode',
				'af_accounts',
				'af_e2e_uploaded_asset',
				'af_e2e_uploaded_bytes',
			) as $option
	) {
		delete_option( $option );
	}

	foreach (
		array(
			'af_activation_redirect',
			'af_system_credentials',
			'af_creatives_list',
			'af_campaigns_cmp_e2e_analytics',
			'af_campaigns_cmp_e2e_block',
		) as $transient
	) {
		delete_transient( $transient );
	}
}

add_filter(
	'pre_http_request',
	static function ( mixed $preempt, array $args, string $url ): mixed {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return $preempt;
		}
			if ( 'uploads.example.test' === $host && 'PUT' === ( $args['method'] ?? 'GET' ) ) {
				$expected_bytes = base64_decode(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
					true
				);
				$body           = $args['body'] ?? null;
				$headers        = is_array( $args['headers'] ?? null )
					? array_change_key_case( $args['headers'], CASE_LOWER )
					: array();
				if (
					! is_string( $expected_bytes )
					|| ! is_string( $body )
					|| ! hash_equals( hash( 'sha256', $expected_bytes ), hash( 'sha256', $body ) )
					|| 'image/png' !== (string) ( $headers['content-type'] ?? '' )
					|| (string) strlen( $expected_bytes ) !== (string) ( $headers['content-length'] ?? '' )
				) {
					return new WP_Error(
						'af_e2e_invalid_upload',
						'Creative upload did not transmit the expected PNG bytes and headers.'
					);
				}
				update_option( 'af_e2e_uploaded_bytes', hash( 'sha256', $body ) );
				return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if (
			! is_string( $host )
			|| ! in_array(
				$host,
				array(
					'api.almost-famous-staging.backend-bushidoco.de',
					'api.almost-famous.backend-bushidoco.de',
				),
				true
			)
		) {
			return new WP_Error(
				'af_e2e_unexpected_external_request',
				'Unexpected external request in E2E: ' . $url
			);
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body = null;

		if ( str_ends_with( $path, '/auth/validate' ) ) {
			$body = array(
				'valid'   => true,
				'keyType' => 'org',
				'orgId'   => 'org_e2e',
			);
		} elseif ( str_ends_with( $path, '/public/orgs/me' ) ) {
			$body = array(
				'data' => array(
					'id'             => 'org_e2e',
					'name'           => 'E2E Organization',
					'credentialMode' => (string) get_option( 'af_org_credential_mode', 'agency' ),
					'status'         => 'active',
					'metadata'       => array( 'tier' => 'pro' ),
				),
			);
		} elseif ( str_ends_with( $path, '/public/auth/connections' ) ) {
			$body = array( 'data' => array( 'credentials' => array() ) );
		} elseif ( preg_match( '#/public/auth/credentials/[^/]+$#', $path ) ) {
			$body = array( 'data' => array( 'systemCredentials' => array() ) );
		} elseif ( str_ends_with( $path, '/public/auth/connect/META' ) ) {
			$body = array(
				'data' => array(
					'authorizationUrl' => admin_url( 'admin.php?page=af-accounts&af_connected=meta' ),
				),
			);
		} elseif ( str_ends_with( $path, '/public/assets' ) && 'GET' === ( $args['method'] ?? 'GET' ) ) {
			$assets = array(
				array(
					'id'       => 'cre_e2e_poll',
					'name'     => 'E2E Polling Asset',
					'type'     => 'image',
					'mimeType' => 'image/png',
					'status'   => 'processing',
				),
			);
			if ( get_option( 'af_e2e_uploaded_asset', false ) ) {
				$assets[] = array(
					'id'       => 'cre_e2e_uploaded',
					'name'     => 'E2E Uploaded Asset',
					'type'     => 'image',
					'mimeType' => 'image/png',
					'status'   => 'complete',
				);
			}
			$body = array(
				'data' => $assets,
			);
		} elseif ( str_ends_with( $path, '/public/assets' ) && 'POST' === ( $args['method'] ?? 'GET' ) ) {
			$body = array(
				'data' => array(
					'id'        => 'cre_e2e_uploaded',
					'uploadUrl' => 'https://uploads.example.test/cre_e2e_uploaded',
				),
			);
		} elseif ( str_ends_with( $path, '/public/assets/cre_e2e_uploaded/confirm' ) ) {
			$expected_hash = hash(
				'sha256',
				(string) base64_decode(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
					true
				)
			);
			if ( ! hash_equals( $expected_hash, (string) get_option( 'af_e2e_uploaded_bytes', '' ) ) ) {
				return new WP_Error(
					'af_e2e_upload_not_received',
					'Creative confirmation arrived before the expected bytes.'
				);
			}
			update_option( 'af_e2e_uploaded_asset', true );
			$body = array(
				'data' => array(
					'id'     => 'cre_e2e_uploaded',
					'status' => 'complete',
				),
			);
		} elseif ( str_ends_with( $path, '/public/assets/cre_e2e_uploaded/platform-status' ) ) {
			$body = array( 'data' => array() );
		} elseif ( str_ends_with( $path, '/public/assets/cre_e2e_uploaded' ) ) {
			$body = array(
				'data' => array(
					'id'       => 'cre_e2e_uploaded',
					'name'     => 'E2E Uploaded Asset',
					'type'     => 'image',
					'mimeType' => 'image/png',
					'status'   => 'complete',
				),
			);
		} elseif ( str_ends_with( $path, '/public/assets/cre_e2e_poll/platform-status' ) ) {
			$body = array( 'data' => array() );
		} elseif ( str_ends_with( $path, '/public/assets/cre_e2e_poll' ) ) {
			$body = array(
				'data' => array(
					'id'       => 'cre_e2e_poll',
					'name'     => 'E2E Polling Asset',
					'type'     => 'image',
					'mimeType' => 'image/png',
					'status'   => 'complete',
				),
			);
		} elseif ( preg_match( '#/public/campaigns/(cmp_e2e_analytics|cmp_e2e_block)$#', $path, $matches ) ) {
			$body = array(
				'data' => array(
					'id'          => $matches[1],
					'name'        => 'E2E Analytics Campaign',
					'status'      => 'active',
					'roas'        => 2.5,
					'impressions' => 5000,
					'spend'       => 100,
				),
			);
		}
		if ( null === $body ) {
			return new WP_Error(
				'af_e2e_missing_fixture',
				'No deterministic E2E fixture for ' . (string) ( $args['method'] ?? 'GET' ) . ' ' . $path
			);
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
