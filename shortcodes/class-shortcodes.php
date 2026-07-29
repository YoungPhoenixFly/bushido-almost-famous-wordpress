<?php
/**
 * Shortcode registration.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Config;
use AlmostFamous\Plugin;

/**
 * Shortcode registration.
 *
 * Registers the public-portal shortcode and the classic-editor fallback
 * for the campaign-widget block.
 */
class Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Register all shortcodes.
	 *
	 * (There is deliberately no `[almost-famous]` iframe-embed shortcode or
	 * oEmbed provider — the embed.bushido.is service they pointed at does
	 * not exist.)
	 *
	 * @return void
	 */
	public function register_shortcodes(): void {
		// Classic editor fallback shortcode for the campaign-widget block.
		add_shortcode( 'af-campaign-widget', array( $this, 'render_campaign_widget_shortcode' ) );

		// Public portal shortcode.
		add_shortcode( 'almost-famous-portal', array( $this, 'render_public_portal_shortcode' ) );
	}

	/**
	 * Render the [almost-famous-portal] shortcode.
	 *
	 * Mounts the public React app for ad buying/management.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string       $content Shortcode content (unused).
	 * @return string The rendered HTML.
	 */
	public function render_public_portal_shortcode( $atts = array(), string $content = '' ): string {
		unset( $content );

		$atts = shortcode_atts(
			array(
				'demo' => '0',
			),
			$atts,
			'almost-famous-portal'
		);

		$demo_mode  = rest_sanitize_boolean( $atts['demo'] ) || Config::is_demo_mode_enabled();
		$asset_file = include ALMOST_FAMOUS_PLUGIN_DIR . 'assets/js/public-portal.asset.php';

		wp_enqueue_style(
			'af-public-portal',
			ALMOST_FAMOUS_PLUGIN_URL . 'assets/css/public-portal.css',
			array(),
			$asset_file['version']
		);

		wp_enqueue_script(
			'af-public-portal',
			ALMOST_FAMOUS_PLUGIN_URL . 'assets/js/public-portal.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		// Load JS translations for the portal's `__()` calls, and hand the
		// React app the site locale so Intl formats currency/dates the WP
		// way rather than always en-US.
		wp_set_script_translations( 'af-public-portal', 'bushido-almost-famous' );

		// Capability tiers mirror the REST proxy's permission callbacks:
		// viewing needs af_view_campaigns, managing needs af_manage_campaigns
		// (manage_options always qualifies). Demo mode is self-contained
		// fixtures, so it grants the full UI to anyone for local testing.
		$can_view   = current_user_can( 'af_view_campaigns' ) || current_user_can( 'manage_options' );
		$can_manage = current_user_can( 'af_manage_campaigns' ) || current_user_can( 'manage_options' );

		wp_localize_script(
			'af-public-portal',
			'afPublicPortal',
			array(
				'restBase'           => rest_url( 'almost-famous/v1' ),
				'restNamespace'      => 'almost-famous/v1',
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'demoMode'           => $demo_mode,
				'apiBaseUrl'         => Config::resolve_api_base_url(),
				// BCP-47 site locale so the React app's Intl currency/date
				// formatting matches the site language instead of always en-US.
				'locale'             => str_replace( '_', '-', get_locale() ),
				'hasApiKey'          => ( new Api_Auth() )->has_api_key(),
				'isLoggedIn'         => is_user_logged_in(),
				'canView'            => $can_view,
				'canManage'          => $can_manage,
				'loginUrl'           => wp_login_url( get_permalink() ),
				'adminUrl'           => admin_url( 'admin.php?page=bushido-almost-famous' ),
				// Ads default to a page on THIS site so the plugin's attribution
				// cookie can pick the click up and tie it to a Woo sale. Authors
				// can override it per campaign.
				'defaultDestination' => Config::get_default_destination_url(),
				'endpoints'          => array(
					'campaigns' => '/almost-famous/v1/campaigns',
					'payments'  => '/almost-famous/v1/payments',
				),
				'i18n'               => array(
					'configurationRequired' => __( 'Add a Bushido Almost Famous API key in WordPress admin or use demo mode for local testing.', 'bushido-almost-famous' ),
					'demoModeNotice'        => __( 'Demo mode is enabled. Stripe and backend writes are simulated locally.', 'bushido-almost-famous' ),
					'signInTitle'           => __( 'Sign in to manage campaigns', 'bushido-almost-famous' ),
					'signInBody'            => __( 'The Bushido Almost Famous portal is available to signed-in team members. Sign in to view and manage your campaigns.', 'bushido-almost-famous' ),
					'signInCta'             => __( 'Sign in', 'bushido-almost-famous' ),
					'readOnlyNotice'        => __( 'You have view-only access. Ask an administrator for campaign-management permissions to make changes.', 'bushido-almost-famous' ),
					'loading'               => __( 'Loading Bushido Almost Famous Portal\u2026', 'bushido-almost-famous' ),
				),
			)
		);

		return sprintf(
			'<div id="af-public-portal"><div class="af-loading">%s</div></div>',
			esc_html__( 'Loading Bushido Almost Famous Portal\u2026', 'bushido-almost-famous' )
		);
	}

	/**
	 * Render the [af-campaign-widget] classic editor shortcode.
	 *
	 * Delegates to the same render logic as the campaign-widget Gutenberg block.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function render_campaign_widget_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'id' => '' ),
			$atts,
			'af-campaign-widget'
		);

		$campaign_id = sanitize_text_field( $atts['id'] );

		if ( empty( $campaign_id ) ) {
			return '<p class="af-shortcode-error">'
				. esc_html__( 'Bushido Almost Famous: Please provide a campaign ID.', 'bushido-almost-famous' )
				. '</p>';
		}

		// Delegate to the block render file.
		$attributes = array( 'campaign_id' => $campaign_id );

		return $this->render_block_template( 'campaign-widget', $attributes );
	}

	/**
	 * Render a block template with given attributes, returning the output.
	 *
	 * Includes the block's render.php file in an output buffer with the
	 * $attributes variable available, mirroring block SSR behavior.
	 *
	 * @param string $block_name The block directory name.
	 * @param array  $attributes The block attributes to pass.
	 * @return string The rendered HTML output.
	 */
	private function render_block_template( string $block_name, array $attributes ): string {
		$template = ALMOST_FAMOUS_PLUGIN_DIR . 'blocks/' . $block_name . '/render.php';

		if ( ! file_exists( $template ) ) {
			unset( $attributes );
			return '';
		}

		ob_start();

		// Make $attributes available to the template (same as block SSR).
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Required to match block render signature.
		include $template;

		return ob_get_clean();
	}
}
