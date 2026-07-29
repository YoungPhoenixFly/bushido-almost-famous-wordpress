<?php
/**
 * Tests for blocks/campaign-widget/render.php — the configure-first gate,
 * empty campaign_id branch, cache hit / cache miss paths, and status badge
 * rendering with sanitization.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Api\Api_Cache;
use PHPUnit\Framework\TestCase;

class Test_Block_Campaign_Widget_Render extends TestCase {

	private const TEMPLATE = ALMOST_FAMOUS_PLUGIN_DIR . 'blocks/campaign-widget/render.php';

	private function render( array $attributes ): string {
		$block = null;
		ob_start();
		include self::TEMPLATE;
		return (string) ob_get_clean();
	}

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
	}

	private function configure_api_key( string $key = 'bsh_widget' ): void {
		update_option( 'af_api_key', ( new Api_Auth() )->encrypt_api_key( $key ) );
	}

	public function test_short_circuits_when_api_key_hash_missing(): void {
		$html = $this->render( array( 'campaign_id' => 'c1' ) );
		$this->assertStringContainsString( 'af-campaign-widget--empty', $html );
		$this->assertStringContainsString( 'configure your Bushido API key', $html );
	}

	public function test_short_circuits_when_campaign_id_empty(): void {
		$this->configure_api_key();

		$html = $this->render( array( 'campaign_id' => '' ) );
		$this->assertStringContainsString( 'af-campaign-widget--empty', $html );
		$this->assertStringContainsString( 'select a campaign to display', $html );
	}

	public function test_cache_hit_renders_metrics_with_escaped_name(): void {
		$this->configure_api_key();

		$cache = new Api_Cache();
		$cache->set(
			$cache->build_key( 'campaigns', 'camp-1' ),
			array(
				'name'        => 'Tour <2026>',
				'status'      => 'active',
				'roas'        => 3.5,
				'impressions' => 1500000,
				'spend'       => 1234.56,
			),
			'campaigns'
		);

		$html = $this->render( array( 'campaign_id' => 'camp-1' ) );

		$this->assertStringContainsString( 'Tour &lt;2026&gt;', $html );
		// Number formatting.
		$this->assertStringContainsString( '1,500,000', $html );
		$this->assertStringContainsString( '$1,234.56', $html );
		// ROAS to 2 decimals.
		$this->assertStringContainsString( '3.50', $html );
		// Active status badge.
		$this->assertStringContainsString( 'af-badge--active', $html );
		$this->assertStringContainsString( 'Active', $html );

		// Cache hit → no HTTP request.
		$this->assertSame( array(), af_test_get_http_requests() );
	}

	public function test_unknown_status_falls_back_to_default_badge(): void {
		$this->configure_api_key();

		$cache = new Api_Cache();
		$cache->set(
			$cache->build_key( 'campaigns', 'camp-2' ),
			array(
				'name'   => 'Mystery',
				'status' => 'weird-state',
			),
			'campaigns'
		);

		$html = $this->render( array( 'campaign_id' => 'camp-2' ) );
		$this->assertStringContainsString( 'af-badge--default', $html );
	}

	public function test_cache_miss_calls_api_and_warms_cache(): void {
		$this->configure_api_key( 'bsh_camp' );

		af_test_register_http_response(
			'/campaigns/camp-9',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'data' => array(
							'name'   => 'Live Fetched',
							'status' => 'paused',
						),
					)
				),
				'headers'  => array(),
			)
		);

		$html = $this->render( array( 'campaign_id' => 'camp-9' ) );

		$this->assertStringContainsString( 'Live Fetched', $html );
		$this->assertStringContainsString( 'af-badge--paused', $html );

		$cache = new Api_Cache();
		$this->assertNotNull( $cache->get( $cache->build_key( 'campaigns', 'camp-9' ) ) );

		$requests = af_test_get_http_requests();
		$this->assertNotEmpty( $requests );
		$this->assertStringContainsString( '/campaigns/camp-9', $requests[ array_key_last( $requests ) ]['url'] );
	}

	public function test_renders_unavailable_state_when_api_errors(): void {
		$this->configure_api_key( 'bsh_dead' );

		af_test_register_http_response(
			'/campaigns/missing',
			array(
				'response' => array( 'code' => 500 ),
				'body'     => wp_json_encode( array( 'error' => array( 'message' => 'nope' ) ) ),
				'headers'  => array(),
			)
		);

		$html = $this->render( array( 'campaign_id' => 'missing' ) );
		$this->assertStringContainsString( 'af-campaign-widget--error', $html );
		$this->assertStringContainsString( 'Campaign data unavailable', $html );
	}
}
