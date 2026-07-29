<?php
/**
 * Tests for the Shortcodes class — registration of all tags, embed iframe
 * markup, public portal mounting
 * (with demo mode + signed token), and the classic-editor delegation paths
 * for the block render templates.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use AlmostFamous\Config;
use AlmostFamous\Shortcodes\Shortcodes;
use PHPUnit\Framework\TestCase;

class Test_Shortcodes extends TestCase {

	private Shortcodes $shortcodes;

	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$_POST   = array();
		$_COOKIE = array();
		$this->shortcodes = new Shortcodes();
	}

	protected function tearDown(): void {
		$_POST   = array();
		$_COOKIE = array();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// register_shortcodes()
	// -----------------------------------------------------------------------

	public function test_register_shortcodes_adds_every_documented_tag(): void {
		$this->shortcodes->register_shortcodes();

		$tags = array_keys( af_test_get_shortcodes() );
		foreach (
			array(
				'almost-famous-portal',
				'af-campaign-widget',
			) as $expected
		) {
			$this->assertContains( $expected, $tags );
		}

		// The iframe-embed shortcode and oEmbed provider were removed —
		// the embed.bushido.is service they targeted does not exist.
		$this->assertNotContains( 'bushido-almost-famous', $tags );
		$this->assertEmpty( af_test_get_oembed_providers() );
	}

	// -----------------------------------------------------------------------
	// [almost-famous-portal] public portal mount
	// -----------------------------------------------------------------------

	public function test_portal_shortcode_outputs_mount_target_and_localizes_data(): void {
		$html = $this->shortcodes->render_public_portal_shortcode( array() );

		$this->assertStringContainsString( 'id="af-public-portal"', $html );
		$this->assertStringContainsString( 'af-loading', $html );

		$localized = get_option( '__localized_afPublicPortal' );
		$this->assertIsArray( $localized );
		$this->assertArrayHasKey( 'restBase', $localized );
		$this->assertArrayHasKey( 'nonce', $localized );
		$this->assertArrayHasKey( 'demoMode', $localized );
		$this->assertArrayNotHasKey( 'portalToken', $localized );
		// The console pre-fills a campaign's destination from this, so ads land
		// back on this site where the attribution cookie can see the click.
		$this->assertSame( 'https://example.test/', $localized['defaultDestination'] );
		$this->assertSame( 'almost-famous/v1', $localized['restNamespace'] );
		$this->assertSame( 'test-nonce', $localized['nonce'] );
	}

	public function test_portal_shortcode_demo_attribute_enables_demo_mode(): void {
		$html = $this->shortcodes->render_public_portal_shortcode( array( 'demo' => '1' ) );

		$this->assertStringContainsString( 'id="af-public-portal"', $html );

		$localized = get_option( '__localized_afPublicPortal' );
		$this->assertTrue( $localized['demoMode'] );

		// The portal is an authenticated console — no guest token is emitted.
		$this->assertArrayNotHasKey( 'portalToken', $localized );
	}

	// -----------------------------------------------------------------------
	// Classic-editor fallback shortcodes — delegation to block render.
	// -----------------------------------------------------------------------

	public function test_campaign_widget_shortcode_short_circuits_when_id_missing(): void {
		$html = $this->shortcodes->render_campaign_widget_shortcode( array() );
		$this->assertStringContainsString( 'af-shortcode-error', $html );
	}

	public function test_campaign_widget_shortcode_delegates_to_block_render(): void {
		// No api key hash configured → block template returns the "configure
		// first" message, proving the template was actually included.
		$html = $this->shortcodes->render_campaign_widget_shortcode( array( 'id' => 'cw1' ) );
		$this->assertStringContainsString( 'af-campaign-widget', $html );
		$this->assertStringContainsString( 'configure your Bushido API key', $html );
	}
}
