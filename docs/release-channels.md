# Release channels

Production and staging are separate artifacts from the same source commit.

## Production

`scripts/build-dist.sh DEST VERSION production` retains the production default
pair and has no `Update URI`. This is the only channel the WordPress.org release
workflow accepts.

Before dependency setup, packaging, or any step that references deployment
credentials, `scripts/check-production-consent-route.sh` requires a successful
response at the exact URL:

`https://bushido.is/almost-famous/wp-connect`

The workflow is tag-only, and the tag must exactly equal `v` plus the plugin
header version (for example, `v1.0.0`). Source tests, PHPCS, audits, and lint run
before packaging. Plugin Check and Playwright then run against the single
hashed ZIP. Afterward, deployment receives a fresh directory extracted from
the unchanged, checksum-verified ZIP.

## Staging

`scripts/build-dist.sh DEST VERSION staging` modifies only the fresh
distribution copy. It defines
`BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL=staging` and adds:

`Update URI: https://github.com/YoungPhoenixFly/bushido-almost-famous-wordpress`

The staging workflow is manual and uploads a ZIP plus checksum as a GitHub
artifact. It has no SVN credentials, protected WordPress.org environment, or
deploy step. A staging ZIP must never be submitted to WordPress.org.

Unknown release-channel values fail closed during plugin configuration.
