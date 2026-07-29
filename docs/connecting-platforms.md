# Connecting platforms (Meta / Google / TikTok / Spotify)

The Bushido Almost Famous plugin never sees your platform passwords. Every OAuth
flow is hosted at `bushido.is`; the plugin only stores the per-platform
credential id the backend returns after consent.

## Flow

1. In **wp-admin → Bushido Almost Famous → Accounts**, click **Connect** next to the
   platform you want to advertise on.
2. The plugin asks the backend for an authorization URL (no `redirectUri`
   is sent — see below) and bounces your browser to the platform.
3. Approve the requested permissions on the platform's consent screen.
4. The platform 302s back to the Bushido Almost Famous API, which finishes the token
   exchange and lands you on the Bushido dashboard with a "Connected"
   confirmation.
5. Click **Return to your WordPress site** on the Bushido dashboard (or
   simply re-open wp-admin). The Accounts page fetches your current
   connections from `/auth/connections` on every render and shows the
   newly-linked platform.
6. To revoke a connection, click **Disconnect** on the same row. The
   plugin calls `DELETE /auth/connections/:id` and drops its local
   reference.

## Why the plugin doesn't pass a redirectUri

The Bushido OAuth service validates any caller-supplied `finalRedirectUri`
against a deploy-time origin allowlist (`ALLOWED_REDIRECT_URIS`). For a
publicly-distributed WordPress plugin that approach doesn't scale: every
customer's site would need to be added to that allowlist and the backend
redeployed.

We dodge the problem by **not** passing a `redirectUri`. The backend
completes OAuth and redirects to its own dashboard, and the plugin
reconciles its local state from `/auth/connections` whenever the
Accounts page is rendered. This means:

- ✅ Zero per-customer infra changes.
- ✅ Same backend code handles plugin users and direct dashboard users.
- ✅ Connections always reflect the truth on the backend, even if the
  user closes the OAuth tab before clicking "Return".
- ⚠️ The user has one extra click ("Return to your site") if they did
  the flow in the same tab as wp-admin. Most users open the Connect
  button in a new tab, where the click happens implicitly when they
  switch back.

## What's stored locally

Only the `af_accounts` WP option (auto-reconciled from
`/auth/connections`):

```
'af_accounts' => [
  'meta'    => [ credentialId, accountId, accountName, status, connectedAt ],
  'google'  => [ ... ],
  'tiktok'  => [ ... ],
  'spotify' => [ ... ],
]
```

Plus a short-lived `af_oauth_state_{user_id}` transient (10 min) used
to CSRF-protect the legacy `/oauth/callback` REST route. New flows
don't depend on the callback any more, but the route stays in place
for backward compatibility with any in-flight tabs.

## Capabilities

- **Connect / Disconnect:** requires `af_manage_accounts` (or
  `manage_options`).
- **View Accounts page:** requires `af_manage_accounts`.

The custom capabilities are registered by `Roles::register_roles()` on
plugin activation and granted to the WordPress `administrator` role
plus the plugin-defined `bushido_admin` role.
