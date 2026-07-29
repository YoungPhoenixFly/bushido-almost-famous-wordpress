<?php
/**
 * GDPR consent plugin integration (CookieBot, Complianz).
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Client;

/**
 * GDPR consent plugin integration (CookieBot, Complianz).
 *
 * Integrates with CookieBot, Complianz, and the WP Consent API to
 * respect user consent preferences. Also registers WordPress Privacy
 * API data exporter and eraser for GDPR compliance.
 */
class Consent_Integration {

	/**
	 * Exporter friendly name.
	 *
	 * @var string
	 */
	private const EXPORTER_NAME = 'Bushido Almost Famous';

	/**
	 * Eraser friendly name.
	 *
	 * @var string
	 */
	private const ERASER_NAME = 'Bushido Almost Famous';

	/**
	 * Orders processed per privacy-request page.
	 *
	 * @var int
	 */
	private const PRIVACY_PAGE_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * Privacy processing is local-only (WooCommerce order meta), so no API
	 * client is needed; the parameter is kept optional for back-compat with
	 * existing call sites.
	 *
	 * @param Api_Client|null $client Unused; retained for signature compatibility.
	 */
	public function __construct( ?Api_Client $client = null ) {
		unset( $client );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_data_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_data_eraser' ) );
	}

	/**
	 * Check if the user has given consent for marketing tracking.
	 *
	 * Checks against CookieBot, Complianz, and the WP Consent API.
	 * If no consent plugin is active, returns true (assumed consent).
	 *
	 * @return bool True if consent has been given or no consent plugin is active.
	 */
	public function has_consent(): bool {
		// Check CookieBot.
		if ( $this->has_cookiebot() ) {
			return $this->get_cookiebot_consent();
		}

		// Check Complianz.
		if ( $this->has_complianz() ) {
			return $this->get_complianz_consent();
		}

		// Check WP Consent API.
		if ( $this->has_wp_consent_api() ) {
			return $this->get_wp_consent_api_consent();
		}

		/**
		 * Default consent posture when no CMP plugin is active.
		 *
		 * Defaults to false so EU visitors (and anyone else) are not tracked
		 * implicitly. Sites that operate outside consent-required regions can
		 * opt back into "assume consent" via the Settings → Privacy checkbox
		 * (the `af_assume_consent_no_cmp` option) or with
		 * add_filter( 'almost_famous_default_consent', '__return_true' );
		 *
		 * @since 1.0.0
		 * @param bool $consent Default consent value. False unless the site opted in.
		 */
		return (bool) apply_filters(
			'almost_famous_default_consent',
			'1' === get_option( 'af_assume_consent_no_cmp', '' )
		);
	}

	/**
	 * Check if CookieBot plugin is active.
	 *
	 * @return bool True if CookieBot is detected.
	 */
	private function has_cookiebot(): bool {
		return class_exists( 'Cookiebot_WP' );
	}

	/**
	 * Get consent status from CookieBot.
	 *
	 * CookieBot stores consent in the CookieConsent cookie as a JSON object.
	 *
	 * @return bool True if marketing consent is given.
	 */
	private function get_cookiebot_consent(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_COOKIE['CookieConsent'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$consent_value = sanitize_text_field( wp_unslash( $_COOKIE['CookieConsent'] ) );

		// CookieBot may store as URL-encoded JSON.
		$decoded = json_decode( urldecode( $consent_value ), true );

		if ( is_array( $decoded ) ) {
			return true === ( $decoded['marketing'] ?? false );
		}

		// Opaque legacy values do not prove category-specific marketing
		// consent. Fail closed instead of treating any non-zero value as all
		// categories granted.
		return false;
	}

	/**
	 * Check if Complianz plugin is active.
	 *
	 * @return bool True if Complianz is detected.
	 */
	private function has_complianz(): bool {
		return function_exists( 'cmplz_uses_consent' );
	}

	/**
	 * Get consent status from Complianz.
	 *
	 * @return bool True if consent is given via Complianz.
	 */
	private function get_complianz_consent(): bool {
		// Check the Complianz cookie.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_COOKIE['cmplz_marketing'] ) ) {
			return 'allow' === sanitize_text_field( wp_unslash( $_COOKIE['cmplz_marketing'] ) );
		}

		return false;
	}

