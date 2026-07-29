<?php
/**
 * Settings template.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 *
 * @var \AlmostFamous\Admin\Settings $settings Settings instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$almost_famous_platforms = $settings->get_platforms();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$almost_famous_refreshed = isset( $_GET['af_platforms_refreshed'] );
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $almost_famous_refreshed ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Platform data refreshed.', 'bushido-almost-famous' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Platform Connection & Health Panel -->
	<div class="af-settings-section">
		<h2><?php esc_html_e( 'Platform Connections', 'bushido-almost-famous' ); ?></h2>
		<p><?php esc_html_e( 'Status of connected advertising platforms.', 'bushido-almost-famous' ); ?></p>

		<?php if ( ! empty( $almost_famous_platforms ) ) : ?>
			<table class="widefat striped af-platforms-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></th>
						<th><?php esc_html_e( 'Status', 'bushido-almost-famous' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'bushido-almost-famous' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $almost_famous_platforms as $almost_famous_platform ) : ?>
						<?php
						$almost_famous_name           = isset( $almost_famous_platform['name'] ) ? $almost_famous_platform['name'] : __( 'Unknown', 'bushido-almost-famous' );
						$almost_famous_status_display = $settings->get_platform_status_display( $almost_famous_platform );
						$almost_famous_platform_id    = isset( $almost_famous_platform['id'] ) ? $almost_famous_platform['id'] : '';
						$almost_famous_connect_url    = isset( $almost_famous_platform['connect_url'] ) ? $almost_famous_platform['connect_url'] : '';
						$almost_famous_is_connected   = in_array( ( $almost_famous_platform['status'] ?? '' ), array( 'connected', 'active', 'ok' ), true );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $almost_famous_name ); ?></strong>
								<?php if ( ! empty( $almost_famous_platform['description'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( $almost_famous_platform['description'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="<?php echo esc_attr( $almost_famous_status_display['class'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $almost_famous_status_display['icon'] ); ?>"></span>
								<?php echo esc_html( $almost_famous_status_display['label'] ); ?>
							</td>
							<td>
								<?php if ( ! empty( $almost_famous_connect_url ) ) : ?>
									<a
										href="<?php echo esc_url( $almost_famous_connect_url ); ?>"
										class="button <?php echo $almost_famous_is_connected ? 'button-secondary' : 'button-primary'; ?>"
										target="_blank"
										rel="noopener noreferrer"
									>
										<?php echo $almost_famous_is_connected ? esc_html__( 'Re-authenticate', 'bushido-almost-famous' ) : esc_html__( 'Connect', 'bushido-almost-famous' ); ?>
									</a>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'Managed via Bushido dashboard', 'bushido-almost-famous' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'No platforms found. Make sure your API key is connected and valid.', 'bushido-almost-famous' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="af-refresh-platforms">
			<?php
			$almost_famous_refresh_url = wp_nonce_url(
				admin_url( 'admin.php?page=af-settings&af_refresh_platforms=1' ),
				'af_refresh_platforms',
				'af_platforms_nonce'
			);
			?>
			<a href="<?php echo esc_url( $almost_famous_refresh_url ); ?>" class="button button-secondary">
				<span class="dashicons dashicons-update" aria-hidden="true" style="vertical-align: text-bottom;"></span>
				<?php esc_html_e( 'Check for New Platforms', 'bushido-almost-famous' ); ?>
			</a>
		</p>
	</div>

	<hr />

	<!-- General Settings Form -->
	<form method="post" action="options.php">
		<?php
		settings_fields( 'af_settings_group' );
		do_settings_sections( 'af-settings' );
		submit_button();
		?>
	</form>
</div>

<style>
	.af-settings-section {
		background: #fff;
		padding: 20px;
		border: 1px solid #c3c4c7;
		box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
		margin-bottom: 20px;
	}
	.af-platforms-table {
		margin: 16px 0;
	}
	.af-status--connected .dashicons {
		color: #46b450;
	}
	.af-status--degraded .dashicons {
		color: #f0b849;
	}
	.af-status--disconnected .dashicons {
		color: #dc3232;
	}
	.af-refresh-platforms {
		margin-top: 12px;
	}
	.af-refresh-platforms .dashicons {
		margin-right: 4px;
	}
</style>
