<?php
/**
 * Platform connections admin page.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Client;

/**
 * Platform connections page.
 *
 * Renders the per-platform OAuth Connect/Disconnect rows and keeps the
 * locally-cached `af_accounts` option in sync with the authoritative
 * `/auth/connections` list on the Bushido backend.
 */
class Accounts {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'af-accounts';

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client API client.
	 */
	public function __construct( Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Register the accounts submenu page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'bushido-almost-famous',
			__( 'Accounts', 'bushido-almost-famous' ),
			__( 'Accounts', 'bushido-almost-famous' ),
			'af_manage_accounts',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Reconcile the locally-stored platform connection state with the
	 * authoritative list on the Bushido backend.
	 *
	 * The plugin no longer passes a redirectUri to the backend's OAuth
	 * /auth/connect endpoint (which would require every customer site to
	 * be added to the backend's ALLOWED_REDIRECT_URIS allowlist and
	 * therefore not scale). Instead the backend lands the user on its own
	 * dashboard after OAuth; when the user returns to wp-admin we fetch
	 * /auth/connections and rebuild af_accounts[] from what the backend
	 * actually has on file.
	 *
	 * Falls back to the cached af_accounts option on API error.
	 *
	 * @return array<string, array{credentialId:string, accountId:string, accountName:string, status:string, connectedAt:int}>
	 */
	public function reconcile_platform_accounts(): array {
		$cached = (array) get_option( 'af_accounts', array() );

		$response = $this->client->get( '/auth/connections' );
		if ( isset( $response['error'] ) ) {
			return $cached;
		}

		// Only reconcile when the backend returns a list shape we recognise.
		// An empty body (e.g. plugin offline / unconfigured) leaves the local
		// cache intact so we never wipe a freshly-stored connection just
		// because the API call returned `{}`.
		$payload = $response['data'] ?? null;
		if ( is_array( $payload ) && isset( $payload['credentials'] ) && is_array( $payload['credentials'] ) ) {
			$rows = $payload['credentials'];
		} elseif ( is_array( $payload ) && array() !== $payload && isset( $payload[0] ) ) {
			$rows = $payload;
		} else {
			return $cached;
		}

		$fresh = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$platform = strtolower( (string) ( $row['platform'] ?? '' ) );
			if ( '' === $platform ) {
				continue;
			}
			$fresh[ $platform ] = array(
				'credentialId' => (string) ( $row['id'] ?? '' ),
				'accountId'    => (string) ( $row['accountId'] ?? '' ),
				'accountName'  => (string) ( $row['accountName'] ?? '' ),
				'status'       => (string) ( $row['status'] ?? 'active' ),
				'source'       => (string) ( $row['source'] ?? 'oauth' ),
				'connectedAt'  => isset( $cached[ $platform ]['connectedAt'] ) ? (int) $cached[ $platform ]['connectedAt'] : time(),
			);
		}

		update_option( 'af_accounts', $fresh );
		return $fresh;
	}

