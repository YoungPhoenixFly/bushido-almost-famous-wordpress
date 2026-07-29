<?php
/**
 * Shared WordPress function stubs for unit tests.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

global $af_test_options, $af_test_transients, $af_test_user_meta,
	$af_test_filters, $af_test_actions, $af_test_rest_routes,
	$af_test_http_responses, $af_test_http_requests,
	$af_test_current_user_caps, $af_test_post_meta,
	$af_test_scheduled_events, $af_test_redirects,
	$af_test_option_update_failures;

$af_test_options              = array();
$af_test_transients           = array();
$af_test_user_meta            = array();
$af_test_filters              = array();
$af_test_actions              = array();
$af_test_rest_routes          = array();
$af_test_http_responses       = array();
$af_test_http_requests        = array();
$af_test_current_user_caps    = array( 'manage_options' => true );
$af_test_post_meta            = array();
$af_test_scheduled_events     = array();
$af_test_redirects            = array();
$af_test_option_update_failures = array();
$af_test_posts                = array();

/**
 * Reset all stub state between tests.
 */
function af_test_reset(): void {
	global $af_test_options, $af_test_transients, $af_test_user_meta,
		$af_test_filters, $af_test_actions, $af_test_rest_routes,
		$af_test_http_responses, $af_test_http_requests,
		$af_test_current_user_caps, $af_test_post_meta,
		$af_test_scheduled_events, $af_test_redirects,
	$af_test_site_options, $af_test_option_update_failures;
	global $af_test_site_option_update_failures;

	$af_test_options          = array();
	$af_test_site_options     = array();
	$af_test_transients       = array();
	$af_test_user_meta        = array();
	$af_test_filters          = array();
	$af_test_actions          = array();
	$af_test_rest_routes      = array();
	$af_test_http_responses   = array();
	$af_test_http_requests    = array();
	$af_test_post_meta        = array();
	$af_test_scheduled_events = array();
	$af_test_redirects        = array();
	$af_test_option_update_failures = array();
	$af_test_site_option_update_failures = array();
	$af_test_current_user_caps = array( 'manage_options' => true );

	global $af_test_menu_pages, $af_test_is_multisite,
		$af_test_shortcodes, $af_test_oembed_providers,
		$af_test_users_by, $af_test_roles, $af_test_posts,
		$af_test_site_ids, $af_test_blog_switches, $af_test_current_blog_id,
		$af_test_blog_stack;
	$af_test_menu_pages       = array();
	$af_test_is_multisite     = false;
	$af_test_shortcodes       = array();
	$af_test_oembed_providers = array();
	$af_test_users_by         = array();
	$af_test_roles            = array();
	$af_test_posts            = array();
	$af_test_site_ids         = array( 1 );
	$af_test_blog_switches    = array();
	$af_test_current_blog_id  = 1;
	$af_test_blog_stack       = array();

	global $af_test_enqueued_styles, $af_test_inline_scripts;
	$af_test_enqueued_styles = array();
	$af_test_inline_scripts  = array();
}

/**
 * Insert a post into the in-memory store and return its WP_Post.
 *
 * @param array<string,mixed> $data Post fields (post_content, post_status, etc).
 * @return WP_Post
 */
function af_test_add_post( array $data ): WP_Post {
	global $af_test_posts;
	$id                     = count( $af_test_posts ) + 1;
	$data['ID']             = $id;
	$post                   = new WP_Post( $data );
	$af_test_posts[ $id ]   = $post;
	return $post;
}

/**
 * Override capability set for the current "user".
 *
 * @param array<string,bool> $caps Capability map.
 */
function af_test_set_caps( array $caps ): void {
	global $af_test_current_user_caps;
	$af_test_current_user_caps = $caps;
}

/**
 * Register a canned HTTP response keyed by a URL substring.
 *
 * @param string $url_pattern Substring matched against the outgoing URL.
 * @param array  $response    wp_remote_request-shaped response array, or WP_Error.
 */
