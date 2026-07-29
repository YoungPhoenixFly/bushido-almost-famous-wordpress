<?php
/**
 * WP REST API proxy routes for Bushido API communication.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

use AlmostFamous\Config;
use AlmostFamous\Creative_Assets;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers WP REST routes that proxy requests to the Bushido API.
 *
 * Each route checks permissions, sanitises inputs, queries the Api_Client,
 * and wraps the result in a standard {data, meta} / {error, meta} envelope.
 * GET routes use Api_Cache for transient caching with ETag support.
 */
class Api_Proxy {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'almost-famous/v1';

	/**
	 * API client instance.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * Cache instance.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client The Bushido API client.
	 * @param Api_Cache  $cache  The transient cache manager.
	 */
	public function __construct( Api_Client $client, Api_Cache $cache ) {
		$this->client = $client;
		$this->cache  = $cache;
	}

	/**
	 * Register all REST API routes.
	 *
	 * Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /campaigns.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_campaigns' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'status'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ), // Updated for public portal.
			)
		);

		// GET /campaigns/{id}.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// PATCH /campaigns/{id}.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ), // Updated for public portal.
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/checkout.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checkout' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /payments. Payment data (amounts, Stripe identifiers) is never
		// exposed to anonymous portal tokens — authenticated WP users only.
		register_rest_route(
			self::REST_NAMESPACE,
			'/payments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payments' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// GET /payments/{id}.
		register_rest_route(
			self::REST_NAMESPACE,
			'/payments/(?P<id>[\\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /payments/{id}/refund. Refunds move money — require the AF
		// manage capability, never an anonymous portal token.
		register_rest_route(
			self::REST_NAMESPACE,
			'/payments/(?P<id>[\\w-]+)/refund',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refund_payment' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/launch.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/launch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'launch_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/pause.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pause_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/resume.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/archive.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/archive',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'archive_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/duplicate.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_campaign' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /campaigns/{id}/platforms.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/platforms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_campaign_platforms' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /campaigns/{id}/analytics.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_campaign_analytics' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /campaigns/{id}/metrics/refresh — synchronous platform re-pull.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/metrics/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_campaign_metrics' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /metrics/summary — aggregate KPIs across platforms.
		register_rest_route(
			self::REST_NAMESPACE,
			'/metrics/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_metrics_summary' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// GET /platforms.
		register_rest_route(
			self::REST_NAMESPACE,
			'/platforms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_platforms' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// GET /platforms/status.
		register_rest_route(
			self::REST_NAMESPACE,
			'/platforms/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_platform_status' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// GET /audiences.
		register_rest_route(
			self::REST_NAMESPACE,
			'/audiences',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audiences' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// POST /creatives.
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_creatives' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_creative' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
			)
		);

		// GET /creatives/{id} (Story 4.3 — polling for generation status).
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives/(?P<id>[\\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_creative' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// PATCH /creatives/{id}/approval (Story 4.4 — approve/unapprove formats).
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives/(?P<id>[\\w-]+)/approval',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_creative_approval' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /creatives/{id}/regenerate (Story 4.4 — one-click refresh).
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives/(?P<id>[\\w-]+)/regenerate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_creative' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /creatives/{id}/confirm — finish the S3 upload handshake.
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives/(?P<id>[\\w-]+)/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm_creative' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /creatives/{id}/upload/{platform} — retry per-platform upload.
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives/(?P<id>[\\w-]+)/upload/(?P<platform>[\\w-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_creative_to_platform' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'platform' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// GET /creatives/{id}/platform-status — list per-platform upload status.
		register_rest_route(
			self::REST_NAMESPACE,
			'/creatives/(?P<id>[\\w-]+)/platform-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_creative_platform_status' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// POST /audiences (Story 4.2 — save audience configuration).
		register_rest_route(
			self::REST_NAMESPACE,
			'/audiences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_audience' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		// PATCH /audiences/{id} — kept registered so stale clients get an
		// explicit 405 (the backend has no audience-update route).
		register_rest_route(
			self::REST_NAMESPACE,
			'/audiences/(?P<id>[\\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_audience' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_audience' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /audiences/estimate (Story 4.2 — audience size estimate).
		register_rest_route(
			self::REST_NAMESPACE,
			'/audiences/estimate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'estimate_audience' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// POST /audiences/{id}/upload — append rows to a customer-list audience.
		register_rest_route(
			self::REST_NAMESPACE,
			'/audiences/(?P<id>[\\w-]+)/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_audience' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// POST /audiences/{id}/lookalike — create a lookalike from an audience.
		register_rest_route(
			self::REST_NAMESPACE,
			'/audiences/(?P<id>[\\w-]+)/lookalike',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_lookalike' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// POST /campaigns/{id}/sync — manual platform sync.
		register_rest_route(
			self::REST_NAMESPACE,
			'/campaigns/(?P<id>[\\w-]+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_campaign' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// GET /health — backend health probe (used by WP Site Health).
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// GET /targeting-options/{kind} — unified targeting picker data.
		// kind ∈ countries | interests | behaviors | languages | cities | demographics.
		register_rest_route(
			self::REST_NAMESPACE,
			'/targeting-options/(?P<kind>[a-z-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_targeting_options' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'kind' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// GET /org/me — current org (channel name + credentialMode).
		register_rest_route(
			self::REST_NAMESPACE,
			'/org/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_org_me' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// POST /org/credential-mode — flip between agency / own.
		register_rest_route(
			self::REST_NAMESPACE,
			'/org/credential-mode',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_credential_mode' ),
				'permission_callback' => array( $this, 'check_manage_accounts' ),
				'args'                => array(
					'mode' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'agency', 'own' ),
					),
				),
			)
		);

		// GET /org/system-credentials — system creds available in agency mode.
		register_rest_route(
			self::REST_NAMESPACE,
			'/org/system-credentials',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_system_credentials' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// GET /pixels — list pixels for the current org.
		register_rest_route(
			self::REST_NAMESPACE,
			'/pixels',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_pixels' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);
	}

	/**
	 * Permission callback for read (GET) routes.
	 *
	 * @return bool True if the current user can view campaigns.
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'af_view_campaigns' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for write (POST/PATCH) routes.
	 *
	 * @return bool True if the current user can manage campaigns.
	 */
	public function check_write_permission(): bool {
		return current_user_can( 'af_manage_campaigns' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for public portal READ routes.
	 *
	 * Two accepted callers:
	 *   1. An authenticated WordPress user with the AF read capability
	 *      (the site owner browsing the portal while logged in).
	 *   2. An anonymous visitor holding a valid, same-site portal token —
	 *      but ONLY for non-mutating GET/HEAD requests.
	 *
	 * The anonymous portal token is a bare "this site issued this" credential
	 * with no per-user or per-campaign binding, so it must never authorize a
	 * state change or reach a money/PII route. Mutations and every `/payments`
	 * route use {@see check_write_permission()} / {@see check_read_permission()}
	 * instead, which require an authenticated WordPress user.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function check_public_permission( WP_REST_Request $request ): bool|WP_Error {
		$method      = strtoupper( (string) $request->get_method() );
		$is_mutating = ! in_array( $method, array( 'GET', 'HEAD' ), true );

		// Authenticated WordPress users: same-origin browser requests must carry a
		// valid X-WP-Nonce on writes so an admin's session cookies alone cannot be
		// abused via CSRF from another tab/site.
		if ( $this->check_read_permission() ) {
			if ( ! $is_mutating ) {
				return true;
			}

			if ( ! $this->check_write_permission() ) {
				return new WP_Error(
					'af_forbidden',
					__( 'You do not have permission to modify campaigns.', 'bushido-almost-famous' ),
					array( 'status' => 403 )
				);
			}

			$nonce = (string) $request->get_header( 'x-wp-nonce' );
			if ( '' === $nonce ) {
				return new WP_Error(
					'af_csrf_missing',
					__( 'A REST nonce is required for write requests. Refresh the page and try again.', 'bushido-almost-famous' ),
					array( 'status' => 403 )
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'af_csrf_invalid',
					__( 'REST nonce is invalid or expired.', 'bushido-almost-famous' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		// The portal is an authenticated console. Anonymous or under-privileged
		// visitors get the sign-in gate client-side and NO data server-side —
		// the only exception is demo mode, which serves synthetic fixtures (no
		// real org data) and is therefore safe to preview without a login.
		// Writes are never allowed for guests. The public [af-campaign-widget]
		// renders server-side with the site key and does not use this surface.
		if ( $is_mutating ) {
			return new WP_Error(
				'af_portal_write_forbidden',
				__( 'Portal write operations require a signed-in WordPress user.', 'bushido-almost-famous' ),
				array( 'status' => 401 )
			);
		}

		if ( Config::is_demo_mode_enabled() ) {
			return true;
		}

		if ( is_user_logged_in() ) {
			return new WP_Error(
				'af_forbidden',
				__( 'You do not have permission to view campaigns.', 'bushido-almost-famous' ),
				array( 'status' => 403 )
			);
		}

		return new WP_Error(
			'af_portal_auth_required',
			__( 'Sign in to view your campaigns.', 'bushido-almost-famous' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Permission callback for the credential-mode / accounts routes.
	 *
	 * @return bool
	 */
	public function check_manage_accounts(): bool {
		return current_user_can( 'af_manage_accounts' ) || current_user_can( 'manage_options' );
	}

	/**
	 * GET /org/me — passthrough to backend `/public/orgs/me`.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_org_me( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->format_response(
			array(
				'data'   => $this->client->get_own_org(),
				'status' => 200,
			),
			false
		);
	}

	/**
	 * POST /org/credential-mode — flip the org credential mode.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function update_credential_mode( WP_REST_Request $request ): WP_REST_Response {
		$mode = (string) $request->get_param( 'mode' );
		if ( ! in_array( $mode, array( 'agency', 'own' ), true ) ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'validation_error',
						'message' => __( 'mode must be "agency" or "own".', 'bushido-almost-famous' ),
						'detail'  => $mode,
					),
				),
				422
			);
		}

		// The org-scoped endpoint resolves the caller's org from the
		// API key, so no orgId in the path. Falls back to no-op on
		// network failure (caller already sees a JS alert).
		return $this->format_response(
			$this->client->patch( '/orgs/me/credential-mode', array( 'credentialMode' => $mode ) ),
			false
		);
	}

	/**
	 * GET /org/system-credentials — list Bushido shared credentials.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_system_credentials( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->format_response(
			array(
				'data'   => $this->client->list_system_credentials(),
				'status' => 200,
			),
			false
		);
	}

	/**
	 * GET /pixels — list pixels owned by the current org.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function list_pixels( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->format_response(
			array(
				'data'   => $this->client->get_pixels(),
				'status' => 200,
			),
			false
		);
	}

	/**
	 * POST /audiences/{id}/upload — append rows to a customer-list audience.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function upload_audience( WP_REST_Request $request ): WP_REST_Response {
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$data = $this->sanitize_request_body( $request );
		return $this->format_response(
			$this->client->post( '/audiences/' . $id . '/upload', $data ),
			false
		);
	}

	/**
	 * POST /audiences/{id}/lookalike — create a lookalike from an audience.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function create_lookalike( WP_REST_Request $request ): WP_REST_Response {
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$data = $this->sanitize_request_body( $request );
		return $this->format_response(
			$this->client->post( '/audiences/' . $id . '/lookalike', $data ),
			false
		);
	}

	/**
	 * POST /campaigns/{id}/sync — manual platform sync.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function sync_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		return $this->format_response(
			$this->client->post( '/campaigns/' . $id . '/sync', array() ),
			false
		);
	}

	/**
	 * GET /health — backend health probe.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->format_response(
			$this->client->get_top( '/health' ),
			false
		);
	}

	/**
	 * GET /targeting-options/{kind} — unified targeting picker data.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_targeting_options( WP_REST_Request $request ): WP_REST_Response {
		$kind   = sanitize_key( (string) $request->get_param( 'kind' ) );
		$query  = (string) $request->get_param( 'q' );
		$path   = '/targeting-options/all/' . $kind;
		$params = array();
		if ( '' !== $query ) {
			$params['q'] = $query;
		}

		// 24h cache for countries (rarely change); 1h for everything else.
		$ttl_seconds   = 'countries' === $kind ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
		$transient_key = 'af_targeting_' . md5( $kind . '|' . $query );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $this->format_response( $cached, false );
		}

		$response = $this->client->get( $path, $params );
		if ( ! isset( $response['error'] ) ) {
			set_transient( $transient_key, $response, $ttl_seconds );
		}
		return $this->format_response( $response, false );
	}

	/**
	 * POST /creatives/{id}/confirm — finish the S3 upload handshake.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function confirm_creative( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		return $this->format_response(
			$this->client->post( '/assets/' . $id . '/confirm', array() ),
			false
		);
	}

	/**
	 * POST /creatives/{id}/upload/{platform} — retry per-platform upload.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function upload_creative_to_platform( WP_REST_Request $request ): WP_REST_Response {
		$id       = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		$data     = $this->sanitize_request_body( $request );
		return $this->format_response(
			$this->client->post( '/assets/' . $id . '/upload-platform/' . $platform, $data ),
			false
		);
	}

	/**
	 * GET /creatives/{id}/platform-status — list per-platform upload status.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_creative_platform_status( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		return $this->format_response(
			$this->client->get( '/assets/' . $id . '/platform-status' ),
			false
		);
	}

	/**
	 * GET /campaigns — list all campaigns.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_campaigns( WP_REST_Request $request ): WP_REST_Response {
		$params = array(
			'page'  => $request->get_param( 'page' ),
			'limit' => $request->get_param( 'per_page' ),
		);

		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) ) {
			$params['status'] = $status;
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$params['search'] = $search;
		}

		$cache_key = $this->cache->build_key( 'campaigns', md5( wp_json_encode( $params ) ) );

		return $this->cached_get( '/campaigns', $params, $cache_key, 'campaigns' );
	}

	/**
	 * POST /campaigns — create a new campaign.
	 *
	 * Includes budget safety pre-flight check (Story 3.4).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function create_campaign( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->sanitize_request_body( $request );

		// Budget safety pre-flight check (Story 3.4).
		$budget_check = $this->check_budget_safety( $data );
		if ( null !== $budget_check ) {
			return $budget_check;
		}

		$result = $this->client->post( '/campaigns', $data );

		// Invalidate campaign list cache on successful creation.
		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * GET /campaigns/{id} — get a single campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id        = sanitize_text_field( $request->get_param( 'id' ) );
		$cache_key = $this->cache->build_key( 'campaigns', $id );

		return $this->cached_get( '/campaigns/' . $id, array(), $cache_key, 'campaigns' );
	}

	/**
	 * PATCH /campaigns/{id} — update a campaign.
	 *
	 * Includes budget safety pre-flight check (Story 3.4).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function update_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id   = sanitize_text_field( $request->get_param( 'id' ) );
		$data = $this->sanitize_request_body( $request );

		// Budget safety pre-flight check (Story 3.4).
		$budget_check = $this->check_budget_safety( $data );
		if ( null !== $budget_check ) {
			return $budget_check;
		}

		$result = $this->client->patch( '/campaigns/' . $id, $data );

		// Invalidate caches for this campaign and the list.
		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $id ) );
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * POST /campaigns/{id}/checkout — create a checkout session.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function create_checkout( WP_REST_Request $request ): WP_REST_Response {
		$id   = sanitize_text_field( $request->get_param( 'id' ) );
		$data = $this->sanitize_request_body( $request );

		$data['campaignId'] = $id;

		$result = $this->client->post( '/payments/checkout', $data );

		return $this->format_response( $result, false );
	}

	/**
	 * GET /payments — list all payments.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_payments( WP_REST_Request $request ): WP_REST_Response {
		$params = array(
			'page'  => $request->get_param( 'page' ),
			'limit' => $request->get_param( 'per_page' ),
		);

		$campaign_id = $request->get_param( 'campaignId' );
		if ( ! empty( $campaign_id ) ) {
			$params['campaignId'] = sanitize_text_field( (string) $campaign_id );
		}

		$result = $this->client->get( '/payments', $params );

		return $this->format_response( $result, false );
	}

	/**
	 * GET /payments/{id} — get a single payment.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_payment( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->get( '/payments/' . $id );

		return $this->format_response( $result, false );
	}

	/**
	 * POST /payments/{id}/refund — request a refund for a payment.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function refund_payment( WP_REST_Request $request ): WP_REST_Response {
		$id   = sanitize_text_field( $request->get_param( 'id' ) );
		$data = $this->sanitize_request_body( $request );

		$result = $this->client->post( '/payments/' . $id . '/refund', $data );

		return $this->format_response( $result, false );
	}

	/**
	 * POST /campaigns/{id}/launch — launch a campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function launch_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id   = sanitize_text_field( $request->get_param( 'id' ) );
		$data = $this->sanitize_request_body( $request );

		$campaign = null;

		if ( empty( $data['platformAllocations'] ) ) {
			$campaign_result = $this->client->get( '/campaigns/' . $id );
			if ( ! isset( $campaign_result['error'] ) && is_array( $campaign_result['data'] ?? null ) ) {
				$campaign = $campaign_result['data'];
			}
			if ( null !== $campaign && isset( $campaign['campaignPlatforms'] ) && is_array( $campaign['campaignPlatforms'] ) ) {
				$allocations = array();
				foreach ( $campaign['campaignPlatforms'] as $platform_row ) {
					if ( ! is_array( $platform_row ) || empty( $platform_row['platform'] ) ) {
						continue;
					}

					$allocations[ strtoupper( (string) $platform_row['platform'] ) ] = (float) ( $platform_row['budgetAllocation'] ?? 0 );
				}

				$data['platformAllocations'] = $allocations;
			}
		}

		// Pre-flight against the org-effective platform daily-budget floors so
		// users get an actionable error before the backend's launch validator.
		$floor_error = $this->check_platform_budget_floors( $id, $data, $campaign );
		if ( null !== $floor_error ) {
			return $floor_error;
		}

		$result = $this->client->post( '/campaigns/' . $id . '/launch', $data );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $id ) );
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * Validate per-platform daily spend against the org-effective floors
	 * from GET /config (`platformMinimumDailyBudget`).
	 *
	 * Allocations are percent shares of the campaign's daily budget. When
	 * the daily budget or the config is unavailable the check is skipped —
	 * the backend launch validator remains authoritative.
	 *
	 * @param string     $id       Campaign id.
	 * @param array      $data     Launch payload (may carry platformAllocations).
	 * @param array|null $campaign Campaign row when already fetched, else null.
	 * @return WP_REST_Response|null Error response, or null when the check passes.
	 */
	private function check_platform_budget_floors( string $id, array $data, ?array $campaign ): ?WP_REST_Response {
		$allocations = isset( $data['platformAllocations'] ) && is_array( $data['platformAllocations'] )
			? $data['platformAllocations']
			: array();

		if ( empty( $allocations ) ) {
			return null;
		}

		if ( null === $campaign ) {
			$campaign_result = $this->client->get( '/campaigns/' . $id );
			if ( isset( $campaign_result['error'] ) || ! is_array( $campaign_result['data'] ?? null ) ) {
				return null;
			}
			$campaign = $campaign_result['data'];
		}

		$budget_daily = (float) ( $campaign['budgetDaily'] ?? 0 );
		if ( $budget_daily <= 0 ) {
			return null;
		}

		$config = $this->client->get_af_config();
		$floors = isset( $config['platformMinimumDailyBudget'] ) && is_array( $config['platformMinimumDailyBudget'] )
			? $config['platformMinimumDailyBudget']
			: array();

		if ( empty( $floors ) ) {
			return null;
		}

		// Backend platform → config floor. META covers both Meta placements,
		// so it must clear the higher of the two; YouTube launches as Google
		// Demand Gen but keeps its own floor entry.
		$floor_for = array(
			'META'    => max( (float) ( $floors['instagram'] ?? 0 ), (float) ( $floors['facebook'] ?? 0 ) ),
			'GOOGLE'  => (float) ( $floors['youtube'] ?? $floors['google'] ?? 0 ),
			'TIKTOK'  => (float) ( $floors['tiktok'] ?? 0 ),
			'SPOTIFY' => (float) ( $floors['spotify'] ?? 0 ),
		);

		$violations = array();
		foreach ( $allocations as $platform => $share ) {
			$platform = strtoupper( (string) $platform );
			$share    = (float) $share;
			$floor    = $floor_for[ $platform ] ?? 0.0;

			if ( $share <= 0 || $floor <= 0 ) {
				continue;
			}

			$platform_daily = $budget_daily * $share / 100;
			if ( $platform_daily + 0.005 < $floor ) {
				$violations[] = sprintf(
					/* translators: 1: platform name, 2: allocated daily amount, 3: required minimum. */
					__( '%1$s: $%2$s/day allocated, minimum is $%3$s/day', 'bushido-almost-famous' ),
					ucfirst( strtolower( $platform ) ),
					number_format( $platform_daily, 2 ),
					number_format( $floor, 2 )
				);
			}
		}

		if ( empty( $violations ) ) {
			return null;
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: semicolon-separated list of per-platform budget problems. */
					__( 'Daily budget is below the platform minimum: %s. Raise the daily budget or shift the allocation.', 'bushido-almost-famous' ),
					implode( '; ', $violations )
				),
			),
			422
		);
	}

	/**
	 * POST /campaigns/{id}/pause — pause a running campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function pause_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->post( '/campaigns/' . $id . '/pause', array() );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $id ) );
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * POST /campaigns/{id}/resume — resume a paused campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function resume_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->post( '/campaigns/' . $id . '/resume', array() );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $id ) );
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * POST /campaigns/{id}/archive — archive a campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function archive_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->delete( '/campaigns/' . $id );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete( $this->cache->build_key( 'campaigns', $id ) );
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * POST /campaigns/{id}/duplicate — duplicate a campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function duplicate_campaign( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->post( '/campaigns/' . $id . '/duplicate', array() );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete_by_prefix( 'campaigns_' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * GET /campaigns/{id}/platforms — get campaign platform allocations.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_campaign_platforms( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->get( '/campaigns/' . $id . '/platforms' );

		return $this->format_response( $result, false );
	}

	/**
	 * GET /campaigns/{id}/analytics — get analytics for a campaign.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_campaign_analytics( WP_REST_Request $request ): WP_REST_Response {
		$id         = sanitize_text_field( $request->get_param( 'id' ) );
		$query      = $this->build_metrics_query( $request );
		$metrics    = $this->client->get( '/campaigns/' . $id . '/metrics', $query );
		$comparison = $this->client->get( '/campaigns/' . $id . '/metrics/comparison', $query );

		if ( isset( $metrics['error'] ) ) {
			return $this->format_response( $metrics, false );
		}

		if ( isset( $comparison['error'] ) ) {
			return $this->format_response( $comparison, false );
		}

		$points = array();
		if ( isset( $metrics['data']['data'] ) && is_array( $metrics['data']['data'] ) ) {
			$points = $metrics['data']['data'];
		} elseif ( isset( $metrics['data'] ) && is_array( $metrics['data'] ) ) {
			$points = $metrics['data'];
		}

		return $this->format_response(
			array(
				'data'   => array(
					'summary'    => $this->summarize_metrics( $points ),
					'dataPoints' => $points,
					'platforms'  => $this->normalize_platform_comparison( $comparison['data'] ?? array() ),
				),
				'status' => 200,
			),
			false
		);
	}

	/**
	 * POST /campaigns/{id}/metrics/refresh — ask the backend to re-pull
	 * fresh metrics from every platform for the campaign's flight range,
	 * then drop the plugin's cached aggregates so the next read refetches.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function refresh_campaign_metrics( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->post( '/campaigns/' . $id . '/metrics/refresh' );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete_by_prefix( 'campaigns_' );
			$this->cache->delete( $this->cache->build_key( 'campaigns', $id ) );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * GET /metrics/summary — aggregate KPIs across platforms.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_metrics_summary( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->format_response( $this->client->get( '/metrics/summary' ), false );
	}

	/**
	 * GET /platforms — list available platforms.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_platforms( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$status_result = $this->client->get( '/platforms/insights/summary', $this->insights_window() );

		if ( isset( $status_result['error'] ) ) {
			$health_result = $this->client->get( '/metrics/summary' );
			if ( isset( $health_result['error'] ) ) {
				return $this->format_response( $health_result, false );
			}
		}

		return $this->format_response(
			array(
				'data'   => $this->get_supported_platform_catalog(),
				'status' => 200,
			),
			false
		);
	}

	/**
	 * GET /platforms/status — get platform status.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_platform_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$result = $this->client->get( '/platforms/insights/summary', $this->insights_window() );

		return $this->format_response( $result, false );
	}

	/**
	 * Date window for the platform insights endpoint.
	 *
	 * The backend contract requires startDate and endDate; without them the
	 * request is rejected with a 400. A trailing 7-day window is enough for
	 * the health/status surfaces this proxy feeds.
	 *
	 * @return array{startDate: string, endDate: string}
	 */
	private function insights_window(): array {
		return array(
			'startDate' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			'endDate'   => gmdate( 'Y-m-d' ),
		);
	}

	/**
	 * GET /audiences — list audiences.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_audiences( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$cache_key = $this->cache->build_key( 'audiences' );

		return $this->cached_get( '/audiences', array(), $cache_key, 'campaigns' );
	}

	/**
	 * GET /creatives — list asset-backed creatives.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_creatives( WP_REST_Request $request ): WP_REST_Response {
		$params = array();

		if ( null !== $request->get_param( 'page' ) ) {
			$params['page'] = absint( $request->get_param( 'page' ) );
		}

		if ( null !== $request->get_param( 'per_page' ) ) {
			$params['limit'] = absint( $request->get_param( 'per_page' ) );
		}

		$result = $this->client->get( '/assets', $params );

		if ( isset( $result['error'] ) ) {
			return $this->format_response( $result, false );
		}

		$creatives = array();
		foreach ( $result['data'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$creatives[] = $this->transform_asset_to_creative( $asset );
		}

		return $this->format_response(
			array(
				'data'   => $creatives,
				'status' => $result['status'] ?? 200,
			),
			false
		);
	}

	/**
	 * POST /creatives — upload a creative asset.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function create_creative( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->sanitize_request_body( $request );

		$result = $this->client->post( '/assets', $data );

		// Invalidate creatives cache on successful upload.
		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete_by_prefix( 'creatives' );
		}

		if ( isset( $result['error'] ) ) {
			return $this->format_response( $result, false );
		}

		$asset    = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$creative = $this->transform_asset_to_creative( $asset );

		// The create response carries a presigned uploadUrl the caller must PUT
		// the file bytes to before confirming. The creative transform drops it
		// (it is not an asset property), so re-attach it — without it the REST
		// create → upload → confirm handshake can never complete.
		if ( isset( $asset['uploadUrl'] ) && is_string( $asset['uploadUrl'] ) ) {
			$creative['uploadUrl'] = esc_url_raw( $asset['uploadUrl'] );
		}

		return $this->format_response(
			array(
				'data'   => $creative,
				'status' => $result['status'] ?? 200,
			),
			false
		);
	}

	/**
	 * GET /creatives/{id} — get a single creative (Story 4.3 polling).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function get_creative( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		// Do not cache processing creatives — always fetch fresh for polling.
		$result = $this->client->get( '/assets/' . $id );

		if ( isset( $result['error'] ) ) {
			return $this->format_response( $result, false );
		}

		return $this->format_response(
			array(
				'data'   => $this->transform_asset_to_creative( is_array( $result['data'] ?? null ) ? $result['data'] : array() ),
				'status' => $result['status'] ?? 200,
			),
			false
		);
	}

	/**
	 * PATCH /creatives/{id}/approval — approve/unapprove formats (Story 4.4).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function update_creative_approval( WP_REST_Request $request ): WP_REST_Response {
		$id        = sanitize_text_field( $request->get_param( 'id' ) );
		$data      = $this->sanitize_request_body( $request );
		$action    = sanitize_text_field( (string) ( $data['action'] ?? '' ) );
		$format_id = sanitize_text_field( (string) ( $data['format_id'] ?? '' ) );

		if ( '' === $id || '' === $action ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'validation_error',
						'message' => __( 'Creative ID and approval action are required.', 'bushido-almost-famous' ),
						'detail'  => '',
					),
				),
				422
			);
		}

		$asset_result = $this->client->get( '/assets/' . $id );
		if ( isset( $asset_result['error'] ) ) {
			return $this->format_response( $asset_result, false );
		}

		$creative = $this->transform_asset_to_creative( is_array( $asset_result['data'] ?? null ) ? $asset_result['data'] : array() );

		$format_ids = array();
		if ( 'approve_all' === $action ) {
			$format_ids = array_filter(
				array_map(
					static fn( array $format ): string => (string) ( $format['id'] ?? '' ),
					$creative['formats'] ?? array()
				)
			);
		} elseif ( '' !== $format_id ) {
			$format_ids = array( $format_id );
		}

		Creative_Assets::update_approval_state( $id, $action, $format_ids );
		$this->cache->delete_by_prefix( 'creatives' );

		$asset_result = $this->client->get( '/assets/' . $id );
		if ( isset( $asset_result['error'] ) ) {
			return $this->format_response( $asset_result, false );
		}

		return $this->format_response(
			array(
				'data'   => $this->transform_asset_to_creative( is_array( $asset_result['data'] ?? null ) ? $asset_result['data'] : array() ),
				'status' => 200,
			),
			false
		);
	}

	/**
	 * POST /creatives/{id}/regenerate — regenerate all formats (Story 4.4).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function regenerate_creative( WP_REST_Request $request ): WP_REST_Response {
		$id          = sanitize_text_field( $request->get_param( 'id' ) );
		$source_meta = Creative_Assets::get_source_meta( $id );
		$name        = sanitize_text_field( (string) ( $source_meta['name'] ?? __( 'Regenerated Creative', 'bushido-almost-famous' ) ) );
		$file_path   = sanitize_text_field( (string) ( $source_meta['file_path'] ?? '' ) );
		$mime_type   = sanitize_text_field( (string) ( $source_meta['mime_type'] ?? '' ) );
		$file_size   = absint( $source_meta['file_size'] ?? 0 );

		if ( '' === $id || '' === $file_path || '' === $mime_type || $file_size <= 0 ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'validation_error',
						'message' => __( 'This creative cannot be regenerated because its source file is not available locally.', 'bushido-almost-famous' ),
						'detail'  => $id,
					),
				),
				422
			);
		}

		$result = Creative_Assets::upload_asset_from_file(
			$this->client,
			$name,
			$file_path,
			$mime_type,
			$file_size,
			$source_meta
		);

		$this->cache->delete_by_prefix( 'creatives' );

		if ( isset( $result['error'] ) ) {
			return $this->format_response( $result, false );
		}

		return $this->format_response(
			array(
				'data'   => $this->transform_asset_to_creative( is_array( $result['data'] ?? null ) ? $result['data'] : array() ),
				'status' => $result['status'] ?? 200,
			),
			false
		);
	}

	/**
	 * POST /audiences — save a new audience configuration (Story 4.2).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function create_audience( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->sanitize_request_body( $request );

		$result = $this->client->post( '/audiences', $data );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete_by_prefix( 'audiences' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * PATCH /audiences/{id} — not supported by the backend.
	 *
	 * The Bushido API exposes create/list/get/delete (plus upload and
	 * lookalike) for audiences but no update route. Editing would silently
	 * fork state, so the proxy answers 405 and the admin UI offers no Edit
	 * action.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function update_audience( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return new WP_REST_Response(
			array(
				'success' => false,
				'error'   => __( 'Audiences cannot be edited after creation. Create a new audience instead.', 'bushido-almost-famous' ),
			),
			405
		);
	}

	/**
	 * DELETE /audiences/{id} — remove an audience.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_audience( WP_REST_Request $request ): WP_REST_Response {
		$id = sanitize_text_field( $request->get_param( 'id' ) );

		$result = $this->client->delete( '/audiences/' . $id );

		if ( ! isset( $result['error'] ) ) {
			$this->cache->delete_by_prefix( 'audiences' );
		}

		return $this->format_response( $result, false );
	}

	/**
	 * POST /audiences/estimate — estimate audience size (Story 4.2).
	 *
	 * Translates the audience-builder form payload into the backend's
	 * unified reach-estimation contract (`POST /reach-estimation/all`),
	 * which needs explicit (platform, credentialId) pairs plus a targeting
	 * blob, then folds the per-platform rows back into the `{total,
	 * by_platform}` shape the admin JS renders.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function estimate_audience( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->sanitize_request_body( $request );

		$credentials = $this->resolve_estimation_credentials();

		if ( empty( $credentials ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'No advertising credential is available. Connect an account or ask a network administrator to configure agency credentials.', 'bushido-almost-famous' ),
				),
				400
			);
		}

		$targeting = array();

		if ( ! empty( $data['countries'] ) ) {
			$countries = is_array( $data['countries'] )
				? $data['countries']
				: array_map( 'trim', explode( ',', (string) $data['countries'] ) );

			$targeting['countries'] = array_values( array_filter( array_map( 'strtoupper', $countries ) ) );
		}

		// The estimation adapters consume the canonical TargetingSchema keys,
		// which are snake_case (age_min/age_max) — camelCase variants pass the
		// loose contract but are silently ignored downstream.
		if ( isset( $data['age_min'] ) && '' !== $data['age_min'] ) {
			$targeting['age_min'] = (int) $data['age_min'];
		}
		if ( isset( $data['age_max'] ) && '' !== $data['age_max'] ) {
			$targeting['age_max'] = (int) $data['age_max'];
		}
		if ( ! empty( $data['genders'] ) && is_array( $data['genders'] ) ) {
			$targeting['genders'] = array_map( 'sanitize_text_field', $data['genders'] );
		}

		// Free-text interest names are NOT forwarded: the adapters resolve
		// interests from platform-native {id, name} entries (found via the
		// targeting-options search endpoints), so bare names would be silently
		// ignored and give the estimate false precision.

		$result = $this->client->post(
			'/reach-estimation/all',
			array(
				'credentials' => $credentials,
				'targeting'   => empty( $targeting ) ? (object) array() : $targeting,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $this->format_response( $result, false );
		}

		$rows        = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$total       = 0;
		$by_platform = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['estimateReady'] ) ) {
				continue;
			}

			// Backend platforms are UPPERCASE and YouTube runs as GOOGLE;
			// the audience-builder UI keys its counters by the plugin's
			// lowercase platform slugs.
			$platform = strtolower( (string) ( $row['platform'] ?? '' ) );
			if ( 'google' === $platform ) {
				$platform = 'youtube';
			}

			$lower    = (float) ( $row['estimatedReachLower'] ?? 0 );
			$upper    = (float) ( $row['estimatedReachUpper'] ?? $lower );
			$estimate = (int) round( ( $lower + $upper ) / 2 );

			if ( '' !== $platform ) {
				$by_platform[ $platform ] = $estimate;
				$total                   += $estimate;
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'total'       => $total,
					'by_platform' => $by_platform,
				),
			),
			200
		);
	}

	/**
	 * Resolve (platform, credentialId) pairs for reach estimation.
	 *
	 * Own-credential orgs estimate against their active connections;
	 * agency-mode orgs fall back to Bushido's shared system credentials.
	 *
	 * @return array<int, array{platform: string, credentialId?: string}>
	 */
	private function resolve_estimation_credentials(): array {
		$pairs = array();

		foreach ( $this->client->get_connections() as $connection ) {
			if ( 'active' !== strtolower( $connection->status ) ) {
				continue;
			}
			$platform = strtoupper( $connection->platform );
			if ( ! isset( $pairs[ $platform ] ) ) {
				$pairs[ $platform ] = array(
					'platform'     => $platform,
					'credentialId' => $connection->id,
				);
			}
		}

		foreach ( $this->client->list_system_credentials() as $row ) {
			$platform = strtoupper( (string) ( $row['platform'] ?? '' ) );
			if ( '' === $platform || isset( $pairs[ $platform ] ) ) {
				continue;
			}
			// The backend resolves shared credentials by platform. Never copy
			// a system credential row id into browser-visible payloads.
			$pairs[ $platform ] = array(
				'platform' => $platform,
			);
		}

		return array_values( $pairs );
	}

	/**
	 * Pre-flight budget safety check in the proxy layer (Story 3.4).
	 *
	 * If the daily budget exceeds the configured limit, returns an error
	 * response. If it exceeds 10x the limit, returns a "review_required"
	 * response. Returns null if the check passes.
	 *
	 * @param array $data The campaign data being created or updated.
	 * @return WP_REST_Response|null Error response or null if passed.
	 */
	private function check_budget_safety( array $data ): ?WP_REST_Response {
		$daily_limit = (float) get_option( 'af_daily_budget_limit', 0 );

		if ( $daily_limit <= 0 ) {
			return null;
		}

		$daily_budget = (float) ( $data['daily_budget'] ?? $data['budgetDaily'] ?? 0 );

		if ( $daily_budget <= 0 ) {
			return null;
		}

		$settings   = get_option( 'af_settings', array() );
		$multiplier = (int) ( $settings['budget_safety_multiplier'] ?? 10 );
		$multiplier = max( 2, $multiplier );

		// Exceeds limit by multiplier-x: require manual review.
		if ( $daily_budget > $daily_limit * $multiplier ) {
			return new WP_REST_Response(
				array(
					'success'         => false,
					'review_required' => true,
					'error'           => sprintf(
						/* translators: %1$s: Daily budget, %2$s: Threshold amount. */
						'Daily budget $%1$s exceeds %3$dx the safety limit of $%2$s. Manual review required.',
						number_format( $daily_budget, 2 ),
						number_format( $daily_limit, 2 ),
						$multiplier
					),
					'meta'            => array(
						'cached'    => false,
						'timestamp' => gmdate( 'c' ),
					),
				),
				422
			);
		}

		// Exceeds daily limit: reject.
		if ( $daily_budget > $daily_limit ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %1$s: Daily budget, %2$s: Daily limit. */
						'Daily budget $%1$s exceeds the configured safety limit of $%2$s.',
						number_format( $daily_budget, 2 ),
						number_format( $daily_limit, 2 )
					),
					'meta'    => array(
						'cached'    => false,
						'timestamp' => gmdate( 'c' ),
					),
				),
				422
			);
		}

		return null;
	}

	/**
	 * Perform a cached GET request.
	 *
	 * Checks the transient cache first. On a miss, calls the Bushido API,
	 * caches the result, stores the ETag if present, and returns.
	 *
	 * @param string $endpoint  The API endpoint path.
	 * @param array  $params    Query parameters.
	 * @param string $cache_key The transient cache key.
	 * @param string $data_type The data type for TTL lookup.
	 * @return WP_REST_Response
	 */
	private function cached_get( string $endpoint, array $params, string $cache_key, string $data_type ): WP_REST_Response {
		// Check cache.
		$cached = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $this->format_response(
				array(
					'data'   => $cached,
					'status' => 200,
				),
				true,
				$this->cache->get_etag( $cache_key )
			);
		}

		// Cache miss — call the API, passing stored ETag for conditional request.
		$stored_etag = $this->cache->get_etag( $cache_key );
		$result      = $this->client->get( $endpoint, $params, $stored_etag );

		// Handle 304 Not Modified — return cached data (ETag still valid).
		if ( ! empty( $result['not_modified'] ) ) {
			$stale_data = $this->cache->get( $cache_key );
			if ( null !== $stale_data ) {
				return $this->format_response(
					array(
						'data'   => $stale_data,
						'status' => 200,
					),
					true,
					$stored_etag
				);
			}

			// The ETag outlived the data cache (ETags are kept longer than the
			// per-type data TTL). We got a 304 with `data: null` and have no
			// stale copy to serve, so re-fetch unconditionally to repopulate —
			// otherwise the list would go blank for the ETag's remaining life.
			$this->cache->delete_etag( $cache_key );
			$result = $this->client->get( $endpoint, $params );
		}

		// On success, cache the result.
		if ( ! isset( $result['error'] ) && isset( $result['data'] ) ) {
			$this->cache->set( $cache_key, $result['data'], $data_type );

			// Store ETag if present in response headers.
			if ( isset( $result['headers'] ) ) {
				$etag = '';
				if ( is_object( $result['headers'] ) && method_exists( $result['headers'], 'offsetGet' ) ) {
					$etag = (string) $result['headers']->offsetGet( 'etag' );
				} elseif ( is_array( $result['headers'] ) && isset( $result['headers']['etag'] ) ) {
					$etag = (string) $result['headers']['etag'];
				}

				if ( '' !== $etag ) {
					$this->cache->set_etag( $cache_key, $etag );
				}
			}
		}

		return $this->format_response( $result, false );
	}

	/**
	 * Format an API result into a standard WP_REST_Response envelope.
	 *
	 * Success: {data, meta: {cached, etag, timestamp}}
	 * Error:   {error: {code, message, detail}, meta: {cached: false, timestamp}}
	 *
	 * @param array  $result The Api_Client response array.
	 * @param bool   $cached Whether the data was served from cache.
	 * @param string $etag   Optional ETag for cached responses.
	 * @return WP_REST_Response
	 */
	private function format_response( array $result, bool $cached, string $etag = '' ): WP_REST_Response {
		$timestamp = gmdate( 'c' );

		// Error response.
		if ( isset( $result['error'] ) ) {
			/**
			 * The error member of the client envelope.
			 *
			 * @var Api_Error $error
			 */
			$error = $result['error'];

			$response_data = array(
				'error' => $error->to_array(),
				'meta'  => array(
					'cached'    => false,
					'timestamp' => $timestamp,
				),
			);

			return new WP_REST_Response( $response_data, $error->http_status );
		}

		// Success response.
		$response_data = array(
			'data' => $result['data'] ?? null,
			'meta' => array(
				'cached'    => $cached,
				'etag'      => $etag,
				'timestamp' => $timestamp,
			),
		);

		$status = $result['status'] ?? 200;

		return new WP_REST_Response( $response_data, $status );
	}

	/**
	 * Sanitize the JSON request body.
	 *
	 * Recursively applies sanitize_text_field to string values.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array Sanitized body parameters.
	 */
	private function sanitize_request_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return array();
		}

		$body = $this->sanitize_recursive( $body );

		// Defense in depth: the backend derives org and billing identity from
		// the site's API key alone. Strip any such fields from the forwarded
		// body so a hostile caller can never smuggle another tenant's identity
		// into a create/checkout request, even if the backend were ever to
		// trust a body-supplied identity over the key-derived one. None of the
		// plugin's own flows send these. Note: credentialId is deliberately NOT
		// stripped — several backend contracts require it in the body (audience
		// create, per-platform asset upload) to select WHICH platform credential
		// to use, and the backend validates its ownership against the key's org.
		foreach ( array( 'orgId', 'organizationId', 'apiKey', 'stripeCustomerId' ) as $forbidden_key ) {
			unset( $body[ $forbidden_key ] );
		}

		return $body;
	}

	/**
	 * Build optional metrics query arguments from the request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, string>
	 */
	private function build_metrics_query( WP_REST_Request $request ): array {
		$params = array();

		foreach ( array( 'startDate', 'endDate', 'period', 'platform' ) as $param ) {
			$value = $request->get_param( $param );
			if ( is_string( $value ) && '' !== $value ) {
				$params[ $param ] = sanitize_text_field( $value );
			}
		}

		return $params;
	}

	/**
	 * Summarize metrics points into aggregate values for the portal cards.
	 *
	 * @param array<int, array<string, mixed>> $points Metrics points.
	 * @return array<string, float|int>
	 */
	private function summarize_metrics( array $points ): array {
		$summary = array(
			'impressions' => 0,
			'clicks'      => 0,
			'spend'       => 0.0,
			'conversions' => 0,
			'reach'       => 0,
			'videoViews'  => 0,
		);

		foreach ( $points as $point ) {
			$summary['impressions'] += (int) ( $point['impressions'] ?? 0 );
			$summary['clicks']      += (int) ( $point['clicks'] ?? 0 );
			$summary['spend']       += (float) ( $point['spend'] ?? 0 );
			$summary['conversions'] += (int) ( $point['conversions'] ?? 0 );
			$summary['reach']       += (int) ( $point['reach'] ?? 0 );
			$summary['videoViews']  += (int) ( $point['videoViews'] ?? 0 );
		}

		$summary['ctr'] = $summary['impressions'] > 0 ? ( $summary['clicks'] / $summary['impressions'] ) * 100 : 0.0;
		$summary['cpc'] = $summary['clicks'] > 0 ? $summary['spend'] / $summary['clicks'] : 0.0;
		$summary['cpm'] = $summary['impressions'] > 0 ? ( $summary['spend'] / $summary['impressions'] ) * 1000 : 0.0;

		return $summary;
	}

	/**
	 * Normalize AF metrics comparison response into a flat platform array.
	 *
	 * @param mixed $comparison Comparison payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_platform_comparison( mixed $comparison ): array {
		if ( ! is_array( $comparison ) ) {
			return array();
		}

		$rows = array();
		foreach ( $comparison as $platform => $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$totals = isset( $payload['totals'] ) && is_array( $payload['totals'] ) ? $payload['totals'] : array();
			$rows[] = array_merge(
				array( 'platform' => $platform ),
				$totals
			);
		}

		return $rows;
	}

	/**
	 * Transform an AF asset into the plugin's creative response model.
	 *
	 * @param array $asset AF asset payload.
	 * @return array
	 */
	private function transform_asset_to_creative( array $asset ): array {
		$asset_id = sanitize_text_field( (string) ( $asset['id'] ?? '' ) );

		return Creative_Assets::transform_asset_to_creative(
			$asset,
			$this->fetch_platform_status_rows( $asset_id ),
			Creative_Assets::get_source_meta( $asset_id )
		);
	}

	/**
	 * Fetch per-platform status rows for an asset.
	 *
	 * @param string $asset_id Asset ID.
	 * @return array
	 */
	private function fetch_platform_status_rows( string $asset_id ): array {
		if ( '' === $asset_id ) {
			return array();
		}

		$status_result = $this->client->get( '/assets/' . $asset_id . '/platform-status' );

		if ( isset( $status_result['error'] ) ) {
			return array();
		}

		return is_array( $status_result['data'] ?? null ) ? $status_result['data'] : array();
	}

	/**
	 * Platform catalog used by the plugin's setup/settings screens.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_supported_platform_catalog(): array {
		return array(
			array(
				'id'          => 'meta',
				'name'        => __( 'Meta (Instagram / Facebook)', 'bushido-almost-famous' ),
				'status'      => 'available',
				'auth_method' => 'oauth2',
				'description' => __( 'Feed, Stories, and Reels campaigns through Meta Ads.', 'bushido-almost-famous' ),
			),
			array(
				'id'          => 'google',
				'name'        => __( 'Google Ads / YouTube', 'bushido-almost-famous' ),
				'status'      => 'available',
				'auth_method' => 'oauth2',
				'description' => __( 'Search, display, and YouTube campaign delivery.', 'bushido-almost-famous' ),
			),
			array(
				'id'          => 'spotify',
				'name'        => __( 'Spotify', 'bushido-almost-famous' ),
				'status'      => 'available',
				'auth_method' => 'oauth2',
				'description' => __( 'Audio and streaming-native promotion inventory.', 'bushido-almost-famous' ),
			),
			array(
				'id'          => 'tiktok',
				'name'        => __( 'TikTok', 'bushido-almost-famous' ),
				'status'      => 'available',
				'auth_method' => 'oauth2',
				'description' => __( 'Short-form video distribution and conversion campaigns.', 'bushido-almost-famous' ),
			),
		);
	}

	/**
	 * Recursively sanitize an array of values.
	 *
	 * @param array $data The data to sanitize.
	 * @return array Sanitized data.
	 */
	private function sanitize_recursive( array $data ): array {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$clean_key = sanitize_text_field( (string) $key );

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_recursive( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $clean_key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$sanitized[ $clean_key ] = $value;
			} elseif ( is_null( $value ) ) {
				$sanitized[ $clean_key ] = null;
			}
		}

		return $sanitized;
	}
}
