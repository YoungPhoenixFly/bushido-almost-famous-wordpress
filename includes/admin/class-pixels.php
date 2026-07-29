<?php
/**
 * Pixels admin page — list pixels and pin a default for conversion events.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Cache;
use AlmostFamous\Api\Api_Client;

/**
 * Surfaces /pixels inside wp-admin so the site owner can see which
 * pixel the plugin will fire CAPI events against.
 *
 * Full pixel CRUD lives on the Bushido dashboard — this page is a
 * read-only mirror plus a "Set as default" toggle so Conversion_Tracking
 * has a stable target.
 */
class Pixels {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'af-pixels';

	/**
	 * Nonce action for the set-default form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'af_pixels_nonce';

	/**
	 * API client instance.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * API cache instance.
	 *
	 * @var Api_Cache
	 */
	private Api_Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client API client.
	 * @param Api_Cache  $cache  API cache.
	 */
	public function __construct( Api_Client $client, Api_Cache $cache ) {
		$this->client = $client;
		$this->cache  = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'handle_set_default' ) );
	}

	/**
	 * Register the pixels submenu page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'bushido-almost-famous',
			__( 'Pixels', 'bushido-almost-famous' ),
			__( 'Pixels', 'bushido-almost-famous' ),
			'af_view_campaigns',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the "Set as default pixel" form submission.
	 *
	 * @return void
	 */
	public function handle_set_default(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['af_set_default_pixel'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}
		check_admin_referer( self::NONCE_ACTION, 'af_pixels_nonce' );

		$pixel_id = sanitize_text_field( wp_unslash( $_POST['af_default_pixel_id'] ?? '' ) );
		update_option( 'af_default_pixel_id', $pixel_id );
		delete_transient( 'af_default_pixel_id_cache' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&af_pinned=1' ) );
		exit;
	}

	/**
	 * Render the pixels page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'af_view_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pinned_notice = ! empty( $_GET['af_pinned'] );

		$pixels  = $this->client->get_pixels();
		$default = (string) get_option( 'af_default_pixel_id', '' );
		?>
		<div class="wrap af-pixels">
			<h1><?php esc_html_e( 'Pixels', 'bushido-almost-famous' ); ?></h1>

			<?php if ( $pinned_notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Default pixel updated.', 'bushido-almost-famous' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'The plugin fires server-side conversion events against your default Meta pixel. Manage pixel CRUD from your Bushido dashboard; this page lets you pin which one the plugin should use.', 'bushido-almost-famous' ); ?>
			</p>

			<?php if ( empty( $pixels ) ) : ?>
				<p><?php esc_html_e( 'No pixels configured yet. Create one from the Bushido dashboard.', 'bushido-almost-famous' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, 'af_pixels_nonce' ); ?>
					<input type="hidden" name="af_set_default_pixel" value="1" />

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Default', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Name', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Pixel ID', 'bushido-almost-famous' ); ?></th>
								<th><?php esc_html_e( 'Status', 'bushido-almost-famous' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pixels as $pixel ) : ?>
								<?php
								$id          = isset( $pixel['id'] ) ? (string) $pixel['id'] : '';
								$name        = isset( $pixel['name'] ) ? (string) $pixel['name'] : '';
								$platform    = isset( $pixel['platform'] ) ? (string) $pixel['platform'] : '';
								$platform_id = isset( $pixel['pixelId'] ) ? (string) $pixel['pixelId'] : '';
								$status      = isset( $pixel['status'] ) ? (string) $pixel['status'] : 'unknown';
								?>
								<tr>
									<td>
										<input
											type="radio"
											name="af_default_pixel_id"
											value="<?php echo esc_attr( $id ); ?>"
											<?php checked( $id, $default ); ?>
										/>
									</td>
									<td><?php echo esc_html( $name ); ?></td>
									<td><?php echo esc_html( ucfirst( strtolower( $platform ) ) ); ?></td>
									<td><code><?php echo esc_html( $platform_id ); ?></code></td>
									<td><?php echo esc_html( $status ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php submit_button( __( 'Save default', 'bushido-almost-famous' ), 'primary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