function af_test_register_http_response( string $url_pattern, mixed $response ): void {
	global $af_test_http_responses;
	$af_test_http_responses[ $url_pattern ] = $response;
}

/**
 * Return all HTTP requests captured by wp_remote_request().
 *
 * @return array<int,array{url:string,args:array}>
 */
function af_test_get_http_requests(): array {
	global $af_test_http_requests;
	return $af_test_http_requests;
}

/**
 * Return all REST routes registered via register_rest_route().
 *
 * @return array<string,array>
 */
function af_test_get_rest_routes(): array {
	global $af_test_rest_routes;
	return $af_test_rest_routes;
}

/**
 * Make the next update_option() calls for one key fail without writing.
 *
 * @param string $key   Option key.
 * @param int    $count Number of failures to inject.
 */
function af_test_fail_option_updates( string $key, int $count = 1 ): void {
	global $af_test_option_update_failures;
	$af_test_option_update_failures[ $key ] = $count;
}

/**
 * Make the next update_site_option() calls for one key fail without writing.
 *
 * @param string $key   Network option key.
 * @param int    $count Number of failures to inject.
 */
function af_test_fail_site_option_updates( string $key, int $count = 1 ): void {
	global $af_test_site_option_update_failures;
	$af_test_site_option_update_failures[ $key ] = $count;
}

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		global $af_test_options;
		return array_key_exists( $key, $af_test_options ) ? $af_test_options[ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		global $af_test_options, $af_test_option_update_failures;
		if ( ( $af_test_option_update_failures[ $key ] ?? 0 ) > 0 ) {
			--$af_test_option_update_failures[ $key ];
			return false;
		}
		$af_test_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $key, mixed $value = '', string $deprecated = '', string $autoload = 'yes' ): bool {
		global $af_test_options;
		if ( array_key_exists( $key, $af_test_options ) ) {
			return false;
		}
		$af_test_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		global $af_test_options;
		unset( $af_test_options[ $key ] );
		return true;
	}
}

