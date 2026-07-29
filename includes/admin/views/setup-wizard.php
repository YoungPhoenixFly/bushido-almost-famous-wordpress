<?php
/**
 * Setup wizard template.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 *
 * @var \AlmostFamous\Admin\Setup_Wizard $wizard Wizard instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$almost_famous_current_step  = $wizard->get_current_step();
$almost_famous_error_message = $wizard->get_error_message();
$almost_famous_nonce_action  = 'af_setup_wizard_nonce';
$almost_famous_app_base_url  = \AlmostFamous\Config::resolve_bushido_app_url();
$almost_famous_signup_url    = add_query_arg(
	array(
		'utm_source' => 'wordpress-plugin',
		'utm_medium' => 'setup-wizard',
	),
	trailingslashit( $almost_famous_app_base_url ) . 'signup'
);
$almost_famous_api_keys_url  = add_query_arg(
	array(
		'tab'        => 'apikeys',
		'utm_source' => 'wordpress-plugin',
		'utm_medium' => 'setup-wizard',
	),
	trailingslashit( $almost_famous_app_base_url ) . 'almost-famous/settings'
);
?>
<div class="wrap af-setup-wizard">
	<h1><?php esc_html_e( 'Bushido Almost Famous Setup', 'bushido-almost-famous' ); ?></h1>

	<div class="af-wizard-steps">
		<ol class="af-wizard-steps__nav">
			<li class="<?php echo $almost_famous_current_step >= 1 ? 'active' : ''; ?> <?php echo $almost_famous_current_step > 1 ? 'complete' : ''; ?>"<?php echo 1 === $almost_famous_current_step ? ' aria-current="step"' : ''; ?>>
				<?php if ( $almost_famous_current_step > 1 ) : ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Completed: ', 'bushido-almost-famous' ); ?></span>
				<?php endif; ?>
				<?php esc_html_e( '1. SSL Check', 'bushido-almost-famous' ); ?>
			</li>
			<li class="<?php echo $almost_famous_current_step >= 2 ? 'active' : ''; ?> <?php echo $almost_famous_current_step > 2 ? 'complete' : ''; ?>"<?php echo 2 === $almost_famous_current_step ? ' aria-current="step"' : ''; ?>>
				<?php if ( $almost_famous_current_step > 2 ) : ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Completed: ', 'bushido-almost-famous' ); ?></span>
				<?php endif; ?>
				<?php esc_html_e( '2. API Connection', 'bushido-almost-famous' ); ?>
			</li>
			<li class="<?php echo 3 === $almost_famous_current_step ? 'active' : ''; ?>"<?php echo 3 === $almost_famous_current_step ? ' aria-current="step"' : ''; ?>>
				<?php esc_html_e( '3. Confirmation', 'bushido-almost-famous' ); ?>
			</li>
		</ol>
	</div>

	<?php if ( ! empty( $almost_famous_error_message ) ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $almost_famous_error_message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 1 === $almost_famous_current_step ) : ?>
		<!-- Step 1: SSL Check -->
		<div class="af-wizard-step" id="af-step-ssl">
			<h2><?php esc_html_e( 'Security Check', 'bushido-almost-famous' ); ?></h2>
			<p><?php esc_html_e( 'Bushido Almost Famous requires a secure HTTPS connection to communicate with the Bushido API.', 'bushido-almost-famous' ); ?></p>

			<?php if ( $wizard->passes_ssl_check() ) : ?>
				<div class="notice notice-success inline">
					<p>
						<span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color: #46b450;"></span>
						<?php esc_html_e( 'SSL is active. Your connection is secure.', 'bushido-almost-famous' ); ?>
					</p>
				</div>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-setup-wizard&step=2' ) ); ?>" class="button button-primary button-hero">
						<?php esc_html_e( 'Continue to API Setup', 'bushido-almost-famous' ); ?>
					</a>
				</p>
			<?php else : ?>
				<div class="notice notice-error inline">
					<p>
						<span class="dashicons dashicons-warning" aria-hidden="true" style="color: #dc3232;"></span>
						<?php esc_html_e( 'SSL is not active on this site.', 'bushido-almost-famous' ); ?>
					</p>
				</div>
				<div class="af-ssl-instructions">
					<h3><?php esc_html_e( 'How to enable SSL:', 'bushido-almost-famous' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Obtain an SSL certificate from your hosting provider or a service like Let\'s Encrypt.', 'bushido-almost-famous' ); ?></li>
						<li><?php esc_html_e( 'Install and activate the certificate on your server.', 'bushido-almost-famous' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: wp-config.php */
								esc_html__( 'Update your WordPress Address and Site Address in Settings > General to use %s.', 'bushido-almost-famous' ),
								'<code>https://</code>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Return to this page once SSL is configured.', 'bushido-almost-famous' ); ?></li>
					</ol>
				</div>
			<?php endif; ?>
		</div>

	<?php elseif ( 2 === $almost_famous_current_step ) : ?>
		<!-- Step 2: Connect (primary) + paste-key fallback -->
		<div class="af-wizard-step" id="af-step-api-key">
			<h2><?php esc_html_e( 'Connect to Bushido', 'bushido-almost-famous' ); ?></h2>

			<div class="af-connect-primary">
				<p class="af-connect-blurb">
					<?php esc_html_e( 'One click links this WordPress site to your Bushido channel. We mint a per-site API key for you — no copy-paste, no key juggling.', 'bushido-almost-famous' ); ?>
				</p>
				<p>
					<?php
					$almost_famous_connect_url = add_query_arg(
						'_wpnonce',
						wp_create_nonce( 'wp_rest' ),
						rest_url( 'almost-famous/v1/wp-connect/start' )
					);
					?>
					<a
						class="button button-primary button-hero"
						href="<?php echo esc_url( $almost_famous_connect_url ); ?>"
					>
						<?php esc_html_e( 'Connect this site to Bushido', 'bushido-almost-famous' ); ?>
					</a>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: Sign-up link to the Bushido staging app. */
						esc_html__( 'Don\'t have a Bushido account yet? %s.', 'bushido-almost-famous' ),
						'<a href="' . esc_url( $almost_famous_signup_url ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'Sign up — it takes a minute', 'bushido-almost-famous' ) . '</strong></a>'
					);
					?>
				</p>
			</div>

			<details class="af-connect-fallback">
				<summary><?php esc_html_e( 'I already have an API key', 'bushido-almost-famous' ); ?></summary>
				<p class="description">
					<?php
					printf(
						/* translators: %s: Link to the Bushido Almost Famous API keys page. */
						esc_html__( 'Paste your existing key below. You can manage API keys at %s.', 'bushido-almost-famous' ),
						'<a href="' . esc_url( $almost_famous_api_keys_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Bushido Almost Famous → API Keys', 'bushido-almost-famous' ) . '</a>'
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<?php wp_nonce_field( $almost_famous_nonce_action, 'af_wizard_nonce' ); ?>
					<input type="hidden" name="af_wizard_step" value="2" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="af_api_key"><?php esc_html_e( 'API Key', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									name="af_api_key"
									id="af_api_key"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'af_…', 'bushido-almost-famous' ); ?>"
									autocomplete="off"
								/>
								<p class="description">
									<?php esc_html_e( 'Your API key is encrypted before storage.', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save & Verify', 'bushido-almost-famous' ), 'secondary', 'af_submit_api_key' ); ?>
				</form>
			</details>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-setup-wizard&step=1' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to SSL Check', 'bushido-almost-famous' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( 3 === $almost_famous_current_step ) : ?>
		<!-- Step 3: Confirmation -->
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display, no DB writes.
		$almost_famous_setup_outcome = isset( $_GET['af_setup'] ) ? sanitize_key( wp_unslash( $_GET['af_setup'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$almost_famous_setup_error_code = isset( $_GET['af_setup_error'] ) ? sanitize_key( wp_unslash( $_GET['af_setup_error'] ) ) : '';
		$almost_famous_credential_mode  = (string) get_option( 'af_org_credential_mode', 'agency' );
		$almost_famous_channel_name     = (string) get_option( 'af_org_channel_name', '' );
		?>
		<div class="af-wizard-step" id="af-step-confirmation">
			<?php if ( 'error' === $almost_famous_setup_outcome || 'cancelled' === $almost_famous_setup_outcome ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: error code from the wp-connect handshake. */
							esc_html__( 'The connection could not be completed (%s). You can try again from step 2.', 'bushido-almost-famous' ),
							esc_html( $almost_famous_setup_error_code )
						);
						?>
					</p>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-setup-wizard&step=2' ) ); ?>">
						&larr; <?php esc_html_e( 'Back to step 2', 'bushido-almost-famous' ); ?>
					</a>
				</p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Connection Successful', 'bushido-almost-famous' ); ?></h2>

				<?php if ( '' !== $almost_famous_channel_name ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: Bushido channel name. */
							esc_html__( 'Connected to Bushido channel "%s".', 'bushido-almost-famous' ),
							esc_html( $almost_famous_channel_name )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( 'agency' === $almost_famous_credential_mode ) : ?>
					<div class="notice notice-success inline">
						<p>
							<span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color: #46b450;"></span>
							<?php esc_html_e( "You're running on Bushido's shared ad accounts. You can create your first campaign right away — no per-platform OAuth needed.", 'bushido-almost-famous' ); ?>
						</p>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'Your organization is set up to use your own platform accounts. Connect the platforms you want to advertise on next.', 'bushido-almost-famous' ); ?></p>
					<?php $almost_famous_platforms = $wizard->get_connected_platforms(); ?>
					<?php if ( ! empty( $almost_famous_platforms ) ) : ?>
						<table class="widefat striped af-platforms-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></th>
									<th><?php esc_html_e( 'Status', 'bushido-almost-famous' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $almost_famous_platforms as $almost_famous_platform ) : ?>
									<?php
									$almost_famous_platform_name   = isset( $almost_famous_platform['name'] ) ? $almost_famous_platform['name'] : __( 'Unknown', 'bushido-almost-famous' );
									$almost_famous_platform_status = isset( $almost_famous_platform['status'] ) ? $almost_famous_platform['status'] : 'unknown';
									$almost_famous_is_ok           = in_array( $almost_famous_platform_status, array( 'connected', 'active', 'ok' ), true );
									?>
									<tr>
										<td><?php echo esc_html( $almost_famous_platform_name ); ?></td>
										<td>
											<?php if ( $almost_famous_is_ok ) : ?>
												<span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color: #46b450;"></span>
												<?php echo esc_html( ucfirst( $almost_famous_platform_status ) ); ?>
											<?php else : ?>
												<span class="dashicons dashicons-warning" aria-hidden="true" style="color: #f0b849;"></span>
												<?php echo esc_html( ucfirst( $almost_famous_platform_status ) ); ?>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<?php wp_nonce_field( $almost_famous_nonce_action, 'af_wizard_nonce' ); ?>
					<input type="hidden" name="af_wizard_step" value="3" />

					<?php submit_button( __( 'Go to Dashboard', 'bushido-almost-famous' ), 'primary', 'af_complete_setup' ); ?>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

<style>
	.af-setup-wizard {
		max-width: 800px;
		margin: 20px auto;
	}
	.af-wizard-steps__nav {
		display: flex;
		list-style: none;
		padding: 0;
		margin: 20px 0 30px;
		border-bottom: 2px solid #e0e0e0;
	}
	.af-wizard-steps__nav li {
		flex: 1;
		padding: 12px 16px;
		text-align: center;
		font-weight: 600;
		color: #999;
		border-bottom: 3px solid transparent;
		margin-bottom: -2px;
	}
	.af-wizard-steps__nav li.active {
		color: #1d2327;
		border-bottom-color: #2271b1;
	}
	.af-wizard-steps__nav li.complete {
		color: #46b450;
	}
	.af-wizard-step {
		background: #fff;
		padding: 24px;
		border: 1px solid #c3c4c7;
		box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
	}
	.af-platforms-table {
		margin: 16px 0;
	}
	.af-ssl-instructions {
		background: #f9f9f9;
		padding: 16px;
		border-left: 4px solid #2271b1;
		margin: 16px 0;
	}
	.af-connect-primary {
		padding: 24px;
		background: #f6f7f7;
		border: 1px solid #c3c4c7;
		border-left: 4px solid #5865f2;
		margin-bottom: 24px;
	}
	.af-connect-blurb {
		font-size: 14px;
		margin-top: 0;
	}
	.af-connect-fallback {
		margin-top: 16px;
		padding: 16px;
		border: 1px solid #dcdcde;
		background: #fff;
	}
	.af-connect-fallback summary {
		cursor: pointer;
		font-weight: 600;
	}
</style>
