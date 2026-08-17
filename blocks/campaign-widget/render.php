<?php
/**
 * Server-side render for campaign-widget block.
 *
 * @package AlmostFamous
 *
 * @var array $attributes Block attributes.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$almost_famous_campaign_id = isset( $attributes['campaign_id'] ) ? sanitize_text_field( $attributes['campaign_id'] ) : '';

// Require an effective site or network API key before rendering external data.
if ( ! \AlmostFamous\Plugin::get_instance()->site_manager()->has_api_key() ) {
	echo '<div class="af-campaign-widget af-campaign-widget--empty">';
	echo '<p>' . esc_html__( 'Please configure your Bushido API key in the Bushido Almost Famous settings.', 'bushido-almost-famous' ) . '</p>';
	echo '</div>';
	return;
}

if ( empty( $almost_famous_campaign_id ) ) {
	echo '<div class="af-campaign-widget af-campaign-widget--empty">';
	echo '<p>' . esc_html__( 'Please select a campaign to display.', 'bushido-almost-famous' ) . '</p>';
	echo '</div>';
	return;
}

// Read campaign data from cache.
$almost_famous_cache     = new \AlmostFamous\Api\Api_Cache();
$almost_famous_cache_key = $almost_famous_cache->build_key( 'campaigns', $almost_famous_campaign_id );
$almost_famous_campaign  = $almost_famous_cache->get( $almost_famous_cache_key );

// If not cached, try to fetch from the API.
if ( null === $almost_famous_campaign ) {
	$almost_famous_client   = \AlmostFamous\Plugin::get_instance()->api_client();
	$almost_famous_response = $almost_famous_client->get( '/campaigns/' . $almost_famous_campaign_id );

	if ( ! isset( $almost_famous_response['error'] ) && isset( $almost_famous_response['data'] ) ) {
		$almost_famous_campaign = $almost_famous_response['data'];
		$almost_famous_cache->set( $almost_famous_cache_key, $almost_famous_campaign, 'campaigns' );
	}
}

if ( empty( $almost_famous_campaign ) || ! is_array( $almost_famous_campaign ) ) {
	echo '<div class="af-campaign-widget af-campaign-widget--error">';
	echo '<p>' . esc_html__( 'Campaign data unavailable.', 'bushido-almost-famous' ) . '</p>';
	echo '</div>';
	return;
}

// Extract display values.
$almost_famous_name            = esc_html( $almost_famous_campaign['name'] ?? __( 'Unknown Campaign', 'bushido-almost-famous' ) );
$almost_famous_campaign_status = sanitize_key( $almost_famous_campaign['status'] ?? 'unknown' );
$almost_famous_roas            = isset( $almost_famous_campaign['roas'] ) ? number_format( (float) $almost_famous_campaign['roas'], 2 ) : '—';
$almost_famous_impressions     = isset( $almost_famous_campaign['impressions'] ) ? number_format( (int) $almost_famous_campaign['impressions'] ) : '—';
$almost_famous_spend           = isset( $almost_famous_campaign['spend'] ) ? '$' . number_format( (float) $almost_famous_campaign['spend'], 2 ) : '—';

// Status badge classes.
$almost_famous_status_classes = array(
	'active'    => 'af-badge--active',
	'paused'    => 'af-badge--paused',
	'draft'     => 'af-badge--draft',
	'completed' => 'af-badge--completed',
	'archived'  => 'af-badge--archived',
);
$almost_famous_badge_class    = $almost_famous_status_classes[ $almost_famous_campaign_status ] ?? 'af-badge--default';
$almost_famous_status_label   = ucfirst( $almost_famous_campaign_status );

?>
<div class="af-campaign-widget">
	<div class="af-campaign-widget__header">
		<h4 class="af-campaign-widget__name">
			<?php echo $almost_famous_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above. ?>
		</h4>
		<span class="af-badge <?php echo esc_attr( $almost_famous_badge_class ); ?>">
			<?php echo esc_html( $almost_famous_status_label ); ?>
		</span>
	</div>

	<div class="af-campaign-widget__metrics">
		<div class="af-campaign-widget__metric">
			<span class="af-campaign-widget__metric-label">
				<?php esc_html_e( 'ROAS', 'bushido-almost-famous' ); ?>
			</span>
			<span class="af-campaign-widget__metric-value">
				<?php echo esc_html( $almost_famous_roas ); ?>
			</span>
		</div>

		<div class="af-campaign-widget__metric">
			<span class="af-campaign-widget__metric-label">
				<?php esc_html_e( 'Impressions', 'bushido-almost-famous' ); ?>
			</span>
			<span class="af-campaign-widget__metric-value">
				<?php echo esc_html( $almost_famous_impressions ); ?>
			</span>
		</div>

		<div class="af-campaign-widget__metric">
			<span class="af-campaign-widget__metric-label">
				<?php esc_html_e( 'Spend', 'bushido-almost-famous' ); ?>
			</span>
			<span class="af-campaign-widget__metric-value">
				<?php echo esc_html( $almost_famous_spend ); ?>
			</span>
		</div>
	</div>
</div>
