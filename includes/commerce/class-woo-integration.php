<?php
/**
 * WooCommerce detection and HPOS-compatible integration.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Consent\Consent_Integration;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * WooCommerce detection and HPOS-compatible integration.
 *
 * Feature-gated: only loads when WooCommerce is active. Declares HPOS
 * (High-Performance Order Storage) compatibility and initializes conversion
 * tracking and attribution sub-modules.
 */
class Woo_Integration {

	/**
	 * Order meta key for the marketing-consent verdict captured at checkout.
	 *
	 * Consent is evaluated in the buyer's own request context (where the
	 * CMP cookie is actually present) and persisted here, so the later
	 * order-completion hook — which usually runs in admin/webhook/cron
	 * context without the buyer's cookies — can honour the real verdict.
	 *
	 * @var string
	 */
	public const CONSENT_META_KEY = 'af_marketing_consent';

	/**
	 * Stored consent verdict: buyer consented to marketing tracking.
	 *
	 * @var string
	 */
	public const CONSENT_GRANTED = 'granted';

	/**
	 * Stored consent verdict: buyer declined marketing tracking.
	 *
	 * @var string
	 */
	public const CONSENT_DENIED = 'denied';

	/**
	 * Script handle for the front-end attribution-capture inline script.
	 *
	 * @var string
	 */
	private const ATTRIBUTION_SCRIPT_HANDLE = 'af-attribution-capture';

	/**
	 * Conversion tracking instance.
	 *
	 * @var Conversion_Tracking|null
	 */
	private ?Conversion_Tracking $conversion_tracking = null;

	/**
	 * Attribution display instance.
	 *
	 * @var Attribution|null
	 */
	private ?Attribution $attribution = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Only load if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Declare HPOS compatibility.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		// Initialize sub-modules once WooCommerce is fully loaded. WooCommerce
		// fires `woocommerce_loaded` from `plugins_loaded` at priority -1, and
		// this plugin boots at the default priority 10 — so by the time we get
		// here the action has almost always already fired and a plain
		// add_action() would never run. Missing it silently disables the whole
		// commerce half: no conversion is ever sent and the order attribution
		// meta box never renders.
		if ( did_action( 'woocommerce_loaded' ) ) {
			$this->init_modules();
		} else {
			add_action( 'woocommerce_loaded', array( $this, 'init_modules' ) );
		}

		// Register WooCommerce hooks for order processing.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ) );
		add_action( 'woocommerce_new_order', array( $this, 'capture_attribution_data' ) );

