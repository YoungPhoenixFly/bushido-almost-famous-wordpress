<?php
/**
 * Creatives template.
 *
 * Renders the source asset upload form, the uploaded asset list, and the
 * per-platform sync status reported by the backend (with async polling
 * while an asset is still processing).
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 *
 * @var array  $creatives   List of all creatives.
 * @var string $creative_id Currently selected creative ID.
 * @var array  $creative    Current creative data (if viewing one).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Transient notices.
$almost_famous_success_msg = get_transient( 'af_creative_success' );
$almost_famous_error_msg   = get_transient( 'af_creative_error' );

if ( $almost_famous_success_msg ) {
	delete_transient( 'af_creative_success' );
}
if ( $almost_famous_error_msg ) {
	delete_transient( 'af_creative_error' );
}

$almost_famous_viewing_creative       = ! empty( $creative );
$almost_famous_creative_status        = $creative['status'] ?? '';
$almost_famous_creative_name          = $creative['name'] ?? '';
$almost_famous_creative_source_url    = $creative['source_url'] ?? '';
$almost_famous_creative_platform_rows = $creative['formats'] ?? array();
$almost_famous_is_processing          = 'processing' === $almost_famous_creative_status;
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Creative Assets', 'bushido-almost-famous' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( $almost_famous_success_msg ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $almost_famous_success_msg ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $almost_famous_error_msg ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $almost_famous_error_msg ); ?></p>
		</div>
	<?php endif; ?>

	<div class="af-creatives-layout">

		<!-- Upload Form (Story 4.3) -->
		<div class="af-panel">
			<h2><?php esc_html_e( 'Upload Source Asset', 'bushido-almost-famous' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload an image or video to your Bushido asset library. The file is stored once and synced to each connected ad platform.', 'bushido-almost-famous' ); ?>
			</p>

			<form method="post" enctype="multipart/form-data" class="af-upload-form">
				<?php wp_nonce_field( 'af_creative_upload', 'af_creative_upload_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="af-creative-name"><?php esc_html_e( 'Creative Name', 'bushido-almost-famous' ); ?></label>
						</th>
						<td>
							<input type="text" id="af-creative-name" name="af_creative_name" class="regular-text"
								required placeholder="<?php esc_attr_e( 'e.g., Summer EP Cover Art', 'bushido-almost-famous' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Source Asset', 'bushido-almost-famous' ); ?></label>
						</th>
						<td>
							<div class="af-upload-methods">
								<!-- Media Library Selection -->
								<div class="af-upload-method">
									<input type="hidden" name="af_source_attachment_id" id="af-source-attachment-id" value="">
									<button type="button" class="button" id="af-select-media">
										<span class="dashicons dashicons-admin-media" aria-hidden="true" style="vertical-align: text-bottom;"></span>
										<?php esc_html_e( 'Choose from Media Library', 'bushido-almost-famous' ); ?>
									</button>
									<span id="af-selected-media-name" class="description"></span>
								</div>

								<p class="af-upload-or"><strong><?php esc_html_e( '-- or --', 'bushido-almost-famous' ); ?></strong></p>

								<!-- Direct Upload -->
								<div class="af-upload-method">
									<input type="file" name="af_source_asset" id="af-source-asset"
										accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime">
									<p class="description">
										<?php esc_html_e( 'Accepted: JPG, PNG, GIF, WebP, MP4, MOV. Max size determined by server settings.', 'bushido-almost-famous' ); ?>
									</p>
								</div>
							</div>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Upload Asset', 'bushido-almost-famous' ), 'primary', 'af_upload_submit' ); ?>
			</form>
		</div>

		<!-- Existing Creatives Grid -->
		<?php if ( ! empty( $creatives ) ) : ?>
			<div class="af-panel">
				<h2><?php esc_html_e( 'All Creatives', 'bushido-almost-famous' ); ?></h2>

				<div class="af-creatives-grid">
					<?php foreach ( $creatives as $almost_famous_item ) : ?>
						<?php
						$almost_famous_item_id     = $almost_famous_item['id'] ?? '';
						$almost_famous_item_name   = $almost_famous_item['name'] ?? __( '(Untitled)', 'bushido-almost-famous' );
						$almost_famous_item_status = $almost_famous_item['status'] ?? 'unknown';
						$almost_famous_item_thumb  = $almost_famous_item['thumbnail_url'] ?? $almost_famous_item['source_url'] ?? '';
						$almost_famous_is_current  = $almost_famous_item_id === $creative_id;
						?>
						<div class="af-creative-card <?php echo $almost_famous_is_current ? 'af-creative-card--active' : ''; ?>"
							data-af-creative-id="<?php echo esc_attr( $almost_famous_item_id ); ?>"
							data-af-creative-status="<?php echo esc_attr( $almost_famous_item_status ); ?>">
							<?php if ( ! empty( $almost_famous_item_thumb ) ) : ?>
								<div class="af-creative-card__thumb">
									<img src="<?php echo esc_url( $almost_famous_item_thumb ); ?>" alt="<?php echo esc_attr( $almost_famous_item_name ); ?>">
								</div>
							<?php else : ?>
								<div class="af-creative-card__thumb af-creative-card__thumb--placeholder">
									<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
								</div>
							<?php endif; ?>

							<div class="af-creative-card__info">
								<strong><?php echo esc_html( $almost_famous_item_name ); ?></strong>
								<span class="af-badge af-badge--<?php echo esc_attr( $almost_famous_item_status ); ?>" data-af-creative-badge>
									<?php echo esc_html( ucfirst( $almost_famous_item_status ) ); ?>
								</span>
							</div>

							<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-creatives&creative_id=' . $almost_famous_item_id ) ); ?>" class="af-creative-card__link">
								<?php esc_html_e( 'View Details', 'bushido-almost-famous' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<!-- Single Creative Detail View -->
		<?php if ( $almost_famous_viewing_creative ) : ?>

			<div class="af-panel af-panel--creative-detail">
				<h2>
					<?php echo esc_html( $almost_famous_creative_name ); ?>
					<?php if ( ! empty( $almost_famous_creative_status ) ) : ?>
						<span class="af-badge af-badge--<?php echo esc_attr( $almost_famous_creative_status ); ?>">
							<?php echo esc_html( ucfirst( $almost_famous_creative_status ) ); ?>
						</span>
					<?php endif; ?>
				</h2>

				<!-- Source Asset Preview -->
				<?php if ( ! empty( $almost_famous_creative_source_url ) ) : ?>
					<div class="af-source-preview">
						<h3><?php esc_html_e( 'Source Asset', 'bushido-almost-famous' ); ?></h3>
						<?php
						$almost_famous_is_video = preg_match( '/\.(mp4|mov|webm)$/i', $almost_famous_creative_source_url );
						if ( $almost_famous_is_video ) :
							?>
							<video src="<?php echo esc_url( $almost_famous_creative_source_url ); ?>" controls class="af-source-video" style="max-width:400px;"></video>
						<?php else : ?>
							<img src="<?php echo esc_url( $almost_famous_creative_source_url ); ?>" alt="<?php echo esc_attr( $almost_famous_creative_name ); ?>" class="af-source-image" style="max-width:400px;">
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- Processing Status (Story 4.3 - Async Polling) -->
				<?php if ( $almost_famous_is_processing ) : ?>
					<div class="af-processing-status" id="af-processing-status"
						data-creative-id="<?php echo esc_attr( $creative_id ); ?>"
						data-poll-interval="5000">
						<div class="af-processing-spinner">
							<span class="spinner is-active"></span>
							<p><?php esc_html_e( 'The asset is still being processed. This page will update automatically when it is ready.', 'bushido-almost-famous' ); ?></p>
						</div>
						<div class="af-processing-progress" id="af-processing-progress">
							<!-- Updated by admin.js polling -->
						</div>
					</div>
				<?php endif; ?>

				<!-- Per-Platform Sync Status -->
				<?php if ( ! empty( $almost_famous_creative_platform_rows ) ) : ?>
					<h3><?php esc_html_e( 'Platform Sync Status', 'bushido-almost-famous' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Upload status reported by each connected ad platform for this asset.', 'bushido-almost-famous' ); ?>
					</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Status', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Uploaded', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Error', 'bushido-almost-famous' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $almost_famous_creative_platform_rows as $almost_famous_platform_row ) : ?>
								<?php
								$almost_famous_row_platform = (string) ( $almost_famous_platform_row['platform'] ?? '' );
								$almost_famous_row_status   = (string) ( $almost_famous_platform_row['status'] ?? 'pending' );
								$almost_famous_row_uploaded = (string) ( $almost_famous_platform_row['uploadedAt'] ?? '' );
								$almost_famous_row_error    = (string) ( $almost_famous_platform_row['error'] ?? '' );
								?>
								<tr>
									<td><strong><?php echo esc_html( ucfirst( $almost_famous_row_platform ) ); ?></strong></td>
									<td>
										<span class="af-badge af-badge--<?php echo esc_attr( $almost_famous_row_status ); ?>">
											<?php echo esc_html( ucfirst( $almost_famous_row_status ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( '' === $almost_famous_row_uploaded ? '—' : $almost_famous_row_uploaded ); ?></td>
									<td><?php echo esc_html( '' === $almost_famous_row_error ? '—' : $almost_famous_row_error ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

		<?php endif; ?>
	</div>
</div>
