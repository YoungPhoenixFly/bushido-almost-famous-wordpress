=== Bushido Almost Famous ===
Contributors: bushido
Tags: advertising, marketing, music, woocommerce, campaigns
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Bushido for cross-platform music advertising, creative uploads, campaign reporting, and consent-gated WooCommerce conversions.

== Description ==

Bushido Almost Famous is the WordPress companion for the Bushido Almost Famous advertising service. A Bushido account is required. The plugin provides:

* A setup flow that links the WordPress site to a Bushido channel.
* An authenticated campaign console through `[almost-famous-portal]` and the Bushido Almost Famous Portal block.
* WordPress capabilities for viewers, campaign managers, settings managers, and account managers.
* Campaign, audience, creative, connection, payment, conversion-pixel, and reporting screens.
* Direct creative upload through a create, short-lived object-storage upload, and confirm lifecycle.
* Consent-gated WooCommerce attribution and conversion delivery.
* Multisite network API-key support with optional site overrides.

The source and WordPress.org package use Bushido production services by default. There are no local Pro, trial, paid-feature gates, or simulated fraud-report exports in this plugin. Ad spend and any Bushido service fees are handled by the Bushido service when an administrator deliberately creates a checkout.

== External Services ==

This plugin depends on the Bushido Almost Famous service. External requests begin when an administrator starts connection or uses a service-backed feature. Normal HTTPS transport also exposes the site's public IP address to the destination service.

= Bushido Almost Famous API =

Default endpoint: `https://api.almost-famous.backend-bushidoco.de/api/v1`

Service provider: Bushido

The API receives data when an administrator connects the site, validates a key, manages campaigns, requests reporting, connects an advertising platform, uploads an audience or creative, creates or inspects a payment checkout, or delivers an eligible WooCommerce conversion.

Depending on the feature, requests may include:

* Site URL, site name, WordPress version, plugin version, callback URL, multisite blog ID, security state, and one-time setup code.
* The encrypted-at-rest Bushido API key, decrypted only for the HTTPS `X-API-Key` request header.
* Organization/channel and advertising-account identifiers.
* Campaign names, objectives, budgets, schedules, targeting choices, platform choices, creative IDs, status actions, and reporting queries.
* Audience names/types/configuration and hashed customer-list values when an administrator explicitly uploads them.
* Creative names, MIME types, sizes, selected media bytes, and asset processing state.
* Campaign/payment identifiers required to create or inspect a requested ad-spend checkout.
* For a consented WooCommerce conversion: SHA-256 hashed buyer email, user agent, order ID, value, currency, product IDs, item count, checkout URL, source site, and captured advertising attribution.

Bushido Terms: https://bushido.is/terms

Bushido Privacy Policy: https://bushido.is/privacy

= Bushido connection and OAuth pages =

Default application: `https://bushido.is`

The explicit "Connect this site" action redirects an administrator to `https://bushido.is/almost-famous/wp-connect` with the site URL, site name, WordPress callback URL, security state, and blog ID when applicable. After the administrator approves a channel, a short-lived single-use code returns to WordPress and is exchanged for the site's Bushido credential.

Advertising-platform OAuth is hosted by Bushido and the selected platform. Platform passwords, access tokens, and refresh tokens are not stored in WordPress. The plugin stores only identifiers and status needed to show the connected account.

The platform's own terms and privacy policy apply when an administrator chooses to connect Meta, Google/YouTube, TikTok, or Spotify.

= Creative object storage =

When an administrator uploads a creative, the Bushido API returns a short-lived HTTPS upload URL for Bushido-designated object storage. The plugin sends the selected media bytes, MIME type, and content length directly to that URL, then confirms the asset with the Bushido API. A failed upload or confirmation triggers best-effort deletion of the incomplete remote asset.

The upload host is selected by the Bushido API and therefore is not a fixed WordPress.org hostname. No creative bytes are uploaded until an authorized WordPress user submits the upload form.

== Privacy ==

Campaign-console and administration requests are made only for signed-in users with the required capability. API keys are encrypted using authenticated AES-256-GCM with WordPress authentication keys; readable legacy ciphertext is migrated only after the replacement is durably verified.

WooCommerce conversion delivery is default-deny. The plugin recognizes Cookiebot, Complianz, and WP Consent API marketing consent. If no supported consent manager is present, no conversion is sent unless the site operator deliberately enables the "assume consent" setting or `almost_famous_default_consent` filter for a lawful use case.

Use WordPress Privacy Tools to export or erase plugin-associated local data. Site operators remain responsible for describing their Bushido and advertising-platform use in their privacy notice and selecting a lawful consent configuration.

== Installation ==

1. Install the plugin from WordPress.org, or upload the `bushido-almost-famous` directory to `/wp-content/plugins/`.
2. Activate **Bushido Almost Famous**.
3. Open the setup wizard and choose **Connect your Bushido account**, or enter an existing Bushido API key.
4. Approve the Bushido channel to link. The plugin stores the returned per-site credential encrypted.
5. Open the campaign console from the Bushido Almost Famous dashboard, or add `[almost-famous-portal]` to a page.

For Multisite, a network administrator can configure a network credential under Network Admin settings and decide whether individual sites may override it.

== Frequently Asked Questions ==

= Do I need a Bushido account? =

Yes. This plugin is a WordPress client for the Bushido Almost Famous service and does not orchestrate advertising by itself.

= Does the WordPress.org plugin use staging? =

No. Source and WordPress.org builds default to the production API and production Bushido app. Staging is a separately marked GitHub artifact with an external Update URI and is never deployed by the WordPress.org workflow.

= Can I configure a local or private Bushido environment? =

Yes. Define both `AF_API_BASE_URL` and `AF_BUSHIDO_APP_URL` in `wp-config.php`. Partial, invalid, or mixed-layer overrides fail closed so a code cannot be minted in one environment and exchanged in another. Plain HTTP is accepted only for loopback development hosts.

= Does the plugin store advertising-platform passwords or OAuth tokens? =

No. Platform OAuth is completed by Bushido and the platform. WordPress stores the Bushido API key encrypted plus non-secret connection identifiers and status.

= Will it send WooCommerce conversions without consent? =

Not by default. Marketing consent must be granted through a supported consent integration. With no supported consent manager present, conversion delivery remains off unless the site operator explicitly opts in.

= Are creative uploads durable? =

The plugin creates a remote asset, uploads bytes to its short-lived URL, then confirms it. Source metadata is stored locally only after confirmation. Failed upload or confirmation attempts trigger best-effort remote cleanup and surface an error instead of reporting success.

= Is there a Pro or trial edition gate? =

No. This plugin contains no local feature-tier or trial gate. A connected Bushido service may charge for requested ad spend or service activity under its published terms.

= Where is the source code? =

The complete preferred source and build instructions are published at `https://github.com/YoungPhoenixFly/bushido-almost-famous-wordpress`. Production ZIPs are reproducibly assembled from that source after Composer and npm builds.

== Upgrade Notice ==

= 1.0.0 =
Initial public release. Connect a Bushido account before using service-backed features.

== Changelog ==

= 1.0.0 =
* Initial Bushido Almost Famous public release.
* Production-safe service defaults with atomic API/app environment selection.
* Encrypted site and Multisite credential storage with verified legacy migration.
* Authenticated campaign console, setup flow, audiences, creatives, connections, reporting, payments, and settings.
* Three-stage creative upload lifecycle with failure compensation.
* Consent-gated WooCommerce attribution and conversion delivery.
* Deterministic setup, analytics, creative-upload, portal, and uninstall browser coverage.