		// Front-end capture of ad-click / UTM params into the af_attribution cookie.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_attribution_capture_script' ) );
	}

	/**
	 * Declare HPOS (Custom Order Tables) compatibility.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', ALMOST_FAMOUS_PLUGIN_BASENAME );
		}
	}

	/**
	 * Initialize conversion tracking and attribution modules.
	 *
	 * @return void
	 */
	public function init_modules(): void {
		$this->conversion_tracking = new Conversion_Tracking();
		$this->conversion_tracking->register();

		$this->attribution = new Attribution();
		$this->attribution->register();

		/**
		 * Fires after WooCommerce integration modules are initialized.
		 *
		 * @param Woo_Integration $integration The integration instance.
		 */
		do_action( 'almost_famous/woo/initialized', $this );
	}

	/**
	 * Handle order creation — capture consent verdict and attribution
	 * from session/cookie.
	 *
	 * @param \WC_Order $order The newly created order.
	 * @return void
	 */
	public function on_order_created( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->store_consent_meta( $order );
		$this->store_attribution_meta( $order );
	}

	/**
	 * Capture consent and attribution data for a new order by ID.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	public function capture_attribution_data( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->store_consent_meta( $order );

		// Only store if not already present (avoid double-fire).
		$existing = $order->get_meta( 'af_attribution' );

		if ( ! empty( $existing ) ) {
			return;
		}

		$this->store_attribution_meta( $order );
	}

	/**
	 * Persist the buyer's marketing-consent verdict as order meta.
	 *
	 * Order creation runs in the buyer's own request context, so the CMP
	 * (CookieBot / Complianz / WP Consent API) cookie is present and the
	 * verdict is real. Conversion sending later happens in admin/webhook/
	 * cron context where that cookie does not exist — it reads this meta
	 * instead of re-evaluating consent against the wrong cookies.
	 *
	 * The first captured verdict wins; double-fire from the two order
	 * hooks (or a later admin re-save) never overwrites it.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return void
	 */
	private function store_consent_meta( \WC_Order $order ): void {
		$existing = (string) $order->get_meta( self::CONSENT_META_KEY );

		if ( '' !== $existing ) {
			return;
		}

		if ( ! class_exists( Consent_Integration::class ) ) {
			return;
		}

		$granted = ( new Consent_Integration() )->has_consent();

		$order->update_meta_data(
			self::CONSENT_META_KEY,
			$granted ? self::CONSENT_GRANTED : self::CONSENT_DENIED
		);
		$order->save();
	}

	/**
	 * Store attribution metadata on an order.
	 *
	 * Reads attribution data from the request cookie or query parameters
	 * set by the tracking pixel / UTM parameters.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return void
	 */
	private function store_attribution_meta( \WC_Order $order ): void {
		$attribution = array();

		// Read from cookie set by Bushido Almost Famous tracking.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cookie_data = isset( $_COOKIE['af_attribution'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['af_attribution'] ) ) : '';

		if ( ! empty( $cookie_data ) ) {
			$decoded = json_decode( $cookie_data, true );

			if ( is_array( $decoded ) ) {
				$attribution = array(
					'platform'    => sanitize_text_field( $decoded['platform'] ?? '' ),
					'campaign_id' => sanitize_text_field( $decoded['campaign_id'] ?? '' ),
					'campaign'    => sanitize_text_field( $decoded['campaign'] ?? '' ),
					'source'      => sanitize_text_field( $decoded['source'] ?? '' ),
					'medium'      => sanitize_text_field( $decoded['medium'] ?? '' ),
					'click_id'    => sanitize_text_field( $decoded['click_id'] ?? '' ),
					'timestamp'   => gmdate( 'c' ),
				);
			}
		}

		// Fall back to UTM parameters if no cookie.
		if ( empty( $attribution ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$utm_source = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$utm_medium = isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$utm_campaign = isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '';

			if ( ! empty( $utm_source ) ) {
				$attribution = array(
					'platform'    => '',
					'campaign_id' => '',
					'campaign'    => $utm_campaign,
					'source'      => $utm_source,
					'medium'      => $utm_medium,
					'click_id'    => '',
					'timestamp'   => gmdate( 'c' ),
				);
			}
		}

		if ( ! empty( $attribution ) ) {
			$order->update_meta_data( 'af_attribution', $attribution );
			$order->save();
		}
	}

	/**
	 * Enqueue the front-end attribution-capture inline script.
	 *
	 * `store_attribution_meta()` reads the `af_attribution` first-party
	 * cookie at checkout, but nothing wrote it historically — attribution
	 * only survived when UTM params reached the checkout request itself.
	 * This tiny dependency-free script writes that cookie on any front-end
	 * page load that carries marketing params (utm_*, af_campaign,
	 * af_platform, fbclid/ttclid/gclid), so the click landing page is
	 * enough to attribute the eventual order.
	 *
	 * Consent-gated: this runs during the visitor's own request, where the
	 * CMP cookie is present, so `has_consent()` reflects the real verdict.
	 * Without consent no cookie is written at all.
	 *
	 * @return void
	 */
	public function enqueue_attribution_capture_script(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! class_exists( Consent_Integration::class ) || ! ( new Consent_Integration() )->has_consent() ) {
			return;
		}

		// Inline-only handle: no src, the payload rides wp_add_inline_script().
		wp_register_script( self::ATTRIBUTION_SCRIPT_HANDLE, '', array(), ALMOST_FAMOUS_VERSION, true );
		wp_enqueue_script( self::ATTRIBUTION_SCRIPT_HANDLE );
		wp_add_inline_script( self::ATTRIBUTION_SCRIPT_HANDLE, $this->attribution_capture_js() );
	}

	/**
	 * Build the attribution-capture inline script.
	 *
	 * Writes a first-party `af_attribution` cookie whose JSON shape mirrors
	 * exactly what {@see Woo_Integration::store_attribution_meta()} parses:
	 * platform, campaign_id, campaign, source, medium, click_id. Platform is
	 * inferred from the click-id param (fbclid/ttclid/gclid) or utm_source
	 * unless `af_platform` names it explicitly; campaign_id prefers
	 * `af_campaign` and falls back to `utm_campaign`.
	 *
	 * The script is static — no server-side values are interpolated.
	 *
	 * @return string JavaScript source.
	 */
	private function attribution_capture_js(): string {
		return '( function() {
	"use strict";
	if ( "undefined" === typeof URLSearchParams ) { return; }
	var q = new URLSearchParams( window.location.search );
	var clickPlatforms = { fbclid: "meta", ttclid: "tiktok", gclid: "google" };
	var sourcePlatforms = { facebook: "meta", instagram: "meta", meta: "meta", tiktok: "tiktok", google: "google", youtube: "google", spotify: "spotify" };
	var platform = q.get( "af_platform" ) || "";
	var clickId = "";
	Object.keys( clickPlatforms ).some( function( key ) {
		var value = q.get( key );
		if ( ! value ) { return false; }
		clickId = value;
		if ( ! platform ) { platform = clickPlatforms[ key ]; }
		return true;
	} );
	var source = q.get( "utm_source" ) || "";
	var medium = q.get( "utm_medium" ) || "";
	var campaign = q.get( "utm_campaign" ) || "";
	var campaignId = q.get( "af_campaign" ) || "";
	if ( ! platform && source && sourcePlatforms[ source.toLowerCase() ] ) {
		platform = sourcePlatforms[ source.toLowerCase() ];
	}
	if ( ! platform && ! clickId && ! source && ! medium && ! campaign && ! campaignId ) { return; }
	var payload = {
		platform: platform,
		campaign_id: campaignId || campaign,
		campaign: campaign,
		source: source,
		medium: medium,
		click_id: clickId
	};
	var cookie = "af_attribution=" + encodeURIComponent( JSON.stringify( payload ) ) +
		"; path=/; max-age=2592000; SameSite=Lax";
	if ( "https:" === window.location.protocol ) { cookie += "; Secure"; }
	document.cookie = cookie;
} )();';
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool True if WooCommerce is available.
	 */
	public static function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get the conversion tracking instance.
	 *
	 * @return Conversion_Tracking|null
	 */
	public function conversion_tracking(): ?Conversion_Tracking {
		return $this->conversion_tracking;
	}

	/**
	 * Get the attribution instance.
	 *
	 * @return Attribution|null
	 */
	public function attribution(): ?Attribution {
		return $this->attribution;
	}
}
