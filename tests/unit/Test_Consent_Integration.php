<?php
/**
 * Tests the public surface of Consent_Integration that does not require
 * mutating PHP's class table: WP Privacy exporter / eraser registration,
 * and the request payload forwarded to the Bushido API. The Cookiebot,
 * Complianz, and WP Consent API detection branches are exercised at the
 * integration tier where a real CMP plugin can be loaded.
 *
 * Builds on top of Test_Consent_Default.php which covers the no-CMP default.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Client;
use AlmostFamous\Consent\Consent_Integration;
use PHPUnit\Framework\TestCase;

class Test_Consent_Integration extends TestCase {

	private Api_Auth $auth;
	private Api_Client $client;
	private Consent_Integration $consent;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_COOKIE = array();

		$this->auth    = new Api_Auth();
		$this->client  = new Api_Client( $this->auth );
		$this->consent = new Consent_Integration( $this->client );
		global $af_test_wc_order_queries;
		$af_test_wc_order_queries = array();

		// Most cookie/email tests need a stored key so requests actually fire.
		update_option(
			'af_api_key',
			$this->auth->encrypt_api_key( 'bsh_consent_tests' )
		);
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Default-denied posture is still the dominant path when no CMP is loaded
	// (covered fully in Test_Consent_Default; we re-assert it here with a
	// CookieBot-shaped cookie present to prove the cookie alone is ignored
	// when no CMP class is loaded — the safest interpretation for unit tests).
	// -----------------------------------------------------------------------

	public function test_cookiebot_shaped_cookie_alone_does_not_grant_consent(): void {
		$_COOKIE['CookieConsent'] = wp_json_encode(
			array(
				'marketing'  => true,
				'statistics' => true,
			)
		);

		// Without the Cookiebot_WP class loaded, the integration short-circuits
		// to the almost_famous_default_consent filter (false by default).
		$this->assertFalse( $this->consent->has_consent() );
	}

	public function test_complianz_cookie_alone_does_not_grant_consent(): void {
		$_COOKIE['cmplz_marketing'] = 'allow';

		// Without cmplz_uses_consent() loaded, the integration short-circuits.
		$this->assertFalse( $this->consent->has_consent() );
	}

	public function test_cookiebot_statistics_only_consent_does_not_grant_marketing(): void {
		$_COOKIE['CookieConsent'] = wp_json_encode(
			array(
				'marketing'  => false,
				'statistics' => true,
			)
		);

		$method = new ReflectionMethod( Consent_Integration::class, 'get_cookiebot_consent' );
		$this->assertFalse( $method->invoke( $this->consent ) );

		$_COOKIE['CookieConsent'] = wp_json_encode( array( 'marketing' => true ) );
		$this->assertTrue( $method->invoke( $this->consent ) );
	}

	public function test_cookiebot_opaque_legacy_value_does_not_grant_marketing(): void {
		$_COOKIE['CookieConsent'] = '1';

		$method = new ReflectionMethod( Consent_Integration::class, 'get_cookiebot_consent' );
		$this->assertFalse( $method->invoke( $this->consent ) );
	}

	public function test_complianz_statistics_cookie_does_not_grant_marketing(): void {
		$_COOKIE['cmplz_statistics'] = 'allow';

		$method = new ReflectionMethod( Consent_Integration::class, 'get_complianz_consent' );
		$this->assertFalse( $method->invoke( $this->consent ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_complianz_region_exemption_does_not_grant_marketing_tracking(): void {
		function cmplz_uses_consent(): bool {
			return false;
		}

		$this->assertFalse( ( new Consent_Integration() )->has_consent() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_consent_api_requires_explicit_marketing_grant(): void {
		global $af_test_wp_marketing_consent;
		$af_test_wp_marketing_consent = false;

		function wp_get_consent_type(): string {
			return '';
		}

		function wp_has_consent( string $category ): bool {
			global $af_test_wp_marketing_consent;
			return 'marketing' === $category && true === $af_test_wp_marketing_consent;
		}

		$consent = new Consent_Integration();
		$this->assertFalse( $consent->has_consent() );

		$af_test_wp_marketing_consent = true;
		$this->assertTrue( $consent->has_consent() );
	}

	// -----------------------------------------------------------------------
	// Privacy exporter / eraser registration
	// -----------------------------------------------------------------------

	public function test_init_registers_data_exporter_and_eraser_filters(): void {
		$this->consent->init();

		$this->assertTrue( has_filter( 'wp_privacy_personal_data_exporters' ) );
		$this->assertTrue( has_filter( 'wp_privacy_personal_data_erasers' ) );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$this->assertArrayHasKey( 'bushido-almost-famous', $exporters );
		$this->assertSame( 'Bushido Almost Famous', $exporters['bushido-almost-famous']['exporter_friendly_name'] );
		$this->assertIsCallable( $exporters['bushido-almost-famous']['callback'] );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( 'bushido-almost-famous', $erasers );
		$this->assertSame( 'Bushido Almost Famous', $erasers['bushido-almost-famous']['eraser_friendly_name'] );
		$this->assertIsCallable( $erasers['bushido-almost-famous']['callback'] );
	}

	public function test_register_data_exporter_appends_without_clobbering_existing(): void {
		$existing = array(
			'other-plugin' => array(
				'exporter_friendly_name' => 'Other',
				'callback'               => '__return_true',
			),
		);

		$result = $this->consent->register_data_exporter( $existing );

		$this->assertArrayHasKey( 'other-plugin', $result );
		$this->assertArrayHasKey( 'bushido-almost-famous', $result );
	}

	public function test_register_data_eraser_appends_without_clobbering_existing(): void {
		$existing = array(
			'other-plugin' => array(
				'eraser_friendly_name' => 'Other',
				'callback'             => '__return_true',
			),
		);

		$result = $this->consent->register_data_eraser( $existing );

		$this->assertArrayHasKey( 'other-plugin', $result );
		$this->assertArrayHasKey( 'bushido-almost-famous', $result );
	}

	// -----------------------------------------------------------------------
	// Privacy export/erase operate on LOCAL WooCommerce attribution meta.
	// There is no backend privacy API — the backend only ever receives
	// hashed identifiers.
	// -----------------------------------------------------------------------

	public function test_export_personal_data_exports_order_attribution_meta(): void {
		global $af_test_wc_orders;
		$af_test_wc_orders = array(
			new Af_Test_Wc_Order(
				101,
				array(
					'utm_source'   => 'meta',
					'utm_campaign' => 'spring-promo',
					'af_click_id'  => 'clk_1',
				)
			),
			new Af_Test_Wc_Order( 102, array() ),
		);

		$result = $this->consent->export_personal_data( 'user@example.test', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'af-attribution', $result['data'][0]['group_id'] );
		$this->assertSame( 'af-attribution-101', $result['data'][0]['item_id'] );

		$names = array_column( $result['data'][0]['data'], 'name' );
		$this->assertContains( 'utm_source', $names );
		$this->assertContains( 'af_click_id', $names );

		// Local-only processing — no backend request may fire.
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	public function test_export_personal_data_handles_woocommerce_absent(): void {
		global $af_test_wc_orders;
		$af_test_wc_orders = null; // wc_get_orders stub returns non-array → treated as none.

		$result = $this->consent->export_personal_data( 'user@example.test', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), $result['data'] );
	}

	public function test_export_full_page_without_attribution_is_not_done(): void {
		global $af_test_wc_orders;
		$af_test_wc_orders = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$af_test_wc_orders[] = new Af_Test_Wc_Order( 500 + $i, array() );
		}

		$result = $this->consent->export_personal_data( 'user@example.test', 1 );

		$this->assertSame( array(), $result['data'] );
		$this->assertFalse( $result['done'], 'Pagination follows fetched orders, not emitted export rows.' );
	}

	public function test_export_second_page_queries_and_returns_only_that_page(): void {
		global $af_test_wc_orders, $af_test_wc_order_queries;
		$af_test_wc_orders = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$af_test_wc_orders[] = new Af_Test_Wc_Order( 700 + $i, array( 'utm_source' => 'meta' ) );
		}

		$result = $this->consent->export_personal_data( 'paged@example.test', 2 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'af-attribution-750', $result['data'][0]['item_id'] );
		$this->assertSame( 'paged@example.test', $af_test_wc_order_queries[0]['billing_email'] );
		$this->assertSame( 50, $af_test_wc_order_queries[0]['limit'] );
		$this->assertSame( 2, $af_test_wc_order_queries[0]['paged'] );
	}

	public function test_erase_personal_data_removes_attribution_meta(): void {
		global $af_test_wc_orders;
		$order_with    = new Af_Test_Wc_Order( 201, array( 'utm_source' => 'tiktok' ) );
		$order_without = new Af_Test_Wc_Order( 202, array() );

		$af_test_wc_orders = array( $order_with, $order_without );

		$result = $this->consent->erase_personal_data( 'gdpr@example.test', 1 );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( 0, $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['messages'] );

		$this->assertNull( $order_with->get_meta( 'af_attribution' ) ?: null );
		$this->assertTrue( $order_with->saved );
		$this->assertFalse( $order_without->saved );

		// Local-only processing — no backend request may fire.
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	public function test_erase_personal_data_reports_done_false_on_full_page(): void {
		global $af_test_wc_orders;
		$af_test_wc_orders = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$af_test_wc_orders[] = new Af_Test_Wc_Order( 300 + $i, array( 'utm_source' => 'meta' ) );
		}

		$result = $this->consent->erase_personal_data( 'gdpr@example.test', 1 );

		$this->assertSame( 50, $result['items_removed'] );
		$this->assertFalse( $result['done'], 'A full page means another page may exist.' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Minimal WC_Order stand-in for privacy tests.
 */
