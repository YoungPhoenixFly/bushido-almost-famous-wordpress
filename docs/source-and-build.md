# Source, build, and distribution

This repository contains the complete preferred source for the plugin.

## Source layout

- `includes/`, `shortcodes/`, and the root plugin file: PHP runtime source.
- `src/`: JavaScript source for admin and portal bundles.
- `blocks/*/src/`: Gutenberg block source.
- `assets/js/` and `blocks/*/build/`: generated JavaScript runtime files.
- `tests/`: PHPUnit stubs/tests and Playwright browser flows.
- `scripts/`: public audit and immutable packaging tools.

## Build

With PHP 8.1+, Composer 2, Node 20, and npm:

```bash
composer install
npm ci
composer test
composer phpcs
npm run build
```

For a production distribution, replace Composer development dependencies with
the optimized runtime autoloader and package to a fresh location:

```bash
composer install --no-dev --optimize-autoloader
release_root="$(mktemp -d)"
scripts/build-dist.sh "$release_root/bushido-almost-famous" 1.0.0 production
(cd "$release_root" && zip -X -qr bushido-almost-famous.zip bushido-almost-famous)
```

The distribution intentionally excludes Git metadata, workflows, tests,
JavaScript source/tooling, the Composer lockfile, documentation, source maps,
and environment files. It includes the GPL license, WordPress.org readme, PHP,
translations, compiled assets, Composer's runtime classmap autoloader, and the
matching `composer.json` manifest required to make that vendor tree
self-describing to WordPress Plugin Check.

`scripts/verify-dist.sh` validates version agreement, channel markers, shipped
files, forbidden development files, links, and PHP syntax.
`scripts/extract-archive.sh` additionally validates the archive root and blocks
path traversal and special files before extraction.

CI hashes the ZIP immediately after its only build. Plugin Check, Playwright,
and deploy use that exact archive or the verified directory extracted from it.
