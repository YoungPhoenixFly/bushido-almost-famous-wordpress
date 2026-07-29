<?php
/**
 * Minimal WP_REST_Request / WP_REST_Response stubs for unit tests.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {

		/**
		 * @var array<string,mixed>
		 */
		private array $params = array();

		/**
		 * @var array<string,string>
		 */
		private array $headers = array();

		private string $method;
		private string $route;
		private string $body = '';

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = strtoupper( $method );
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function set_method( string $method ): void {
			$this->method = strtoupper( $method );
		}

		public function get_route(): string {
			return $this->route;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function get_params(): array {
			return $this->params;
		}

		public function set_query_params( array $params ): void {
			$this->params = array_merge( $this->params, $params );
		}

		public function set_body_params( array $params ): void {
			$this->params = array_merge( $this->params, $params );
		}

		public function get_json_params(): array {
			return $this->params;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function get_headers(): array {
			return $this->headers;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/**
		 * @var array<string,string>
		 */
		private array $headers;

		public function __construct(
			public mixed $data = null,
			public int $status = 200,
			array $headers = array()
		) {
			$this->headers = $headers;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function set_data( mixed $data ): void {
			$this->data = $data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function set_status( int $status ): void {
			$this->status = $status;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}