class Af_Test_Wc_Order {

	/**
	 * Whether save() was called.
	 *
	 * @var bool
	 */
	public bool $saved = false;

	/**
	 * Order meta keyed by meta key.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta;

	/**
	 * Order id.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Constructor.
	 *
	 * @param int   $id          Order id.
	 * @param array $attribution af_attribution meta value (empty = none).
	 */
	public function __construct( int $id, array $attribution ) {
		$this->id   = $id;
		$this->meta = empty( $attribution ) ? array() : array( 'af_attribution' => $attribution );
	}

	/**
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	/**
	 * @param string $key Meta key.
	 */
	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function save(): void {
		$this->saved = true;
	}

	/**
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * Test stub — returns the requested page from staged orders.
	 *
	 * @param array $args Query args.
	 * @return mixed
	 */
	function wc_get_orders( array $args ) {
		global $af_test_wc_orders, $af_test_wc_order_queries;
		$af_test_wc_order_queries[] = $args;
		if ( ! is_array( $af_test_wc_orders ) ) {
			return $af_test_wc_orders;
		}
		$limit  = max( 1, (int) ( $args['limit'] ?? 50 ) );
		$page   = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset = ( $page - 1 ) * $limit;
		return array_slice( $af_test_wc_orders, $offset, $limit );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
