# Bushido Almost Famous Portal Local Testing

Use the public portal shortcode on a published page:

```text
[almost-famous-portal]
```

For a deterministic local demo that does not require a live API key:

```text
[almost-famous-portal demo="1"]
```

You can also enable demo mode globally with an environment variable:

```bash
export ALMOST_FAMOUS_PUBLIC_PORTAL_DEMO_MODE=1
```

## Service endpoint pair

The source distribution defaults to production:

- API: `https://api.almost-famous.backend-bushidoco.de/api/v1`
- App: `https://bushido.is`

Constants, environment variables, and WordPress options are checked in that
order, but an override layer must provide both API and app values. If the API
value has no path, `/api/v1` is appended. A partial pair throws rather than
mixing environments.

## wp-env example

Add the override in your local WordPress environment before starting `wp-env`:

```bash
export ALMOST_FAMOUS_API_BASE_URL=https://api.almost-famous-staging.backend-bushidoco.de
export ALMOST_FAMOUS_BUSHIDO_APP_URL=https://staging.bushido.is
export ALMOST_FAMOUS_PUBLIC_PORTAL_DEMO_MODE=0
```

For a locally running API and Bushido app, pair both overrides:

```php
define( 'AF_API_BASE_URL', 'http://localhost:3010/api/v1' );
define( 'AF_BUSHIDO_APP_URL', 'http://localhost:3000' );
```

Then configure a real API key in the Bushido Almost Famous setup wizard inside wp-admin.
