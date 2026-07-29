# Privacy and data flow

This document complements the field-level External Services section in
`readme.txt`.

## Trust boundary

The plugin is an authenticated WordPress client. Signed-in users call the local
`almost-famous/v1` REST namespace. PHP applies WordPress capabilities and
nonces, decrypts the Bushido credential only for the outgoing HTTPS request,
and proxies the allowed public API operation.

The production service pair is:

- `https://api.almost-famous.backend-bushidoco.de/api/v1`
- `https://bushido.is`

An administrator may provide a different pair. Partial and invalid pairs fail
closed. The source package never chooses staging from `WP_ENVIRONMENT_TYPE`.

## Stored locally

- An authenticated-encryption ciphertext for the site or network Bushido API
  key. Readable legacy ciphertext is replaced only after a verified readback;
  failed migration preserves the working legacy value.
- Organization, channel, connection, campaign, audience, creative-source, and
  settings identifiers required for the WordPress user interface.
- Short-lived caches, setup state/locks, OAuth CSRF state, attribution cookies,
  and consent verdicts.
- WooCommerce order attribution and the consent verdict captured at checkout.

Platform passwords and platform OAuth access/refresh tokens are not stored in
WordPress.

## Sent to Bushido

Connection sends site/callback metadata, a CSRF state, a blog ID when relevant,
and a one-time exchange code. Feature requests send the identifiers and
payloads an authorized user submits. Creative bytes go to a short-lived
API-designated object-storage URL and are then confirmed with the API.

WooCommerce conversion delivery can include a hashed buyer email, order and
value data, product identifiers, user agent, page/site context, and captured ad
attribution. It is sent only after marketing consent. Without a supported CMP,
the default is deny.

## Operator responsibilities

Site operators must:

- describe Bushido and selected advertising platforms in their privacy notice;
- select and configure a lawful consent mechanism;
- grant WordPress capabilities only to appropriate users;
- avoid audience or creative uploads they lack permission to process; and
- honor export/erasure requests using WordPress Privacy Tools and any necessary
  Bushido account process.

Terms: `https://bushido.is/terms`  
Privacy: `https://bushido.is/privacy`