	/**
	 * Render the accounts management page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'af_manage_accounts' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'bushido-almost-famous' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connected_platform = isset( $_GET['af_connected'] ) ? sanitize_key( wp_unslash( $_GET['af_connected'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connect_error     = isset( $_GET['af_connect_error'] ) ? sanitize_key( wp_unslash( $_GET['af_connect_error'] ) ) : '';
		$platform_accounts = $this->reconcile_platform_accounts();
		$org               = $this->client->get_own_org();
		$credential_mode   = is_array( $org ) && isset( $org['credentialMode'] )
			? (string) $org['credentialMode']
			: (string) get_option( 'af_org_credential_mode', 'agency' );
		$system_creds      = $this->client->list_system_credentials();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account Management', 'bushido-almost-famous' ); ?></h1>

			<?php if ( '' !== $connected_platform ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s is the platform name. */
							__( '%s connected successfully.', 'bushido-almost-famous' ),
							ucfirst( $connected_platform )
						)
					);
					?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $connect_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s is an error code. */
							__( 'Could not finish the connection (%s). Please try again.', 'bushido-almost-famous' ),
							$connect_error
						)
					);
					?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Credential mode toggle -->
			<div class="af-settings-section af-credential-mode-card">
				<h2><?php esc_html_e( 'Ad Account Source', 'bushido-almost-famous' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Choose where campaigns get their platform credentials. Most users keep this on Bushido shared accounts and never have to touch per-platform OAuth.', 'bushido-almost-famous' ); ?>
				</p>

				<div class="af-credmode-options">
					<label class="af-credmode-option <?php echo 'agency' === $credential_mode ? 'is-selected' : ''; ?>">
						<input
							type="radio"
							name="af_credential_mode"
							value="agency"
							<?php checked( 'agency', $credential_mode ); ?>
						/>
						<div>
							<strong><?php esc_html_e( 'Bushido shared accounts', 'bushido-almost-famous' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Run campaigns on Bushido\'s pre-approved Meta, Google, TikTok, and Spotify accounts. Zero setup. Recommended.', 'bushido-almost-famous' ); ?>
							</p>
						</div>
					</label>
					<label class="af-credmode-option <?php echo 'own' === $credential_mode ? 'is-selected' : ''; ?>">
						<input
							type="radio"
							name="af_credential_mode"
							value="own"
							<?php checked( 'own', $credential_mode ); ?>
						/>
						<div>
							<strong><?php esc_html_e( 'My own ad accounts', 'bushido-almost-famous' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Bring your own Meta / Google / TikTok / Spotify ad accounts via OAuth.', 'bushido-almost-famous' ); ?>
							</p>
						</div>
					</label>
				</div>

				<?php if ( 'agency' === $credential_mode && ! empty( $system_creds ) ) : ?>
					<div class="af-system-creds">
						<p class="description">
							<?php esc_html_e( 'Available shared accounts:', 'bushido-almost-famous' ); ?>
						</p>
						<ul class="af-system-creds__list">
							<?php foreach ( $system_creds as $cred ) : ?>
								<?php $platform = isset( $cred['platform'] ) ? (string) $cred['platform'] : ''; ?>
								<?php if ( '' !== $platform ) : ?>
									<li>
										<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
										<?php echo esc_html( ucfirst( strtolower( $platform ) ) ); ?>
									</li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>

			<!-- Platform Connections (only relevant in "own" mode) -->
			<div class="af-settings-section af-platform-connections" <?php echo 'agency' === $credential_mode ? 'data-collapsed="true"' : ''; ?>>
				<h2><?php esc_html_e( 'Platform Connections', 'bushido-almost-famous' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Connect the ad platforms you want to run campaigns on. Authentication happens on bushido.is — the plugin never sees your platform passwords.', 'bushido-almost-famous' ); ?>
				</p>

				<table class="widefat striped af-platforms-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Platform', 'bushido-almost-famous' ); ?></th>
							<th><?php esc_html_e( 'Account', 'bushido-almost-famous' ); ?></th>
							<th><?php esc_html_e( 'Status', 'bushido-almost-famous' ); ?></th>
							<th><?php esc_html_e( 'Action', 'bushido-almost-famous' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$platform_labels = array(
							'meta'    => __( 'Meta (Instagram & Facebook)', 'bushido-almost-famous' ),
							'google'  => __( 'Google Ads (YouTube)', 'bushido-almost-famous' ),
							'tiktok'  => __( 'TikTok Ads', 'bushido-almost-famous' ),
							'spotify' => __( 'Spotify Ads', 'bushido-almost-famous' ),
						);
						foreach ( $platform_labels as $platform_key => $label ) :
							$linked = isset( $platform_accounts[ $platform_key ] ) && is_array( $platform_accounts[ $platform_key ] );
							$row    = $linked ? $platform_accounts[ $platform_key ] : array();
							?>
							<tr data-platform="<?php echo esc_attr( $platform_key ); ?>">
								<td><strong><?php echo esc_html( $label ); ?></strong></td>
								<td>
									<?php if ( $linked ) : ?>
										<?php echo esc_html( ! empty( $row['accountName'] ) ? $row['accountName'] : ( $row['accountId'] ?? '' ) ); ?>
										<?php if ( ! empty( $row['accountId'] ) ) : ?>
											<br /><code><?php echo esc_html( $row['accountId'] ); ?></code>
										<?php endif; ?>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Not connected', 'bushido-almost-famous' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $linked ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
										<?php echo esc_html( $row['status'] ?? 'active' ); ?>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Disconnected', 'bushido-almost-famous' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $linked ) : ?>
										<button type="button"
											class="button af-platform-disconnect"
											data-platform="<?php echo esc_attr( $platform_key ); ?>">
											<?php esc_html_e( 'Disconnect', 'bushido-almost-famous' ); ?>
										</button>
									<?php else : ?>
										<button type="button"
											class="button button-primary af-platform-connect"
											data-platform="<?php echo esc_attr( $platform_key ); ?>">
											<?php esc_html_e( 'Connect', 'bushido-almost-famous' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
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
			.af-platforms-table td {
				vertical-align: middle;
			}
			.af-credmode-options {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 12px;
				margin: 16px 0;
			}
			.af-credmode-option {
				display: flex;
				gap: 12px;
				padding: 12px 14px;
				border: 1px solid #dcdcde;
				border-radius: 4px;
				cursor: pointer;
				background: #fff;
			}
			.af-credmode-option.is-selected {
				border-color: #5865f2;
				box-shadow: 0 0 0 1px #5865f2;
			}
			.af-credmode-option input[type=radio] {
				margin-top: 4px;
			}
			.af-credmode-option p.description {
				margin: 4px 0 0;
			}
			.af-system-creds {
				margin-top: 12px;
				padding: 12px;
				background: #f6f7f7;
				border-left: 4px solid #46b450;
			}
			.af-system-creds__list {
				display: flex;
				gap: 16px;
				margin: 8px 0 0;
				padding: 0;
				list-style: none;
				flex-wrap: wrap;
			}
			.af-platform-connections[data-collapsed=true] {
				opacity: 0.6;
			}
		</style>
		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				if ( typeof wp === 'undefined' || ! wp.apiFetch ) {
					return;
				}
				// wp.apiFetch needs the REST nonce + root URL middleware
				// explicitly registered for cookie-authenticated calls.
				if ( window.wpApiSettings && wp.apiFetch.createNonceMiddleware ) {
					wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( window.wpApiSettings.nonce ) );
				}
				if ( window.wpApiSettings && wp.apiFetch.createRootURLMiddleware ) {
					wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( window.wpApiSettings.root ) );
				}
				const rest = '/almost-famous/v1/oauth';

				// Credential-mode toggle — flip the org's credentialMode and
				// reload to re-render the appropriate sections.
				document.querySelectorAll( 'input[name="af_credential_mode"]' ).forEach( function ( radio ) {
					radio.addEventListener( 'change', async function () {
						const mode = radio.value;
						radio.disabled = true;
						try {
							await wp.apiFetch( {
								path: '/almost-famous/v1/org/credential-mode',
								method: 'POST',
								data: { mode: mode },
							} );
							window.location.reload();
						} catch ( err ) {
							radio.disabled = false;
							const msg = ( err && err.message ) || 'unknown_error';
							window.alert( <?php echo wp_json_encode( __( 'Could not update credential mode:', 'bushido-almost-famous' ) ); ?> + ' ' + msg );
						}
					} );
				} );

				function disable( button, label ) {
					button.disabled = true;
					button.dataset.originalLabel = button.textContent;
					button.textContent = label;
				}

				function reenable( button ) {
					button.disabled = false;
					if ( button.dataset.originalLabel ) {
						button.textContent = button.dataset.originalLabel;
					}
				}

				document.querySelectorAll( '.af-platform-connect' ).forEach( function ( button ) {
					button.addEventListener( 'click', async function () {
						const platform = button.dataset.platform;
						disable( button, <?php echo wp_json_encode( __( 'Redirecting…', 'bushido-almost-famous' ) ); ?> );
						try {
							const resp = await wp.apiFetch( {
								path: rest + '/start',
								method: 'POST',
								data: { platform: platform },
							} );
							if ( resp && resp.authorizationUrl ) {
								window.location = resp.authorizationUrl;
								return;
							}
							throw new Error( ( resp && resp.error && resp.error.message ) || 'oauth_failed' );
						} catch ( err ) {
							reenable( button );
							const msg = ( err && err.message ) || 'oauth_failed';
							window.alert( <?php echo wp_json_encode( __( 'Could not start the connection:', 'bushido-almost-famous' ) ); ?> + ' ' + msg );
						}
					} );
				} );

				document.querySelectorAll( '.af-platform-disconnect' ).forEach( function ( button ) {
					button.addEventListener( 'click', async function () {
						const platform = button.dataset.platform;
						if ( ! window.confirm( <?php echo wp_json_encode( __( 'Disconnect this platform? Active campaigns may pause.', 'bushido-almost-famous' ) ); ?> ) ) {
							return;
						}
						disable( button, <?php echo wp_json_encode( __( 'Disconnecting…', 'bushido-almost-famous' ) ); ?> );
						try {
							await wp.apiFetch( {
								path: rest + '/disconnect',
								method: 'POST',
								data: { platform: platform },
							} );
							window.location.reload();
						} catch ( err ) {
							reenable( button );
							const msg = ( err && err.message ) || 'unknown_error';
							window.alert( <?php echo wp_json_encode( __( 'Could not disconnect:', 'bushido-almost-famous' ) ); ?> + ' ' + msg );
						}
					} );
				} );
			} );
		</script>
		<?php
	}
}