// Network (site) options live in their own store — on real multisite they
// are network-wide while get_option()/update_option() are per-site, and
// several tests assert exactly that separation.
if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $key, mixed $default = false ): mixed {
		global $af_test_site_options;
		return $af_test_site_options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( string $key, mixed $value ): bool {
		global $af_test_site_options, $af_test_site_option_update_failures;
		if ( ( $af_test_site_option_update_failures[ $key ] ?? 0 ) > 0 ) {
			--$af_test_site_option_update_failures[ $key ];
			return false;
		}
		$af_test_site_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( string $key ): bool {
		global $af_test_site_options;
		unset( $af_test_site_options[ $key ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Transients
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		global $af_test_transients;
		return array_key_exists( $key, $af_test_transients ) ? $af_test_transients[ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		global $af_test_transients;
		$af_test_transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		global $af_test_transients;
		if ( array_key_exists( $key, $af_test_transients ) ) {
			unset( $af_test_transients[ $key ] );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( string $key ): mixed {
		return get_transient( $key );
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		return set_transient( $key, $value, $ttl );
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( string $key ): bool {
		return delete_transient( $key );
	}
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		global $af_test_filters;
		if ( isset( $af_test_filters[ $hook ] ) ) {
			foreach ( $af_test_filters[ $hook ] as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		global $af_test_filters;
		$af_test_filters[ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		global $af_test_filters;
		if ( ! isset( $af_test_filters[ $hook ] ) ) {
			return false;
		}
		foreach ( $af_test_filters[ $hook ] as $idx => $cb ) {
			if ( $cb === $callback ) {
				unset( $af_test_filters[ $hook ][ $idx ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		global $af_test_actions;
		$af_test_actions[ $hook ] ??= array();
		$af_test_actions[ $hook ][] = $args;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		global $af_test_actions;
		return isset( $af_test_actions[ $hook ] ) ? count( $af_test_actions[ $hook ] ) : 0;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook ): bool {
		global $af_test_filters;
		return ! empty( $af_test_filters[ $hook ] );
	}
}

// ---------------------------------------------------------------------------
// Capabilities
// ---------------------------------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, mixed ...$args ): bool {
		global $af_test_current_user_caps;
		return ! empty( $af_test_current_user_caps[ $cap ] );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $af_test_current_user_caps;
		return ! empty( $af_test_current_user_caps );
	}
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			public string $code = '',
			public string $message = '',
			public mixed $data = null
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ): array|WP_Error {
		global $af_test_http_responses, $af_test_http_requests;
		$af_test_http_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		foreach ( $af_test_http_responses as $pattern => $response ) {
			if ( str_contains( $url, $pattern ) ) {
				if ( $response instanceof WP_Error ) {
					return $response;
				}
				return $response;
			}
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
			'headers'  => array(),
		);
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
		$args['method'] = 'GET';
		return wp_remote_request( $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ): array|WP_Error {
		$args['method'] = 'POST';
		return wp_remote_request( $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( mixed $response ): int {
		if ( ! is_array( $response ) ) {
			return 0;
		}
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_message' ) ) {
	function wp_remote_retrieve_response_message( mixed $response ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		return (string) ( $response['response']['message'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( mixed $response ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( mixed $response ): array {
		if ( ! is_array( $response ) ) {
			return array();
		}
		return (array) ( $response['headers'] ?? array() );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( mixed $response, string $header ): string {
		$headers = wp_remote_retrieve_headers( $response );
		return (string) ( $headers[ strtolower( $header ) ] ?? $headers[ $header ] ?? '' );
	}
}

// ---------------------------------------------------------------------------
// REST
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'POST, PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
		global $af_test_rest_routes;
		$af_test_rest_routes[ $namespace . $route ] = $args;
		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( mixed $response ): mixed {
		if ( $response instanceof WP_REST_Response ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( '__return_null' ) ) {
	function __return_null(): mixed {
		return null;
	}
}

// ---------------------------------------------------------------------------
// Sanitize / Escape passthroughs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string {
		return is_scalar( $text ) ? htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) : '';
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, ?string $domain = null ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, ?string $domain = null ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string {
		return is_scalar( $text ) ? htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) : '';
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, ?string $domain = null ): string {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $url ): string {
		return is_scalar( $url ) ? (string) $url : '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( mixed $url ): string {
		return is_scalar( $url ) ? (string) $url : '';
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( mixed $text ): string {
		return is_scalar( $text ) ? (string) $text : '';
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( mixed $text ): string {
		return is_scalar( $text ) ? (string) $text : '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		return preg_replace( '/[^a-z0-9\-]/', '-', $value ) ?? '';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( mixed $value ): string {
		return is_scalar( $value ) ? strip_tags( (string) $value ) : '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( mixed $args, array $defaults = array() ): array {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			parse_str( (string) $args, $args );
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( mixed ...$args ): string {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = $_SERVER['REQUEST_URI'] ?? '';
		} elseif ( count( $args ) === 2 && is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = (string) $args[1];
		} elseif ( count( $args ) >= 3 ) {
			$params = array( (string) $args[0] => $args[1] );
			$url    = (string) $args[2];
		} else {
			return '';
		}

		$parts = parse_url( $url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$query = array_merge( $query, $params );

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = $parts['host'] ?? '';
		$path   = $parts['path'] ?? '';
		$qs     = http_build_query( $query );

		return $scheme . $host . $path . ( $qs !== '' ? '?' . $qs : '' );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( mixed $key, string $url = '' ): string {
		$parts = parse_url( $url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		foreach ( (array) $key as $k ) {
			unset( $query[ $k ] );
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = $parts['host'] ?? '';
		$path   = $parts['path'] ?? '';
		$qs     = http_build_query( $query );
		return $scheme . $host . $path . ( $qs !== '' ? '?' . $qs : '' );
	}
}

// ---------------------------------------------------------------------------
// URLs and paths
// ---------------------------------------------------------------------------

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test' . ( $path === '' ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '' ): string {
		return 'https://example.test' . ( $path === '' ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://example.test/wp-content/plugins/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return true;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

global $af_test_is_multisite;
$af_test_is_multisite = false;

if ( ! class_exists( 'WP_Site' ) ) {
	class WP_Site {
		public function __construct( public int $blog_id ) {}
	}
}

function af_test_set_multisite( bool $on ): void {
	global $af_test_is_multisite;
	$af_test_is_multisite = $on;
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		global $af_test_is_multisite;
		return (bool) $af_test_is_multisite;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		global $af_test_current_blog_id;
		return (int) $af_test_current_blog_id;
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( array $args = array() ): array {
		unset( $args );
		global $af_test_site_ids;
		return $af_test_site_ids;
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $site_id ): bool {
		global $af_test_current_blog_id, $af_test_blog_stack, $af_test_blog_switches;
		$af_test_blog_stack[]      = $af_test_current_blog_id;
		$af_test_current_blog_id   = $site_id;
		$af_test_blog_switches[]   = $site_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		global $af_test_current_blog_id, $af_test_blog_stack;
		if ( empty( $af_test_blog_stack ) ) {
			return false;
		}
		$af_test_current_blog_id = (int) array_pop( $af_test_blog_stack );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

// ---------------------------------------------------------------------------
// Nonces
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '' ): string {
		return 'test-nonce';
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( mixed $nonce, string $action = '' ): bool {
		return $nonce === 'test-nonce';
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action = '', string $field = '_wpnonce' ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}
}

// ---------------------------------------------------------------------------
// User meta
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
		global $af_test_user_meta;
		$value = $af_test_user_meta[ $user_id ][ $key ] ?? '';
		if ( $single ) {
			return $value;
		}
		return $value === '' ? array() : array( $value );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, mixed $value ): bool {
		global $af_test_user_meta;
		$af_test_user_meta[ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $user_id, string $key, mixed $value = '' ): bool {
		global $af_test_user_meta;
		unset( $af_test_user_meta[ $user_id ][ $key ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Post meta
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		global $af_test_post_meta;
		$value = $af_test_post_meta[ $post_id ][ $key ] ?? '';
		if ( $single ) {
			return $value;
		}
		return $value === '' ? array() : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev = '' ): bool {
		global $af_test_post_meta;
		$af_test_post_meta[ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		global $af_test_post_meta;
		unset( $af_test_post_meta[ $post_id ][ $key ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		global $af_test_scheduled_events;
		$af_test_scheduled_events[ $hook ] = array(
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
			'args'       => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		global $af_test_scheduled_events;
		$af_test_scheduled_events[ $hook ] = array(
			'timestamp'  => $timestamp,
			'recurrence' => false,
			'args'       => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ): int|false {
		global $af_test_scheduled_events;
		return $af_test_scheduled_events[ $hook ]['timestamp'] ?? false;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = array() ): mixed {
		global $af_test_scheduled_events;
		unset( $af_test_scheduled_events[ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): mixed {
		return wp_clear_scheduled_hook( $hook, $args );
	}
}

// ---------------------------------------------------------------------------
// Lifecycle / Redirects / Misc
// ---------------------------------------------------------------------------

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( string $domain, mixed $deprecated = false, string $path = '' ): bool {
		return true;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $callback ): void {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {}
}

if ( ! function_exists( 'register_uninstall_hook' ) ) {
	function register_uninstall_hook( string $file, callable $callback ): void {}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $url, int $status = 302 ): bool {
		global $af_test_redirects, $af_test_redirect_throws;
		$af_test_redirects[] = array(
			'url'    => $url,
			'status' => $status,
		);
		if ( ! empty( $af_test_redirect_throws ) ) {
			throw new RuntimeException( 'af_test_redirect:' . $url );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( string $url, int $status = 302 ): bool {
		return wp_safe_redirect( $url, $status );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string|WP_Error $message = '', mixed $title = '', mixed $args = array() ): void {
		$msg = $message instanceof WP_Error ? $message->get_error_message() : (string) $message;
		throw new RuntimeException( 'wp_die: ' . $msg );
	}
}

if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( mixed $data, ?int $status_code = null ): void {
		throw new RuntimeException( 'wp_send_json: ' . wp_json_encode( $data ) );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( mixed $data = null, ?int $status_code = null ): void {
		wp_send_json( array( 'success' => true, 'data' => $data ), $status_code );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( mixed $data = null, ?int $status_code = null ): void {
		wp_send_json( array( 'success' => false, 'data' => $data ), $status_code );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), mixed $ver = false, mixed $in_footer = false ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( string $handle, string $src = '', array $deps = array(), mixed $ver = false, mixed $in_footer = false ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		global $af_test_inline_scripts;
		if ( ! isset( $af_test_inline_scripts ) || ! is_array( $af_test_inline_scripts ) ) {
			$af_test_inline_scripts = array();
		}
		$af_test_inline_scripts[] = array(
			'handle'   => $handle,
			'data'     => $data,
			'position' => $position,
		);
		return true;
	}
}

if ( ! function_exists( 'af_test_get_inline_scripts' ) ) {
	function af_test_get_inline_scripts(): array {
		global $af_test_inline_scripts;
		return is_array( $af_test_inline_scripts ?? null ) ? $af_test_inline_scripts : array();
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), mixed $ver = false, string $media = 'all' ): bool {
		global $af_test_enqueued_styles;
		if ( ! isset( $af_test_enqueued_styles ) || ! is_array( $af_test_enqueued_styles ) ) {
			$af_test_enqueued_styles = array();
		}
		$af_test_enqueued_styles[] = $handle;
		return true;
	}
}

if ( ! function_exists( 'af_test_get_enqueued_styles' ) ) {
	function af_test_get_enqueued_styles(): array {
		global $af_test_enqueued_styles;
		return is_array( $af_test_enqueued_styles ?? null ) ? $af_test_enqueued_styles : array();
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( string $handle, string $src = '', array $deps = array(), mixed $ver = false, string $media = 'all' ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $data ): bool {
		global $af_test_options;
		$af_test_options[ '__localized_' . $object_name ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media( array $args = array() ): void {}
}

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( mixed $block_type, array $args = array() ): mixed {
		return true;
	}
}

global $af_test_menu_pages;
$af_test_menu_pages = array();

function af_test_get_menu_pages(): array {
	global $af_test_menu_pages;
	return $af_test_menu_pages;
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', string $icon = '', mixed $position = null ): string {
		global $af_test_menu_pages;
		$af_test_menu_pages[] = array(
			'type'        => 'top',
			'parent_slug' => '',
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
		);
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', mixed $position = null ): string {
		global $af_test_menu_pages;
		$af_test_menu_pages[] = array(
			'type'        => 'sub',
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
		);
		return $parent_slug . '_page_' . $menu_slug;
	}
}

global $af_test_roles;
if ( ! isset( $af_test_roles ) ) {
	$af_test_roles = array();
}

if ( ! function_exists( 'af_test_reset_roles' ) ) {
	function af_test_reset_roles(): void {
		global $af_test_roles;
		$af_test_roles = array();
	}
}

if ( ! function_exists( 'af_test_set_role' ) ) {
	function af_test_set_role( string $slug, array $capabilities = array() ): void {
		global $af_test_roles;
		$af_test_roles[ $slug ] = (object) array(
			'name'         => $slug,
			'capabilities' => $capabilities,
			'add_cap'      => null,
			'remove_cap'   => null,
		);
	}
}

if ( ! class_exists( 'AF_Test_WP_Role' ) ) {
	final class AF_Test_WP_Role {
		public string $name;
		/** @var array<string,bool> */
		public array $capabilities;

		/**
		 * @param array<string,bool> $capabilities
		 */
		public function __construct( string $name, array $capabilities = array() ) {
			$this->name         = $name;
			$this->capabilities = $capabilities;
		}

		public function add_cap( string $cap, bool $grant = true ): void {
			$this->capabilities[ $cap ] = $grant;
		}

		public function remove_cap( string $cap ): void {
			unset( $this->capabilities[ $cap ] );
		}

		public function has_cap( string $cap ): bool {
			return ! empty( $this->capabilities[ $cap ] );
		}
	}
}

if ( ! function_exists( 'remove_role' ) ) {
	function remove_role( string $role ): void {
		global $af_test_roles;
		unset( $af_test_roles[ $role ] );
	}
}

if ( ! function_exists( 'add_role' ) ) {
	function add_role( string $role, string $display_name, array $capabilities = array() ): mixed {
		global $af_test_roles;
		if ( isset( $af_test_roles[ $role ] ) ) {
			return null;
		}
		$af_test_roles[ $role ] = new AF_Test_WP_Role( $role, $capabilities );
		return $af_test_roles[ $role ];
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ): mixed {
		global $af_test_roles;
		return $af_test_roles[ $role ] ?? null;
	}
}

if ( ! function_exists( 'af_test_get_roles' ) ) {
	function af_test_get_roles(): array {
		global $af_test_roles;
		return $af_test_roles;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object {
		return (object) array(
			'ID'           => get_current_user_id(),
			'user_login'   => 'admin',
			'display_name' => 'Admin',
		);
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		return array();
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context = '', ?string $domain = null ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, ?string $domain = null ): string {
		return $number === 1 ? $single : $plural;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( string $text, ?string $domain = null ): void {
		echo $text;
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( in_array( $lower, array( 'false', '0', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}
		return (bool) $value;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function, string $message, string $version ): void {}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}

// ---------------------------------------------------------------------------
// Shortcodes + oEmbed + misc helpers used by shortcode/role/consent tests.
// ---------------------------------------------------------------------------

global $af_test_shortcodes, $af_test_oembed_providers, $af_test_users_by;

if ( ! isset( $af_test_shortcodes ) ) {
	$af_test_shortcodes = array();
}
if ( ! isset( $af_test_oembed_providers ) ) {
	$af_test_oembed_providers = array();
}
if ( ! isset( $af_test_users_by ) ) {
	$af_test_users_by = array();
}

if ( ! function_exists( 'af_test_get_shortcodes' ) ) {
	function af_test_get_shortcodes(): array {
		global $af_test_shortcodes;
		return $af_test_shortcodes;
	}
}

if ( ! function_exists( 'af_test_get_oembed_providers' ) ) {
	function af_test_get_oembed_providers(): array {
		global $af_test_oembed_providers;
		return $af_test_oembed_providers;
	}
}

if ( ! function_exists( 'af_test_reset_shortcode_state' ) ) {
	function af_test_reset_shortcode_state(): void {
		global $af_test_shortcodes, $af_test_oembed_providers;
		$af_test_shortcodes       = array();
		$af_test_oembed_providers = array();
	}
}

if ( ! function_exists( 'af_test_register_user' ) ) {
	function af_test_register_user( string $field, string $value, object $user ): void {
		global $af_test_users_by;
		$af_test_users_by[ $field ][ $value ] = $user;
	}
}

if ( ! function_exists( 'af_test_reset_users' ) ) {
	function af_test_reset_users(): void {
		global $af_test_users_by;
		$af_test_users_by = array();
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( string $tag, callable $callback ): void {
		global $af_test_shortcodes;
		$af_test_shortcodes[ $tag ] = $callback;
	}
}

if ( ! function_exists( 'remove_shortcode' ) ) {
	function remove_shortcode( string $tag ): void {
		global $af_test_shortcodes;
		unset( $af_test_shortcodes[ $tag ] );
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( array $defaults, mixed $atts, string $shortcode = '' ): array {
		$atts = is_array( $atts ) ? $atts : array();
		$out  = $defaults;
		foreach ( $atts as $key => $value ) {
			if ( array_key_exists( $key, $defaults ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'wp_oembed_add_provider' ) ) {
	function wp_oembed_add_provider( string $format, string $provider, bool $regex = false ): void {
		global $af_test_oembed_providers;
		$af_test_oembed_providers[ $format ] = array(
			'provider' => $provider,
			'regex'    => $regex,
		);
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( mixed $value ): bool|string {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false;
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int|false {
		return 0;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( string $handle, string $domain = 'default', ?string $path = null ): bool {
		global $af_test_script_translations;
		$af_test_script_translations[ $handle ] = $domain;
		unset( $path );
		return true;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( mixed $post = 0 ): string {
		$resolved = get_post( $post );
		if ( $resolved instanceof WP_Post && $resolved->ID > 0 ) {
			return 'https://example.test/?page_id=' . $resolved->ID;
		}
		return 'https://example.test/portal';
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( mixed $post = null ): string|false {
		$resolved = get_post( $post );
		return $resolved instanceof WP_Post ? $resolved->post_status : false;
	}
}

if ( ! function_exists( 'wp_dropdown_pages' ) ) {
	function wp_dropdown_pages( array $args = array() ): string {
		global $af_test_posts;

		$name     = (string) ( $args['name'] ?? 'page_id' );
		$selected = (string) ( $args['selected'] ?? '' );
		$html     = '<select name="' . $name . '" id="' . (string) ( $args['id'] ?? $name ) . '">';

		if ( isset( $args['show_option_none'] ) ) {
			$none_value = (string) ( $args['option_none_value'] ?? '' );
			$html      .= '<option value="' . $none_value . '"' . ( $selected === $none_value ? ' selected' : '' ) . '>'
				. (string) $args['show_option_none'] . '</option>';
		}

		foreach ( $af_test_posts as $post ) {
			if ( 'page' !== $post->post_type ) {
				continue;
			}
			if ( isset( $args['post_status'] ) && $args['post_status'] !== $post->post_status ) {
				continue;
			}
			$html .= '<option value="' . $post->ID . '"' . ( $selected === (string) $post->ID ? ' selected' : '' ) . '>'
				. $post->post_title . '</option>';
		}

		$html .= '</select>';

		if ( isset( $args['echo'] ) && ! $args['echo'] ) {
			return $html;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
		return $html;
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( string $redirect = '' ): string {
		$url = 'https://example.test/wp-login.php';
		if ( '' !== $redirect ) {
			$url .= '?redirect_to=' . rawurlencode( $redirect );
		}
		return $url;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, mixed $value ): mixed {
		global $af_test_users_by;
		return $af_test_users_by[ $field ][ (string) $value ] ?? false;
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url( int $blog_id = 0, string $path = '' ): string {
		return 'https://example.test' . ( $path === '' ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : 'test-salt';
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true, mixed $other = '' ): void {
		echo '<button>' . esc_html( $text ) . '</button>';
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $echo = true ): string {
		$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, ?string $domain = null ): void {
		echo esc_attr( $text );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test stub only.
	class WP_Post {
		public int $ID           = 0;
		public string $post_content = '';
		public string $post_status  = 'publish';
		public string $post_title   = '';
		public string $post_type    = 'page';

		/**
		 * @param array<string,mixed> $data Post fields.
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->{$key} = $value;
				}
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $post = null ): ?WP_Post {
		global $af_test_posts;
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		$id = (int) $post;
		return $af_test_posts[ $id ] ?? null;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		global $af_test_posts;
		$post_type   = $args['post_type'] ?? 'post';
		$post_status = $args['post_status'] ?? 'publish';
		$out         = array();
		foreach ( $af_test_posts as $post ) {
			if ( $post->post_type === $post_type && $post->post_status === $post_status ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ): mixed {
		unset( $wp_error );
		return af_test_add_post( $postarr )->ID;
	}
}

if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( string $content, string $tag ): bool {
		return false !== strpos( $content, '[' . $tag );
	}
}

if ( ! function_exists( 'has_block' ) ) {
	function has_block( string $block_name, mixed $post = null ): bool {
		if ( $post instanceof WP_Post ) {
			$content = $post->post_content;
		} elseif ( is_string( $post ) ) {
			$content = $post;
		} else {
			$fetched = get_post( $post );
			$content = $fetched instanceof WP_Post ? $fetched->post_content : '';
		}
		return false !== strpos( $content, 'wp:' . $block_name );
	}
}
