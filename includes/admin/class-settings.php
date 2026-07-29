<?php
/**
 * Settings API integration pages.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Config;
use AlmostFamous\Roles\Permissions;

/**
 * Settings API integration pages.
 *
 * Registers settings sections and fields for the plugin settings page,
 * including a platform connection and health panel.
 */
class Settings {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'af-settings';

	/**
	 * Option group for Settings API.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'af_settings_group';

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'af_settings';

	/**
	 * Transient key for cached platform data.
	 *
	 * @var string
	 */
	private const PLATFORMS_CACHE_KEY = 'af_platforms_cache';

	/**
	 * Cache TTL for platform data (5 minutes).
	 *
	 * @var int
	 */
	private const PLATFORMS_CACHE_TTL = 300;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * API cache instance.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client API client.
	 * @param Api_Cache  $cache  API cache (optional for backward compat).
	 */
	public function __construct( Api_Client $client, ?Api_Cache $cache = null ) {
		$this->client = $client;
		$this->cache  = $cache ?? new Api_Cache();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_refresh_platforms' ) );

		// AJAX handler for API connection test (Story 5.5).
		add_action( 'wp_ajax_af_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Register the settings submenu page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'bushido-almost-famous',
			__( 'Settings', 'bushido-almost-famous' ),
			__( 'Settings', 'bushido-almost-famous' ),
			'af_manage_settings',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register settings sections and fields using the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'cache_ttl_active'         => 60,
					'cache_ttl_archived'       => 300,
					'budget_safety_multiplier' => 10,
				),
			)
		);

		// General settings section.
		add_settings_section(
			'af_general_section',
			__( 'General Settings', 'bushido-almost-famous' ),
			array( $this, 'render_general_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'af_cache_ttl_active',
			__( 'Active Campaign Cache TTL (seconds)', 'bushido-almost-famous' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'af_general_section',
			array(
				'label_for' => 'af_cache_ttl_active',
				'field_key' => 'cache_ttl_active',
				'min'       => 10,
				'max'       => 3600,
			)
		);

		add_settings_field(
			'af_cache_ttl_archived',
			__( 'Archived Campaign Cache TTL (seconds)', 'bushido-almost-famous' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'af_general_section',
			array(
				'label_for' => 'af_cache_ttl_archived',
				'field_key' => 'cache_ttl_archived',
				'min'       => 60,
				'max'       => 86400,
			)
		);

		add_settings_field(
			'af_budget_safety_multiplier',
			__( 'Budget Safety Multiplier (%)', 'bushido-almost-famous' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'af_general_section',
			array(
				'label_for' => 'af_budget_safety_multiplier',
				'field_key' => 'budget_safety_multiplier',
				'min'       => 0,
				'max'       => 100,
			)
		);

		// -- Section: Budget Safety Limits (Story 3.4) --
		add_settings_section(
			'af_budget_limits_section',
			__( 'Budget Safety Limits', 'bushido-almost-famous' ),
			array( $this, 'render_budget_limits_section_description' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'af_daily_budget_limit',
			array(
				'type'              => 'number',
				'sanitize_callback' => array( $this, 'sanitize_daily_budget_limit' ),
				'default'           => 0,
			)
		);

		add_settings_field(
			'af_daily_budget_limit',
			__( 'Daily Budget Limit ($)', 'bushido-almost-famous' ),
			array( $this, 'render_daily_budget_limit_field' ),
			self::PAGE_SLUG,
			'af_budget_limits_section'
		);

		// -- Section: Campaign Defaults --
		add_settings_section(
			'af_campaign_defaults_section',
			__( 'Campaign Defaults', 'bushido-almost-famous' ),
			array( $this, 'render_campaign_defaults_section_description' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			Config::DESTINATION_PAGE_OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		add_settings_field(
			Config::DESTINATION_PAGE_OPTION,
			__( 'Default ad destination', 'bushido-almost-famous' ),
			array( $this, 'render_default_destination_field' ),
			self::PAGE_SLUG,
			'af_campaign_defaults_section',
			array( 'label_for' => Config::DESTINATION_PAGE_OPTION )
		);

		// -- Section: Privacy & Consent (Task 1.8) --
		register_setting(
			self::OPTION_GROUP,
			'af_assume_consent_no_cmp',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'af_privacy_section',
			__( 'Privacy & Consent', 'bushido-almost-famous' ),
			array( $this, 'render_privacy_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'af_assume_consent_no_cmp',
			__( 'Consent without a CMP', 'bushido-almost-famous' ),
			array( $this, 'render_assume_consent_field' ),
			self::PAGE_SLUG,
			'af_privacy_section'
		);

		// -- Section: Cache TTL Configuration (Story 5.5) --
		add_settings_section(
			'af_cache_ttl_section',
			__( 'Cache TTL Configuration', 'bushido-almost-famous' ),
			array( $this, 'render_cache_ttl_section_description' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'af_cache_ttl_platform_status',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 3600,
			)
		);

		add_settings_field(
			'af_cache_ttl_platform_status',
			__( 'Platform Status Cache TTL (seconds)', 'bushido-almost-famous' ),
			array( $this, 'render_standalone_number_field' ),
			self::PAGE_SLUG,
			'af_cache_ttl_section',
			array(
				'option_name' => 'af_cache_ttl_platform_status',
				'default'     => 3600,
				'description' => __( 'How long to cache platform status data. Default: 3600 (1 hour).', 'bushido-almost-famous' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'af_cache_ttl_campaigns_list',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 300,
			)
		);

		add_settings_field(
			'af_cache_ttl_campaigns_list',
			__( 'Campaigns List Cache TTL (seconds)', 'bushido-almost-famous' ),
			array( $this, 'render_standalone_number_field' ),
			self::PAGE_SLUG,
			'af_cache_ttl_section',
			array(
				'option_name' => 'af_cache_ttl_campaigns_list',
				'default'     => 300,
				'description' => __( 'How long to cache campaign list data. Default: 300 (5 minutes).', 'bushido-almost-famous' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'af_cache_ttl_analytics_data',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 60,
			)
		);

		add_settings_field(
			'af_cache_ttl_analytics_data',
			__( 'Analytics Cache TTL (seconds)', 'bushido-almost-famous' ),
			array( $this, 'render_standalone_number_field' ),
			self::PAGE_SLUG,
			'af_cache_ttl_section',
			array(
				'option_name' => 'af_cache_ttl_analytics_data',
				'default'     => 60,
				'description' => __( 'How long to cache analytics data. Default: 60 (1 minute).', 'bushido-almost-famous' ),
			)
		);

		// -- Section: Operations Diagnostics (Story 5.5) --
		add_settings_section(
			'af_ops_section',
			__( 'Operations Diagnostics', 'bushido-almost-famous' ),
			array( $this, 'render_ops_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'af_test_connection',
			__( 'API Connection Test', 'bushido-almost-famous' ),
			array( $this, 'render_test_connection_field' ),
			self::PAGE_SLUG,
			'af_ops_section'
		);

		add_settings_field(
			'af_webhook_log',
			__( 'Webhook Event Log', 'bushido-almost-famous' ),
			array( $this, 'render_webhook_log_field' ),
			self::PAGE_SLUG,
			'af_ops_section'
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param mixed $input Raw input from form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$sanitized = array();

		$sanitized['cache_ttl_active'] = isset( $input['cache_ttl_active'] )
			? max( 10, min( 3600, absint( $input['cache_ttl_active'] ) ) )
			: 60;

		$sanitized['cache_ttl_archived'] = isset( $input['cache_ttl_archived'] )
			? max( 60, min( 86400, absint( $input['cache_ttl_archived'] ) ) )
			: 300;

		$sanitized['budget_safety_multiplier'] = isset( $input['budget_safety_multiplier'] )
			? max( 0, min( 100, absint( $input['budget_safety_multiplier'] ) ) )
			: 10;

		return $sanitized;
	}

	/**
	 * Render general section description.
	 *
	 * @return void
	 */
	public function render_general_section_description(): void {
		echo '<p>' . esc_html__( 'Configure cache behavior and safety thresholds.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Render a numeric input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_number_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, array() );
		$value   = $options[ $args['field_key'] ] ?? '';
		printf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" min="%d" max="%d" class="small-text" />',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['field_key'] ),
			esc_attr( (string) $value ),
			(int) ( $args['min'] ?? 0 ),
			(int) ( $args['max'] ?? 99999 )
		);
	}

	/**
	 * Handle the "Check for new platforms" action (cache bypass).
	 *
	 * @return void
	 */
	public function handle_refresh_platforms(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['af_refresh_platforms'] ) ) {
			return;
		}

		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'af_refresh_platforms', 'af_platforms_nonce' );

		// Delete the cached data to force a fresh API call.
		delete_transient( self::PLATFORMS_CACHE_KEY );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&af_platforms_refreshed=1' ) );
		exit;
	}

	/**
	 * Build the per-platform status list, cached.
	 *
	 * There is no backend platform-catalog route; connection state comes
	 * from `GET /auth/connections` (the org's own OAuth credentials) with
	 * Bushido's shared system credentials as the agency-mode fallback.
	 *
	 * @return array Array of platform data ({name, platform, status}).
	 */
	public function get_platforms(): array {
		$cached = get_transient( self::PLATFORMS_CACHE_KEY );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$status_by_platform = array();

		foreach ( $this->client->get_connections() as $connection ) {
			$key = strtoupper( $connection->platform );
			if ( isset( $status_by_platform[ $key ] ) ) {
				continue;
			}
			$health = strtolower( (string) ( $connection->lastHealthStatus ?? '' ) );

			// The backend health checker reports healthy/unhealthy (never the
			// literal "degraded"), so map any non-healthy verdict to this
			// table's degraded state instead of silently showing Connected.
			$status_by_platform[ $key ] = ( '' !== $health && 'healthy' !== $health )
				? 'degraded'
				: strtolower( $connection->status );
		}

		foreach ( $this->client->list_system_credentials() as $row ) {
			$key = strtoupper( (string) ( $row['platform'] ?? '' ) );
			if ( '' === $key || isset( $status_by_platform[ $key ] ) ) {
				continue;
			}
			$status_by_platform[ $key ] = strtolower( (string) ( $row['status'] ?? 'active' ) );
		}

		$platforms = array();
		foreach ( array( 'META', 'GOOGLE', 'TIKTOK', 'SPOTIFY' ) as $platform ) {
			$platforms[] = array(
				'name'     => ucfirst( strtolower( $platform ) ),
				'platform' => $platform,
				'status'   => $status_by_platform[ $platform ] ?? 'disconnected',
			);
		}

		set_transient( self::PLATFORMS_CACHE_KEY, $platforms, self::PLATFORMS_CACHE_TTL );

		return $platforms;
	}

	/**
	 * Get the status display info for a platform.
	 *
	 * @param array $platform Platform data.
	 * @return array{label: string, class: string, icon: string} Status display info.
	 */
	public function get_platform_status_display( array $platform ): array {
		$status = $platform['status'] ?? 'unknown';

		switch ( $status ) {
			case 'connected':
			case 'active':
			case 'ok':
				return array(
					'label' => __( 'Connected', 'bushido-almost-famous' ),
					'class' => 'af-status--connected',
					'icon'  => 'dashicons-yes-alt',
				);
			case 'degraded':
				return array(
					'label' => __( 'Degraded', 'bushido-almost-famous' ),
					'class' => 'af-status--degraded',
					'icon'  => 'dashicons-warning',
				);
			case 'disconnected':
			default:
				return array(
					'label' => __( 'Disconnected', 'bushido-almost-famous' ),
					'class' => 'af-status--disconnected',
					'icon'  => 'dashicons-dismiss',
				);
		}
	}

	/**
	 * Render budget limits section description (Story 3.4).
	 *
	 * @return void
	 */
	public function render_budget_limits_section_description(): void {
		echo '<p>' . esc_html__(
			'Set safety limits to prevent accidental overspending. Campaigns exceeding the daily limit will be blocked. Campaigns exceeding 10x the limit will be held for manual review.',
			'bushido-almost-famous'
		) . '</p>';
	}

	/**
	 * Render the daily budget limit field (Story 3.4).
	 *
	 * @return void
	 */
	/**
	 * Describe the Campaign Defaults section.
	 *
	 * @return void
	 */
	public function render_campaign_defaults_section_description(): void {
		echo '<p>' . esc_html__( 'Starting values used when your team creates a campaign in the console. Every campaign can still override them.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Render the default ad-destination page picker.
	 *
	 * @return void
	 */
	public function render_default_destination_field(): void {
		// wp_dropdown_pages() builds and escapes its own <select>. The sniff
		// flags the arguments of any printing function, but these are a class
		// constant, an int and a translated label — nothing user-supplied.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_dropdown_pages(
			array(
				'name'              => Config::DESTINATION_PAGE_OPTION,
				'id'                => Config::DESTINATION_PAGE_OPTION,
				'selected'          => (int) get_option( Config::DESTINATION_PAGE_OPTION, 0 ),
				'show_option_none'  => __( 'Site home page', 'bushido-almost-famous' ),
				'option_none_value' => '0',
				'post_status'       => 'publish',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<p class="description">';
		printf(
			/* translators: %s: the resolved destination URL. */
			esc_html__( 'Where your ads click through to. New campaigns start with %s.', 'bushido-almost-famous' ),
			'<code>' . esc_html( Config::get_default_destination_url() ) . '</code>'
		);
		echo '<br />';
		esc_html_e( 'Pointing ads at this site is what lets Bushido Almost Famous attribute the click — a visit to another site cannot be matched to a WooCommerce sale.', 'bushido-almost-famous' );
		echo '</p>';
	}

	/**
	 * Render the daily budget limit field.
	 *
	 * @return void
	 */
	public function render_daily_budget_limit_field(): void {
		$value = get_option( 'af_daily_budget_limit', 0 );
		printf(
			'<input type="number" id="af_daily_budget_limit" name="af_daily_budget_limit" value="%s" min="0" step="0.01" class="regular-text" placeholder="0.00" />',
			esc_attr( (string) $value )
		);
		echo '<p class="description">' . esc_html__( 'Maximum daily budget allowed per campaign. Set to 0 to disable. Campaigns exceeding 10x this limit require manual review.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Sanitize the daily budget limit (Story 3.4).
	 *
	 * @param mixed $value Raw input value.
	 * @return float Sanitized budget limit.
	 */
	public function sanitize_daily_budget_limit( mixed $value ): float {
		return max( 0.0, (float) $value );
	}


	/**
	 * Sanitize a checkbox value to '1' or ''.
	 *
	 * @param mixed $value Raw input.
	 * @return string '1' when checked, '' otherwise.
	 */
	public function sanitize_checkbox( $value ): string {
		return '1' === (string) $value ? '1' : '';
	}

	/**
	 * Render the Privacy & Consent section description.
	 *
	 * @return void
	 */
	public function render_privacy_section_description(): void {
		echo '<p>' . esc_html__( 'Controls how conversion tracking behaves when no consent-management plugin (CookieBot, Complianz, WP Consent API) is active.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Render the assume-consent checkbox.
	 *
	 * @return void
	 */
	public function render_assume_consent_field(): void {
		$value = get_option( 'af_assume_consent_no_cmp', '' );
		printf(
			'<label><input type="checkbox" id="af_assume_consent_no_cmp" name="af_assume_consent_no_cmp" value="1" %s /> %s</label>',
			checked( '1', $value, false ),
			esc_html__( 'Assume visitor consent when no CMP plugin is active', 'bushido-almost-famous' )
		);
		echo '<p class="description">' . esc_html__( 'Leave unchecked (default) to skip server-side conversion events unless a CMP grants consent. Only enable this if your site does not serve visitors in consent-required regions such as the EU/EEA or UK.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Render cache TTL section description.
	 *
	 * @return void
	 */
	public function render_cache_ttl_section_description(): void {
		echo '<p>' . esc_html__( 'Configure cache TTL (Time To Live) values for different data types. Lower values mean fresher data but more API calls.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Render a standalone number field (not nested in af_settings array).
	 *
	 * @param array $args Field arguments with option_name, default, description.
	 * @return void
	 */
	public function render_standalone_number_field( array $args ): void {
		$option_name = $args['option_name'];
		$default     = $args['default'] ?? 300;
		$description = $args['description'] ?? '';
		$value       = get_option( $option_name, $default );
		printf(
			'<input type="number" id="%s" name="%s" value="%s" min="0" step="1" class="small-text" />',
			esc_attr( $option_name ),
			esc_attr( $option_name ),
			esc_attr( (string) $value )
		);
		if ( ! empty( $description ) ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Render operations diagnostics section description.
	 *
	 * @return void
	 */
	public function render_ops_section_description(): void {
		echo '<p>' . esc_html__( 'Diagnostic tools for troubleshooting API connectivity and viewing webhook activity.', 'bushido-almost-famous' ) . '</p>';
	}

	/**
	 * Render the test connection button and status display.
	 *
	 * @return void
	 */
	public function render_test_connection_field(): void {
		$nonce = wp_create_nonce( 'af_test_connection' );
		?>
		<button
			type="button"
			id="af-test-connection"
			class="button button-secondary"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<?php esc_html_e( 'Test Connection', 'bushido-almost-famous' ); ?>
		</button>
		<span id="af-connection-status" style="margin-left: 10px;"></span>
		<p class="description">
			<?php esc_html_e( 'Validate your API key by sending a test request to the Bushido API.', 'bushido-almost-famous' ); ?>
		</p>
		<script type="text/javascript">
			(function() {
				var btn = document.getElementById('af-test-connection');
				var status = document.getElementById('af-connection-status');
				if (!btn) return;
				btn.addEventListener('click', function() {
					status.textContent = '<?php echo esc_js( __( 'Testing...', 'bushido-almost-famous' ) ); ?>';
					status.style.color = '#666';
					var xhr = new XMLHttpRequest();
					xhr.open('POST', ajaxurl);
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.onload = function() {
						var resp = JSON.parse(xhr.responseText);
						if (resp.success) {
							status.textContent = resp.data.message;
							status.style.color = '#00a32a';
						} else {
							status.textContent = resp.data.message || '<?php echo esc_js( __( 'Connection failed.', 'bushido-almost-famous' ) ); ?>';
							status.style.color = '#d63638';
						}
					};
					xhr.onerror = function() {
						status.textContent = '<?php echo esc_js( __( 'Request failed.', 'bushido-almost-famous' ) ); ?>';
						status.style.color = '#d63638';
					};
					xhr.send('action=af_test_connection&nonce=' + encodeURIComponent('<?php echo esc_js( $nonce ); ?>'));
				});
			})();
		</script>
		<?php
	}

	/**
	 * Render the webhook event log viewer.
	 *
	 * @return void
	 */
	public function render_webhook_log_field(): void {
		$log = get_transient( 'af_webhook_event_log' );

		if ( ! is_array( $log ) || empty( $log ) ) {
			echo '<p>' . esc_html__( 'No webhook events recorded.', 'bushido-almost-famous' ) . '</p>';
			return;
		}

		echo '<div class="af-webhook-log" style="max-height: 400px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #f6f7f7;">';
		echo '<table class="widefat striped" style="margin: 0;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'bushido-almost-famous' ) . '</th>';
		echo '<th>' . esc_html__( 'Event Type', 'bushido-almost-famous' ) . '</th>';
		echo '<th>' . esc_html__( 'Idempotency Key', 'bushido-almost-famous' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'bushido-almost-famous' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$idem_key     = $entry['idempotency_key'] ?? '';
			$idem_display = strlen( $idem_key ) > 12 ? substr( $idem_key, 0, 12 ) . '...' : $idem_key;

			echo '<tr>';
			echo '<td>' . esc_html( $entry['timestamp'] ?? '' ) . '</td>';
			echo '<td><code>' . esc_html( $entry['event_type'] ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $idem_display ) . '</code></td>';
			echo '<td><small>' . esc_html( substr( (string) ( $entry['summary'] ?? '' ), 0, 100 ) ) . '</small></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
		printf(
			/* translators: %d: Number of webhook events shown in the diagnostics log. */
			'<p class="description">' . esc_html__( 'Showing %d most recent webhook events.', 'bushido-almost-famous' ) . '</p>',
			(int) count( $log )
		);
	}

	/**
	 * AJAX handler for testing the API connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'af_test_connection', 'nonce' );

		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'bushido-almost-famous' ) ) );
		}

		$result = $this->client->get_top( '/auth/validate' );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Connection failed: %s', 'bushido-almost-famous' ),
						is_object( $result['error'] ) && property_exists( $result['error'], 'message' )
							? $result['error']->message
							: __( 'Unknown error', 'bushido-almost-famous' )
					),
				)
			);
		}

		wp_send_json_success(
			array( 'message' => __( 'Successfully connected to the Bushido API.', 'bushido-almost-famous' ) )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'af_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		$settings = $this;
		include ALMOST_FAMOUS_PLUGIN_DIR . 'includes/admin/views/settings.php';
	}
}
