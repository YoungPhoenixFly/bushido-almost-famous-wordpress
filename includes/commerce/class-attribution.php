<?php
/**
 * Cross-platform conversion attribution display on WooCommerce orders.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Cross-platform conversion attribution display on WooCommerce orders.
 *
 * Adds a meta box to the WooCommerce order detail page showing the
 * originating platform, campaign name, and full attribution chain.
 * HPOS compatible using OrderUtil for order type detection.
 */
class Attribution {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_attribution_meta_box' ) );
	}

	/**
	 * Add the attribution meta box to the order edit screen.
	 *
	 * Supports both HPOS (woocommerce_page_wc-orders) and legacy
	 * (shop_order post type) order screens.
	 *
	 * @return void
	 */
	public function add_attribution_meta_box(): void {
		$screen = $this->get_order_screen_id();

		add_meta_box(
			'af-attribution',
			__( 'Bushido Almost Famous Attribution', 'bushido-almost-famous' ),
			array( $this, 'render_attribution_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the attribution meta box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order The post or order object.
	 * @return void
	 */
	public function render_attribution_meta_box( $post_or_order ): void {
		$order = $this->get_order_from_context( $post_or_order );

		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'bushido-almost-famous' ) . '</p>';
			return;
		}

		$attribution = $order->get_meta( 'af_attribution' );

		if ( empty( $attribution ) || ! is_array( $attribution ) ) {
			echo '<p>' . esc_html__( 'No attribution data available for this order.', 'bushido-almost-famous' ) . '</p>';
			return;
		}

		echo '<div class="af-attribution-details">';

		// Originating platform.
		if ( ! empty( $attribution['platform'] ) ) {
			echo '<p><strong>' . esc_html__( 'Platform:', 'bushido-almost-famous' ) . '</strong> ';
			echo esc_html( $attribution['platform'] ) . '</p>';
		}

		// Campaign name.
		if ( ! empty( $attribution['campaign'] ) ) {
			echo '<p><strong>' . esc_html__( 'Campaign:', 'bushido-almost-famous' ) . '</strong> ';
			echo esc_html( $attribution['campaign'] ) . '</p>';
		}

		// Campaign ID.
		if ( ! empty( $attribution['campaign_id'] ) ) {
			echo '<p><strong>' . esc_html__( 'Campaign ID:', 'bushido-almost-famous' ) . '</strong> ';
			echo '<code>' . esc_html( $attribution['campaign_id'] ) . '</code></p>';
		}

		// Source / Medium.
		if ( ! empty( $attribution['source'] ) || ! empty( $attribution['medium'] ) ) {
			echo '<p><strong>' . esc_html__( 'Source / Medium:', 'bushido-almost-famous' ) . '</strong> ';
			echo esc_html( ( $attribution['source'] ?? '' ) . ' / ' . ( $attribution['medium'] ?? '' ) );
			echo '</p>';
		}

		// Click ID.
		if ( ! empty( $attribution['click_id'] ) ) {
			echo '<p><strong>' . esc_html__( 'Click ID:', 'bushido-almost-famous' ) . '</strong> ';
			echo '<code>' . esc_html( $attribution['click_id'] ) . '</code></p>';
		}

		// Attribution chain.
		if ( ! empty( $attribution['chain'] ) && is_array( $attribution['chain'] ) ) {
			echo '<hr />';
			echo '<p><strong>' . esc_html__( 'Attribution Chain:', 'bushido-almost-famous' ) . '</strong></p>';
			echo '<ol class="af-attribution-chain">';

			foreach ( $attribution['chain'] as $touchpoint ) {
				if ( ! is_array( $touchpoint ) ) {
					continue;
				}

				echo '<li>';
				echo esc_html( ( $touchpoint['platform'] ?? '' ) . ' — ' . ( $touchpoint['campaign'] ?? '' ) );

				if ( ! empty( $touchpoint['timestamp'] ) ) {
					echo ' <small>(' . esc_html( $touchpoint['timestamp'] ) . ')</small>';
				}

				echo '</li>';
			}

			echo '</ol>';
		}

		// Timestamp.
		if ( ! empty( $attribution['timestamp'] ) ) {
			echo '<hr />';
			echo '<p class="description">';
			echo esc_html(
				sprintf(
					/* translators: %s: attribution timestamp */
					__( 'Attributed at: %s', 'bushido-almost-famous' ),
					$attribution['timestamp']
				)
			);
			echo '</p>';
		}

		// Conversion event ID if tracked.
		$event_id = $order->get_meta( 'af_conversion_event_id' );

		if ( ! empty( $event_id ) ) {
			echo '<p class="description">';
			echo esc_html__( 'Conversion Event ID:', 'bushido-almost-famous' ) . ' ';
			echo '<code>' . esc_html( $event_id ) . '</code>';
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Get the appropriate screen ID for the order edit page.
	 *
	 * Returns the HPOS screen ID if Custom Order Tables are active,
	 * otherwise falls back to the legacy shop_order post type.
	 *
	 * @return string The screen ID for meta box registration.
	 */
	private function get_order_screen_id(): string {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return 'woocommerce_page_wc-orders';
		}

		return 'shop_order';
	}

	/**
	 * Get the WC_Order object from the meta box context.
	 *
	 * Handles both HPOS (where the order object is passed directly)
	 * and legacy (where a WP_Post is passed).
	 *
	 * @param \WP_Post|\WC_Order $post_or_order The post or order from the meta box.
	 * @return \WC_Order|null The order object, or null if not resolvable.
	 */
	private function get_order_from_context( $post_or_order ): ?\WC_Order {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}

		if ( $post_or_order instanceof \WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );

			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		return null;
	}
}
