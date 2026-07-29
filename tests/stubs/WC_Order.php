<?php
/**
 * Shared WC_Order stand-in for unit tests.
 *
 * The plugin guards every WooCommerce code path with `instanceof \WC_Order`,
 * so tests only need a class that satisfies that contract plus the small
 * read/write surface we touch: id, billing, currency, items, meta, and the
 * checkout URL helper. Keep this header thin so multiple test files can
 * require it without colliding on property/method signatures.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {

		public int $id = 0;
		public string $billing_email = '';
		public float $total = 0.0;
		public string $currency = 'USD';

		/**
		 * @var array<string, mixed>
		 */
		public array $meta = array();

		/**
		 * @var array<int, object>
		 */
		public array $items = array();

		public bool $saved = false;

		public function get_id(): int {
			return $this->id;
		}

		public function get_billing_email(): string {
			return $this->billing_email;
		}

		public function get_total(): string {
			return (string) $this->total;
		}

		public function get_currency(): string {
			return $this->currency;
		}

		public function get_items(): array {
			return $this->items;
		}

		public function get_checkout_order_received_url(): string {
			return 'https://example.test/checkout/order-received/' . $this->id;
		}

		public function get_meta( string $key, bool $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function save(): int {
			$this->saved = true;
			return $this->id ?: 1;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}
