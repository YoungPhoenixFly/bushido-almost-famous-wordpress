<?php
/**
 * WordPress Multisite network-level settings and API key management.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Multisite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Auth;

/**
 * WordPress Multisite network-level settings and API key management.
 *
 * Registers a network admin settings page that stores a global API key
 * in site options (accessible across all sites in the network). Only
 * loads when is_multisite() returns true.
 */
class Network_Admin {

	/**
	 * Network admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'af-network-settings';

	/**
	 * Site option key for the encrypted network API key.
	 *
	 * @var string
	 */
	public const NETWORK_API_KEY_OPTION = 'af_network_api_key';

	/**
	 * Nonce action for network settings form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'af_network_settings_nonce';

	/**
	 * API authentication handler.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * Constructor.
	 *
	 * @param Api_Auth $auth API auth handler.
	 */
	public function __construct( Api_Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Register hooks. Only call this when is_multisite() is true.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', array( $this, 'register_network_page' ) );
		add_action( 'network_admin_edit_af_network_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Register the network admin settings page.
	 *
	 * @return void
	 */
	public function register_network_page(): void {
		add_submenu_page(
			'settings.php',
			__( 'Bushido Almost Famous Network Settings', 'bushido-almost-famous' ),
			__( 'Bushido Almost Famous', 'bushido-almost-famous' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle saving network settings.
	 *
	 * Hooked to 'network_admin_edit_af_network_settings' action.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		check_admin_referer( self::NONCE_ACTION, 'af_network_nonce' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		// Handle API key — use trim + pattern validation instead of sanitize_text_field
		// which strips +/= characters valid in API keys.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; the key is pattern-validated below (sanitize_text_field would strip characters valid in API keys) and stored encrypted.
		$api_key = isset( $_POST['af_network_api_key'] ) ? trim( wp_unslash( $_POST['af_network_api_key'] ) ) : '';

		if ( ! empty( $api_key ) && ! preg_match( '/^[a-zA-Z0-9_\-\+\/\=\.]+$/', $api_key ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => self::PAGE_SLUG,
						'error' => 'invalid_key_format',
					),
					network_admin_url( 'settings.php' )
				)
			);
			exit;
		}

		if ( ! empty( $api_key ) ) {
			if ( ! $this->store_network_api_key( $api_key ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'  => self::PAGE_SLUG,
							'error' => 'key_storage_failed',
						),
						network_admin_url( 'settings.php' )
					)
				);
				exit;
			}
		}

		// Handle per-site override setting.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified above.
		$allow_site_override = isset( $_POST['af_allow_site_override'] ) ? '1' : '0';
		update_site_option( 'af_allow_site_override', $allow_site_override );

		// Redirect back to the settings page with a success message.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Encrypt and durably verify a network key without destroying the previous
	 * working credential on encryption or persistence failure.
	 *
	 * @param string $api_key Plaintext API key.
	 * @return bool True only when the exact key can be read back.
	 */
	private function store_network_api_key( string $api_key ): bool {
		$previous  = get_site_option( self::NETWORK_API_KEY_OPTION, null );
		$encrypted = $this->auth->encrypt_api_key( $api_key );
		if ( '' === $encrypted ) {
			return false;
		}

		update_site_option( self::NETWORK_API_KEY_OPTION, $encrypted );
		$stored = get_site_option( self::NETWORK_API_KEY_OPTION, '' );
		if (
			is_string( $stored )
			&& hash_equals( $encrypted, $stored )
			&& hash_equals( $api_key, $this->auth->decrypt( $stored ) )
		) {
			return true;
		}

		if ( null === $previous ) {
			delete_site_option( self::NETWORK_API_KEY_OPTION );
		} else {
			update_site_option( self::NETWORK_API_KEY_OPTION, $previous );
		}
		return false;
	}

	/**
	 * Check if a network API key is stored.
	 *
	 * @return bool True if a non-empty network API key exists.
	 */
	public function has_network_api_key(): bool {
		return ! empty( get_site_option( self::NETWORK_API_KEY_OPTION, '' ) );
	}

	/**
	 * Get the decrypted network API key.
	 *
	 * @return string Decrypted API key or empty string if not set.
	 */
	public function get_network_api_key(): string {
		$stored = get_site_option( self::NETWORK_API_KEY_OPTION, '' );

		if ( empty( $stored ) ) {
			return '';
		}

		$plaintext = $this->auth->decrypt( $stored );

		// Network keys share the same legacy CBC format as site keys. Migrate
		// readable values only after the authenticated replacement can be read
		// back. Restore the working legacy ciphertext after any failed write.
		if ( '' !== $plaintext && ! str_starts_with( $stored, 'v2:' ) ) {
			$migrated = $this->auth->encrypt_api_key( $plaintext );
			if ( '' !== $migrated ) {
				update_site_option( self::NETWORK_API_KEY_OPTION, $migrated );
				$persisted = (string) get_site_option( self::NETWORK_API_KEY_OPTION, '' );
				if (
					! hash_equals( $migrated, $persisted )
					|| ! hash_equals( $plaintext, $this->auth->decrypt( $persisted ) )
				) {
					update_site_option( self::NETWORK_API_KEY_OPTION, $stored );
				}
			}
		}

		return $plaintext;
	}

	/**
	 * Delete the network API key.
	 *
	 * @return bool True on success.
	 */
	public function delete_network_api_key(): bool {
		return delete_site_option( self::NETWORK_API_KEY_OPTION );
	}

	/**
	 * Check if per-site key override is allowed.
	 *
	 * @return bool True if sites can use their own API keys.
	 */
	public function allows_site_override(): bool {
		return '1' === get_site_option( 'af_allow_site_override', '1' );
	}

	/**
	 * Render the network admin settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		$has_key        = $this->has_network_api_key();
		$allow_override = $this->allows_site_override();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) && 'true' === $_GET['updated'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bushido Almost Famous Network Settings', 'bushido-almost-famous' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Network settings saved.', 'bushido-almost-famous' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="af-settings-section">
				<h2><?php esc_html_e( 'Network API Key', 'bushido-almost-famous' ); ?></h2>
				<p>
					<?php esc_html_e( 'Set a global Bushido API key that will be used by all sites in the network. Individual sites can override this with their own key if allowed below.', 'bushido-almost-famous' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=af_network_settings' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION, 'af_network_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="af_network_api_key"><?php esc_html_e( 'API Key', 'bushido-almost-famous' ); ?></label>
							</th>
							<td>
								<?php if ( $has_key ) : ?>
									<p class="description" style="margin-bottom: 8px;">
										<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
										<?php esc_html_e( 'A network API key is currently stored. Enter a new key below to replace it.', 'bushido-almost-famous' ); ?>
									</p>
								<?php endif; ?>
								<input
									type="password"
									name="af_network_api_key"
									id="af_network_api_key"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'bsh_live_...', 'bushido-almost-famous' ); ?>"
									autocomplete="off"
								/>
								<p class="description">
									<?php esc_html_e( 'Leave blank to keep the existing key.', 'bushido-almost-famous' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Per-Site Override', 'bushido-almost-famous' ); ?></th>
							<td>
								<label for="af_allow_site_override">
									<input
										type="checkbox"
										name="af_allow_site_override"
										id="af_allow_site_override"
										value="1"
										<?php checked( $allow_override ); ?>
									/>
									<?php esc_html_e( 'Allow individual sites to use their own API key instead of the network key.', 'bushido-almost-famous' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Network Settings', 'bushido-almost-famous' ) ); ?>
				</form>
			</div>
		</div>

		<style>
			.af-settings-section {
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
				margin-bottom: 20px;
			}
		</style>
		<?php
	}
}
