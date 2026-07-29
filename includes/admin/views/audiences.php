<?php
/**
 * Audiences template.
 *
 * Renders the saved-audience list, the per-platform audience creation form
 * (matching the backend contract: name, type, platform, credentialId,
 * config), and the lookalike creation panel.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 *
 * @var array $audiences        Saved audiences list.
 * @var array $credentials      Platform slug → credential id map.
 * @var array $platform_choices Platform select choices (value/label/disabled).
 * @var array $type_choices     Audience type value → label map.
 * @var bool  $fetch_failed     Whether the audiences API fetch errored.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$almost_famous_has_any_credential = ! empty( array_filter( $platform_choices, static fn( array $almost_famous_choice ): bool => empty( $almost_famous_choice['disabled'] ) ) );
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Audiences', 'bushido-almost-famous' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( ! empty( $fetch_failed ) ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Could not load audiences. Please check your API connection and try again.', 'bushido-almost-famous' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="af-audiences-layout">

		<div class="af-audiences-main">

			<!-- Saved Audiences List -->
			<?php if ( ! empty( $audiences ) ) : ?>
				<div class="af-panel">
					<h2><?php esc_html_e( 'Saved Audiences', 'bushido-almost-famous' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Type', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Size', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Status', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'bushido-almost-famous' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $audiences as $almost_famous_saved ) : ?>
								<?php
								$almost_famous_saved_id     = (string) ( $almost_famous_saved['id'] ?? '' );
								$almost_famous_saved_name   = (string) ( $almost_famous_saved['name'] ?? __( '(Untitled)', 'bushido-almost-famous' ) );
								$almost_famous_saved_type   = (string) ( $almost_famous_saved['type'] ?? '' );
								$almost_famous_saved_size   = $almost_famous_saved['size'] ?? null;
								$almost_famous_saved_status = (string) ( $almost_famous_saved['status'] ?? '' );
								// Lookalikes need a platform-synced source audience.
								$almost_famous_can_seed = ! empty( $almost_famous_saved['platformAudienceId'] );
								?>
								<tr>
									<td><strong><?php echo esc_html( $almost_famous_saved_name ); ?></strong></td>
									<td><?php echo esc_html( ucfirst( strtolower( (string) ( $almost_famous_saved['platform'] ?? '' ) ) ) ); ?></td>
									<td><?php echo esc_html( $type_choices[ $almost_famous_saved_type ] ?? ucfirst( $almost_famous_saved_type ) ); ?></td>
									<td>
										<?php
										echo esc_html(
											is_numeric( $almost_famous_saved_size )
												? number_format( (float) $almost_famous_saved_size )
												: '—'
										);
										?>
									</td>
									<td><?php echo esc_html( '' === $almost_famous_saved_status ? '—' : ucfirst( $almost_famous_saved_status ) ); ?></td>
									<td>
										<button type="button"
											class="button button-small af-audience-lookalike"
											data-audience-id="<?php echo esc_attr( $almost_famous_saved_id ); ?>"
											data-audience-name="<?php echo esc_attr( $almost_famous_saved_name ); ?>"
											<?php disabled( ! $almost_famous_can_seed ); ?>
											<?php if ( ! $almost_famous_can_seed ) : ?>
												title="<?php esc_attr_e( 'This audience has not synced to its platform yet, so it cannot seed a lookalike.', 'bushido-almost-famous' ); ?>"
											<?php endif; ?>>
											<?php esc_html_e( 'Create Lookalike', 'bushido-almost-famous' ); ?>
										</button>
										<button type="button"
											class="button button-small af-audience-delete"
											data-audience-id="<?php echo esc_attr( $almost_famous_saved_id ); ?>">
											<?php esc_html_e( 'Delete', 'bushido-almost-famous' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<!-- Lookalike Creation (POST /audiences/{id}/lookalike) -->
			<div class="af-panel" id="af-lookalike-panel" hidden>
				<h2><?php esc_html_e( 'Create Lookalike Audience', 'bushido-almost-famous' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Creates a platform lookalike modelled on the selected seed audience:', 'bushido-almost-famous' ); ?>
					<strong id="af-lookalike-source-name"></strong>
				</p>

				<form id="af-lookalike-form">
					<input type="hidden" id="af-lookalike-source-id" value="">

					<div class="af-form-notices" id="af-lookalike-notices"></div>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="af-lookalike-name"><?php esc_html_e( 'Lookalike Name', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input type="text" id="af-lookalike-name" class="regular-text" maxlength="255"
									placeholder="<?php esc_attr_e( 'Defaults to “Lookalike — {source name}”', 'bushido-almost-famous' ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="af-lookalike-ratio"><?php esc_html_e( 'Similarity Ratio (%)', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input type="range" id="af-lookalike-ratio" min="1" max="20" step="1" value="1">
								<span id="af-lookalike-ratio-value">1%</span>
								<p class="description">
									<?php esc_html_e( 'Share of the target country population (1–20%). Meta uses the exact ratio; TikTok maps it to narrow / balanced / broad.', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="af-lookalike-country"><?php esc_html_e( 'Target Country', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input type="text" id="af-lookalike-country" class="small-text" maxlength="2" value="US"
									placeholder="US">
								<p class="description">
									<?php esc_html_e( 'ISO 3166-1 alpha-2 country code (e.g. US, GB, DE).', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p>
						<button type="submit" class="button button-primary" id="af-lookalike-submit">
							<?php esc_html_e( 'Create Lookalike', 'bushido-almost-famous' ); ?>
						</button>
						<button type="button" class="button" id="af-lookalike-cancel">
							<?php esc_html_e( 'Cancel', 'bushido-almost-famous' ); ?>
						</button>
					</p>
				</form>
			</div>

			<!-- Audience Creation Form (POST /audiences) -->
			<?php // Audiences are immutable on the backend (create/delete only) — the form always creates. ?>
			<form id="af-audience-form" class="af-audience-form"
				data-api-endpoint="/audiences"
				data-api-method="POST">

				<?php wp_nonce_field( 'af_audience_save', 'af_audience_nonce' ); ?>

				<div class="af-form-notices" id="af-audience-notices"></div>

				<div class="af-panel">
					<h2><?php esc_html_e( 'Create Audience', 'bushido-almost-famous' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Creates a custom audience on the selected ad platform using the connected credential for that platform.', 'bushido-almost-famous' ); ?>
					</p>

					<?php if ( ! $almost_famous_has_any_credential ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<?php esc_html_e( 'No platform credentials are connected yet, so audiences cannot be created. Connect a platform first.', 'bushido-almost-famous' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-accounts' ) ); ?>">
									<?php esc_html_e( 'Open Accounts', 'bushido-almost-famous' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="af-audience-name"><?php esc_html_e( 'Audience Name', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input type="text" id="af-audience-name" name="name" class="regular-text"
									required maxlength="255"
									placeholder="<?php esc_attr_e( 'e.g., Newsletter Subscribers', 'bushido-almost-famous' ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="af-audience-platform"><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<select id="af-audience-platform" name="platform" class="regular-text" required>
									<option value=""><?php esc_html_e( '— Select platform —', 'bushido-almost-famous' ); ?></option>
									<?php foreach ( $platform_choices as $almost_famous_choice ) : ?>
										<option value="<?php echo esc_attr( $almost_famous_choice['value'] ); ?>" <?php disabled( ! empty( $almost_famous_choice['disabled'] ) ); ?>>
											<?php
											echo esc_html( $almost_famous_choice['label'] );
											if ( ! empty( $almost_famous_choice['disabled'] ) ) {
												echo ' — ' . esc_html__( 'no credential connected', 'bushido-almost-famous' );
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Platforms without a connected credential are disabled — the audience is created directly on the platform account.', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="af-audience-type"><?php esc_html_e( 'Audience Type', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<select id="af-audience-type" name="type" class="regular-text" required>
									<?php foreach ( $type_choices as $almost_famous_type_value => $almost_famous_type_label ) : ?>
										<option value="<?php echo esc_attr( $almost_famous_type_value ); ?>">
											<?php echo esc_html( $almost_famous_type_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Customer lists start empty; upload hashed customer data from the platform dashboard after creation.', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
						<?php // Google Ads user lists are the only create path that reads config fields (description + membershipLifeSpan). ?>
						<tr class="af-audience-google-only" hidden>
							<th scope="row">
								<label for="af-audience-description"><?php esc_html_e( 'Description', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input type="text" id="af-audience-description" name="config_description" class="regular-text"
									placeholder="<?php esc_attr_e( 'Optional Google user-list description', 'bushido-almost-famous' ); ?>">
							</td>
						</tr>
						<tr class="af-audience-google-only" hidden>
							<th scope="row">
								<label for="af-audience-membership"><?php esc_html_e( 'Membership Lifespan (days)', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input type="number" id="af-audience-membership" name="config_membership_life_span"
									class="small-text" min="0" max="540" placeholder="30">
								<p class="description">
									<?php esc_html_e( 'How long a user stays in the Google user list. Defaults to 30 days.', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p class="description">
						<?php esc_html_e( 'Detailed targeting — countries, age ranges, and interests — is configured per campaign in the campaign console, where the platforms actually apply it.', 'bushido-almost-famous' ); ?>
					</p>
				</div>

				<div class="af-form-actions">
					<button type="submit" class="button button-primary button-hero" id="af-audience-submit"
						<?php disabled( ! $almost_famous_has_any_credential ); ?>>
						<?php esc_html_e( 'Create Audience', 'bushido-almost-famous' ); ?>
					</button>
				</div>
			</form>
		</div>

	</div>
</div>