	/**
	 * Check if the WP Consent API is available.
	 *
	 * @return bool True if WP Consent API functions exist.
	 */
	private function has_wp_consent_api(): bool {
		return function_exists( 'wp_get_consent_type' );
	}

	/**
	 * Get consent status from the WP Consent API.
	 *
	 * @return bool True if consent is given via WP Consent API.
	 */
	private function get_wp_consent_api_consent(): bool {
		// A configured Consent API without an explicit category grant does not
		// authorize ad conversion tracking, regardless of regional consent
		// mode or whether the integration calls that mode opt-in/opt-out.
		if ( function_exists( 'wp_has_consent' ) ) {
			return true === wp_has_consent( 'marketing' );
		}

		return false;
	}

	/**
	 * Register the personal data exporter with the WordPress Privacy API.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array Updated exporters.
	 */
	public function register_data_exporter( array $exporters ): array {
		$exporters['bushido-almost-famous'] = array(
			'exporter_friendly_name' => self::EXPORTER_NAME,
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the personal data eraser with the WordPress Privacy API.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array Updated erasers.
	 */
	public function register_data_eraser( array $erasers ): array {
		$erasers['bushido-almost-famous'] = array(
			'eraser_friendly_name' => self::ERASER_NAME,
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export personal data for a given email address.
	 *
	 * Called by WordPress Privacy API during a data export request. The
	 * plugin's only per-visitor personal data is the `af_attribution`
	 * click/UTM meta captured on WooCommerce orders — export that. (The
	 * Bushido backend only ever receives SHA-256-hashed emails for
	 * conversion matching and exposes no per-email privacy API; requests
	 * about backend data go through Bushido's own privacy channels.)
	 *
	 * @param string $email_address The user's email address.
	 * @param int    $page          Page number for paginated results.
	 * @return array{data: array, done: bool} Export data.
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$export_items = array();
		$orders       = $this->get_attributed_orders( $email_address, $page );

		foreach ( $orders as $order ) {
			$attribution = $order->get_meta( 'af_attribution' );

			if ( empty( $attribution ) || ! is_array( $attribution ) ) {
				continue;
			}

			$data = array();
			foreach ( $attribution as $key => $value ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$data[] = array(
						'name'  => sanitize_text_field( (string) $key ),
						'value' => sanitize_text_field( (string) $value ),
					);
				}
			}

			if ( empty( $data ) ) {
				continue;
			}

			$export_items[] = array(
				'group_id'          => 'af-attribution',
				'group_label'       => __( 'Bushido Almost Famous Ad Attribution', 'bushido-almost-famous' ),
				'group_description' => __( 'Ad click and campaign attribution captured on your orders by Bushido Almost Famous.', 'bushido-almost-famous' ),
				'item_id'           => 'af-attribution-' . $order->get_id(),
				'data'              => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $orders ) < self::PRIVACY_PAGE_SIZE,
		);
	}

	/**
	 * Erase personal data for a given email address.
	 *
	 * Called by WordPress Privacy API during a data erasure request.
	 * Removes the `af_attribution` meta from the requester's orders.
	 *
	 * @param string $email_address The user's email address.
	 * @param int    $page          Page number for paginated erasure.
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool} Erasure result.
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		$items_removed = 0;

		$orders = $this->get_attributed_orders( $email_address, $page );

		foreach ( $orders as $order ) {
			$attribution = $order->get_meta( 'af_attribution' );

			if ( empty( $attribution ) ) {
				continue;
			}

			$order->delete_meta_data( 'af_attribution' );
			$order->save();
			++$items_removed;
		}

		$messages = array(
			__( 'Bushido Almost Famous shares only SHA-256-hashed identifiers with the Bushido ad platform; for backend data requests see the Bushido privacy policy at https://bushido.is/privacy.', 'bushido-almost-famous' ),
		);

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PRIVACY_PAGE_SIZE,
		);
	}

	/**
	 * Fetch the requester's WooCommerce orders for privacy processing.
	 *
	 * @param string $email_address Requester email.
	 * @param int    $page          1-based page number.
	 * @return array<int, object> WC_Order-like objects; empty when WooCommerce is inactive.
	 */
	private function get_attributed_orders( string $email_address, int $page ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email_address,
				'limit'         => self::PRIVACY_PAGE_SIZE,
				'paged'         => max( 1, $page ),
			)
		);

		return is_array( $orders ) ? $orders : array();
	}
}
