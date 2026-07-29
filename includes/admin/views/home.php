<?php
/**
 * Bushido Almost Famous Home hub template.
 *
 * A static connect + console surface. When the site is not connected it shows a
 * guided "Connect your Bushido account" card; once connected it links to the
 * front-end campaign console and the config tools.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 * @var \AlmostFamous\Admin\Dashboard $hub             Hub controller instance.
 * @var bool                          $is_connected    Whether the account is connected.
 * @var bool                          $degraded        Whether any platform is degraded.
 * @var string                        $credential_mode Org credential mode (agency|byo).
 * @var string                        $console_url     Console page permalink, or ''.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$almost_famous_is_agency  = 'agency' === $credential_mode;
$almost_famous_signup_url = add_query_arg(
	array(
		'utm_source' => 'wordpress-plugin',
		'utm_medium' => 'hub',
	),
	trailingslashit( \AlmostFamous\Config::resolve_bushido_app_url() ) . 'signup'
);
?>
<div class="wrap af-hub">
	<h1><?php esc_html_e( 'Bushido Almost Famous', 'bushido-almost-famous' ); ?></h1>

	<?php if ( $degraded ) : ?>
		<div class="notice notice-warning" role="alert">
			<p>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'Data may be outdated — one or more platforms are experiencing issues.', 'bushido-almost-famous' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $is_connected ) : ?>

		<div class="card">
			<h2><?php esc_html_e( 'Connect your Bushido account', 'bushido-almost-famous' ); ?></h2>
			<p>
				<?php esc_html_e( 'Bushido Almost Famous runs your multi-platform ad campaigns through a Bushido account. Connect one to launch, pay for, and track campaigns from this site.', 'bushido-almost-famous' ); ?>
			</p>
			<p>
				<a
					class="button button-primary button-hero"
					href="<?php echo esc_url( admin_url( 'admin.php?page=af-setup-wizard' ) ); ?>"
				>
					<?php esc_html_e( 'Connect your Bushido account', 'bushido-almost-famous' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( "Don't have one?", 'bushido-almost-famous' ); ?>
				<a
					href="<?php echo esc_url( $almost_famous_signup_url ); ?>"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Sign up', 'bushido-almost-famous' ); ?>
				</a>
			</p>
		</div>

	<?php else : ?>

		<div class="card">
			<h2><?php esc_html_e( 'Connected', 'bushido-almost-famous' ); ?></h2>
			<p>
				<?php if ( $almost_famous_is_agency ) : ?>
					<?php esc_html_e( 'Your campaigns run on Bushido-managed ad accounts (agency mode).', 'bushido-almost-famous' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Your campaigns run on your own connected ad accounts.', 'bushido-almost-famous' ); ?>
				<?php endif; ?>
			</p>

			<?php if ( '' !== $console_url ) : ?>
				<p>
					<a class="button button-primary button-hero" href="<?php echo esc_url( $console_url ); ?>">
						<?php esc_html_e( 'Open campaign console', 'bushido-almost-famous' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Create a page for the campaign console so you and your team can browse, launch, and track campaigns on the front end.', 'bushido-almost-famous' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( $hub->get_create_console_action_url() ); ?>">
					<?php wp_nonce_field( $hub->get_create_console_nonce_action(), 'af_console_nonce' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Create console page', 'bushido-almost-famous' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>

		<div class="card">
			<h2><?php esc_html_e( 'Configure', 'bushido-almost-famous' ); ?></h2>
			<div class="af-hub-links">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-audiences' ) ); ?>">
					<?php esc_html_e( 'Audiences', 'bushido-almost-famous' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-creatives' ) ); ?>">
					<?php esc_html_e( 'Creatives', 'bushido-almost-famous' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-pixels' ) ); ?>">
					<?php esc_html_e( 'Conversions', 'bushido-almost-famous' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-accounts' ) ); ?>">
					<?php esc_html_e( 'Connections', 'bushido-almost-famous' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-settings' ) ); ?>">
					<?php esc_html_e( 'Settings', 'bushido-almost-famous' ); ?>
				</a>
			</div>
		</div>

	<?php endif; ?>
</div>
