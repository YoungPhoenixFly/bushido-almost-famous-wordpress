# Bushido Almost Famous

`bushido-almost-famous` is the public, GPL-2.0-or-later WordPress plugin for
connecting a WordPress site to the Bushido Almost Famous advertising service.
This repository is the only plugin source of truth. The private Bushido
application repositories consume its public REST contract; they do not carry a
second plugin copy or publish plugin artifacts.

Version `1.0.0` is intentionally retained for the first public release.

## What the plugin owns

- The WordPress setup handshake and encrypted per-site or multisite API key.
- The authenticated WordPress REST proxy and capability checks.
- Campaign, audience, creative, payment, pixel, and account user interfaces.
- The authenticated front-end console and Gutenberg blocks.
- Consent-gated WooCommerce attribution and conversion delivery.
- Plugin tests, compiled browser assets, packaging, and release workflows.

The Bushido service owns account identity, ad-platform OAuth credentials,
campaign orchestration, payments, media storage, and reporting.

## Production defaults and service boundary

The source and WordPress.org package always default to the coherent production
pair:

- API: `https://api.almost-famous.backend-bushidoco.de/api/v1`
- Consent application: `https://bushido.is`

The connection page is `https://bushido.is/almost-famous/wp-connect`.

Administrators can supply a custom environment only as an API/app pair. Define
both constants in `wp-config.php`:

```php
define( 'AF_API_BASE_URL', 'http://localhost:3010/api/v1' );
define( 'AF_BUSHIDO_APP_URL', 'http://localhost:3000' );
```

Partial, mixed-layer, invalid, and unknown profiles fail closed. HTTP is
accepted only for loopback development hosts. WordPress runtime environment
labels do not silently redirect production source to staging.

Staging is a separately built GitHub artifact. It carries an explicit staging
release-channel constant and an `Update URI` outside WordPress.org. The staging
workflow has no WordPress.org deploy job. See
[docs/release-channels.md](docs/release-channels.md).

## Data and external services

The plugin sends authenticated feature requests to the configured Bushido API.
Selected creative bytes are sent to a short-lived, API-provided object-storage
URL before the asset is confirmed. Platform OAuth is hosted by Bushido; platform
passwords and access/refresh tokens are never stored in WordPress.

WooCommerce conversion data is sent only after marketing consent. The default
is deny when no supported consent-management integration is present. See
[docs/privacy-and-data-flow.md](docs/privacy-and-data-flow.md) and the
WordPress.org [readme.txt](readme.txt) for field-level disclosure.

## Development

Requirements:

- PHP 8.1 or later and Composer 2
- Node.js 20 and npm
- Docker for `wp-env` browser tests

```bash
composer install
npm ci

composer test
composer phpcs
npm run build
npm run test:e2e
```

PHPUnit uses the WordPress stubs under `tests/`; it does not need a WordPress
database. Playwright runs deterministic setup, upload, analytics, portal, and
uninstall flows. Its HTTP fixture rejects unexpected external traffic.

Edit JavaScript under `src/` and block source directories, then rebuild.
`assets/js/*.js` and `blocks/*/build/` are generated runtime files.

## Reproducible packaging

Install production dependencies and compile assets before packaging:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build

release_root="$(mktemp -d)"
scripts/build-dist.sh "$release_root/bushido-almost-famous" 1.0.0 production
(cd "$release_root" && zip -X -qr bushido-almost-famous.zip bushido-almost-famous)
```

`build-dist.sh` requires a fresh destination outside the repository, enforces
the four version declarations, applies `.distignore`, and verifies every PHP
file. `extract-archive.sh` rejects traversal, alternate roots, and special
files. CI builds one immutable ZIP and runs Plugin Check and Playwright against
those exact bytes.

Run `scripts/audit-public-tree.sh` before a public commit. It checks public
history provenance, common secret signatures, private monorepo paths, removed
commercial placeholders, and the license file.

Complete source/build instructions and shipped-file policy are documented in
[docs/source-and-build.md](docs/source-and-build.md).

## Release safety

- `.github/workflows/ci.yml` validates source and the immutable production ZIP.
- `.github/workflows/staging-artifact.yml` creates a non-directory GitHub
  staging artifact only.
- `.github/workflows/wordpress-org-release.yml` is tag-only, requires the tag
  to match the source version, and cannot package or reference SVN credentials
  until `scripts/check-production-consent-route.sh` confirms the production
  consent route returns a successful response at the exact URL.
- No release workflow publishes staging bytes to WordPress.org.

Creating a GitHub repository, configuring environments/secrets, publishing a
staging artifact, and submitting to WordPress.org are deliberate operator
actions. This source tree performs none of them on checkout.

## License

Copyright Bushido contributors. Licensed under
[GPL-2.0-or-later](LICENSE).
