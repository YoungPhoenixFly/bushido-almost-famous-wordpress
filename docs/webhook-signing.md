# Webhook signing contract

> **Status: dormant / forward-looking.** No Bushido backend currently emits
> these webhooks or registers a site's callback URL and secret, so the plugin's
> receiver route is **not registered by default**. Enable it ahead of a backend
> dispatcher with `add_filter( 'almost_famous/enable_webhooks', '__return_true' );`
> and seed the shared secret into the `af_webhook_secret` option. Until then,
> data freshness comes from cache TTLs and manual refresh. This document
> specifies the contract a future dispatcher must implement.

The plugin verifies every incoming webhook against a per-site secret. The
backend dispatcher must follow this contract, modelled on Stripe and GitHub:

## Headers

Every webhook POST to `https://<site>/wp-json/almost-famous/v1/webhooks/incoming`
MUST include the following request headers:

| Header                | Required | Description                                                                                 |
| --------------------- | -------- | ------------------------------------------------------------------------------------------- |
| `X-Bushido-Timestamp` | yes      | Unix epoch seconds (integer string) when the webhook was generated.                         |
| `X-Bushido-Signature` | yes      | Lowercase hex HMAC-SHA256 of `"<timestamp>.<body>"` keyed with the per-site webhook secret. |
| `Content-Type`        | yes      | `application/json`.                                                                         |

`<timestamp>` is the exact contents of the `X-Bushido-Timestamp` header.
`<body>` is the exact raw JSON request body, byte-for-byte.

## Verification rules

1. **Missing timestamp →** the plugin replies `401 missing_timestamp`.
2. **Timestamp outside ±5 minutes of `time()` →** `401 replay_window`. This is
   the only protection against replay attacks; do not relax it.
3. **Signature mismatch →** `401 invalid_signature`.
4. **Malformed JSON →** `400 invalid_payload`.
5. **Missing `event_type` →** `400 missing_event_type`.
6. **Duplicate `idempotency_key` already seen within 24 h →** `409 duplicate_event`.
7. **Otherwise →** `200 ok` after the handler runs.

## Example (pseudocode)

```ts
const timestamp = Math.floor(Date.now() / 1000).toString();
const body = JSON.stringify(payload);
const signature = crypto
  .createHmac("sha256", siteWebhookSecret)
  .update(`${timestamp}.${body}`)
  .digest("hex");

await fetch(url, {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    "X-Bushido-Timestamp": timestamp,
    "X-Bushido-Signature": signature,
  },
  body,
});
```

## Secret rotation

The secret is stored encrypted in the `af_webhook_secret` WP option, set by
the site admin via Settings → Webhooks. Rotating the secret requires the
backend to coordinate the cutover (publish to both old and new secret for
the rotation window).

## Backward compatibility

This contract supersedes the previous body-only signing. Backends MUST be
updated to include `X-Bushido-Timestamp` and sign `timestamp.body` before
the new plugin version (≥ 1.0.0) is deployed to any site, otherwise every
webhook will be rejected with `missing_timestamp`.
