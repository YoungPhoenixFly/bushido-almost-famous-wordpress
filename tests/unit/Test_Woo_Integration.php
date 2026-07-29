<?php
/**
 * Tests that Woo_Integration captures attribution from the af_attribution
 * cookie or UTM query parameters into order meta. Validates the precedence
 * (cookie wins over UTM), that the buyer's marketing-consent verdict is
 * captured as order meta at order-creation time (the buyer's own request
 * context), and that the front-end attribution-capture cookie writer is
 * enqueued only with consent and emits the exact JSON shape the checkout
 * parser expects.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Commerce\Woo_Integration;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs/WC_Order.php';

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $id ) {
		global $af_test_orders;
		return $af_test_orders[ $id ] ?? null;
	}
}

class Test_Woo_Integration extends TestCase {

	private Woo_Integration $integration;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_COOKIE = array();
		$_GET    = array();
		$this->integration = new Woo_Integration();
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		$_GET    = array();
		parent::tearDown();
	}

	public function test_cookie_attribution_stored_on_order_creation(): void {
		$_COOKIE['af_attribution'] = wp_json_encode(
			array(
				'platform'    => 'meta',
				'campaign_id' => 'cmp_42',
				'campaign'    => 'Spring Push',
				'source'      => 'facebook',
				'medium'      => 'cpc',
				'click_id'    => 'fbclid_xyz',
			)
		);

		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		$this->assertArrayHasKey( 'af_attribution', $order->meta );
		$this->assertSame( 'meta', $order->meta['af_attribution']['platform'] );
		$this->assertSame( 'cmp_42', $order->meta['af_attribution']['campaign_id'] );
		$this->assertSame( 'Spring Push', $order->meta['af_attribution']['campaign'] );
		$this->assertSame( 'facebook', $order->meta['af_attribution']['source'] );
		$this->assertSame( 'cpc', $order->meta['af_attribution']['medium'] );
		$this->assertSame( 'fbclid_xyz', $order->meta['af_attribution']['click_id'] );
		$this->assertArrayHasKey( 'timestamp', $order->meta['af_attribution'] );
		$this->assertTrue( $order->saved );
	}

	public function test_utm_attribution_used_when_cookie_absent(): void {
		$_GET['utm_source']   = 'newsletter';
		$_GET['utm_medium']   = 'email';
		$_GET['utm_campaign'] = 'launch';

		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		$this->assertSame( 'newsletter', $order->meta['af_attribution']['source'] );
		$this->assertSame( 'email', $order->meta['af_attribution']['medium'] );
		$this->assertSame( 'launch', $order->meta['af_attribution']['campaign'] );
		// Cookie-only fields are blank when falling back to UTM.
		$this->assertSame( '', $order->meta['af_attribution']['platform'] );
		$this->assertSame( '', $order->meta['af_attribution']['click_id'] );
	}

	public function test_cookie_takes_precedence_over_utm_params(): void {
		$_COOKIE['af_attribution'] = wp_json_encode(
			array(
				'platform' => 'tiktok',
				'source'   => 'tiktok',
				'medium'   => 'video',
				'campaign' => 'CookieCampaign',
			)
		);
		$_GET['utm_source']   = 'newsletter';
		$_GET['utm_medium']   = 'email';
		$_GET['utm_campaign'] = 'UtmCampaign';

		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		// Cookie wins.
		$this->assertSame( 'tiktok', $order->meta['af_attribution']['platform'] );
		$this->assertSame( 'tiktok', $order->meta['af_attribution']['source'] );
		$this->assertSame( 'video', $order->meta['af_attribution']['medium'] );
		$this->assertSame( 'CookieCampaign', $order->meta['af_attribution']['campaign'] );
	}

	public function test_no_attribution_meta_written_when_cookie_and_utm_missing(): void {
		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		// The consent verdict is always captured, but no attribution meta
		// is invented when neither the cookie nor UTM params are present.
		$this->assertArrayNotHasKey( 'af_attribution', $order->meta );
		$this->assertSame( array( 'af_marketing_consent' => 'denied' ), $order->meta );
	}

	public function test_invalid_cookie_json_falls_back_to_utm(): void {
		$_COOKIE['af_attribution'] = 'not-json{';
		$_GET['utm_source']        = 'twitter';
		$_GET['utm_medium']        = 'social';
		$_GET['utm_campaign']      = 'organic';

		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		$this->assertSame( 'twitter', $order->meta['af_attribution']['source'] );
	}

	public function test_non_order_argument_is_ignored(): void {
		// Passing something that is not a WC_Order — handler must short-circuit.
		$this->integration->on_order_created( null );
		$this->expectNotToPerformAssertions();
	}

	public function test_is_available_reflects_woocommerce_presence(): void {
		// WC_Order is declared, but WooCommerce sentinel class is not — so the
		// integration treats WooCommerce as inactive in this environment.
		$this->assertFalse( Woo_Integration::is_available() );
	}

	public function test_consent_verdict_granted_captured_at_order_creation(): void {
		add_filter( 'almost_famous_default_consent', '__return_true' );

		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		$this->assertSame( Woo_Integration::CONSENT_GRANTED, $order->meta[ Woo_Integration::CONSENT_META_KEY ] );
		$this->assertTrue( $order->saved );
	}

	public function test_consent_verdict_denied_captured_at_order_creation(): void {
		// No CMP active and no opt-in: default-deny posture applies.
		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		$this->assertSame( Woo_Integration::CONSENT_DENIED, $order->meta[ Woo_Integration::CONSENT_META_KEY ] );
	}

	public function test_existing_consent_verdict_is_never_overwritten(): void {
		// Verdict captured at checkout (granted) must survive a later
		// re-fire in a context where consent would evaluate to denied.
		$order = new \WC_Order();
		$order->meta[ Woo_Integration::CONSENT_META_KEY ] = Woo_Integration::CONSENT_GRANTED;

		$this->integration->on_order_created( $order );

		$this->assertSame( Woo_Integration::CONSENT_GRANTED, $order->meta[ Woo_Integration::CONSENT_META_KEY ] );
	}

	public function test_capture_attribution_data_records_consent_even_when_attribution_exists(): void {
		add_filter( 'almost_famous_default_consent', '__return_true' );

		$order       = new \WC_Order();
		$order->id   = 55;
		$order->meta = array( 'af_attribution' => array( 'source' => 'existing' ) );

		global $af_test_orders;
		$af_test_orders       = array();
		$af_test_orders[55]   = $order;

		$this->integration->capture_attribution_data( 55 );

		// Attribution untouched (double-fire guard), consent still captured.
		$this->assertSame( array( 'source' => 'existing' ), $order->meta['af_attribution'] );
		$this->assertSame( Woo_Integration::CONSENT_GRANTED, $order->meta[ Woo_Integration::CONSENT_META_KEY ] );
	}

	public function test_cookie_writer_payload_shape_round_trips_through_parser(): void {
		// Mirror exactly what the front-end capture script would write for
		// ?gclid=abc123&utm_source=google&utm_medium=cpc&utm_campaign=summer
		// (PHP has already urldecoded the cookie value into $_COOKIE).
		$_COOKIE['af_attribution'] = wp_json_encode(
			array(
				'platform'    => 'google',
				'campaign_id' => 'summer',
				'campaign'    => 'summer',
				'source'      => 'google',
				'medium'      => 'cpc',
				'click_id'    => 'abc123',
			)
		);

		$order = new \WC_Order();

		$this->integration->on_order_created( $order );

		$this->assertSame( 'google', $order->meta['af_attribution']['platform'] );
		$this->assertSame( 'summer', $order->meta['af_attribution']['campaign_id'] );
		$this->assertSame( 'summer', $order->meta['af_attribution']['campaign'] );
		$this->assertSame( 'google', $order->meta['af_attribution']['source'] );
		$this->assertSame( 'cpc', $order->meta['af_attribution']['medium'] );
		$this->assertSame( 'abc123', $order->meta['af_attribution']['click_id'] );
	}

	public function test_attribution_capture_script_enqueued_when_consent_granted(): void {
		add_filter( 'almost_famous_default_consent', '__return_true' );

		$this->integration->enqueue_attribution_capture_script();

		$scripts = af_test_get_inline_scripts();
		$this->assertCount( 1, $scripts );
		$this->assertSame( 'af-attribution-capture', $scripts[0]['handle'] );

		// The script must write the cookie the checkout parser reads, with
		// every key store_attribution_meta() expects, plus safe attributes.
		$js = $scripts[0]['data'];
		$this->assertStringContainsString( 'af_attribution=', $js );
		foreach ( array( 'platform', 'campaign_id', 'campaign', 'source', 'medium', 'click_id' ) as $key ) {
			$this->assertStringContainsString( $key . ':', $js );
		}
		$this->assertStringContainsString( 'SameSite=Lax', $js );
		$this->assertStringContainsString( 'path=/', $js );
		$this->assertStringContainsString( 'max-age=2592000', $js );
		$this->assertStringContainsString( 'Secure', $js );
	}

	public function test_attribution_capture_script_not_enqueued_without_consent(): void {
		// Default-deny posture, no CMP: the cookie writer must not ship.
		$this->integration->enqueue_attribution_capture_script();

		$this->assertSame( array(), af_test_get_inline_scripts() );
	}
}
