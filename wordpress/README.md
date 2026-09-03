# WordPress adapter

This directory contains the server-side WordPress adapter for `ccf-google-ads-site-connector`.

## Why server-side forwarding

The browser must never receive `CCF_GADS_SITE_TOKEN`. Browser events are sent to a same-origin WordPress REST endpoint. WordPress validates and normalizes the event and forwards it to `ccf-google-ads-control` with the runtime token on the server.

The public page contains no reusable site credential. When advertising consent is granted, the browser fetches a short-lived HMAC page signature from the same-origin `/bootstrap` endpoint with `Cache-Control: no-store`. The signature expires after five minutes. This also works correctly when the WordPress page HTML itself is cached.

The short-lived page signature is not a replacement for the server-side site token. It is combined with strict same-origin checks and a basic rate limit before WordPress forwards an event.

## Requirements

- WordPress with PHP 7.4 or newer
- HTTPS for the production control-service endpoint
- a deployed `ccf-google-ads-control` service
- a dedicated site token stored only in server-side configuration

## Installation

Copy the complete `wordpress` directory into a plugin folder such as:

`wp-content/plugins/ccf-google-ads-site-connector/`

The resulting plugin file must be:

`wp-content/plugins/ccf-google-ads-site-connector/ccf-google-ads-site-connector.php`

Activate **CCF Sites & Ads Connector** in WordPress.

## Updates

The plugin checks the official GitHub Releases feed for a newer stable
`wordpress-v*` release. A validated `ccf-sites-ads-connector.zip` asset then
appears as a normal update in the WordPress Plugins screen. Drafts,
pre-releases, non-semantic tags, foreign download hosts and unexpected asset
names are rejected. No GitHub credential is stored in WordPress.

## Runtime configuration

Preferred when protected server-file access is available: add the following
values to `wp-config.php` or inject equivalent constants from the server
environment:

```php
define('CCF_GADS_CONTROL_ENDPOINT', 'https://CONTROL-SERVICE-URL');
define('CCF_GADS_SITE_TOKEN', 'RUNTIME-SECRET-FROM-SECRET-MANAGER');
define('CCF_GADS_CLIENT_ID', 'example-customer');
define('CCF_GADS_SITE_ID', 'example-site');
define('CCF_GADS_ENVIRONMENT', 'production');
```

`CCF_GADS_SITE_TOKEN` must be a strong secret of at least 32 characters. Never commit the real value to Git and never print it into HTML or JavaScript.

When protected server-file access is unavailable, an administrator can instead
configure the connector under **Settings → CCF Sites & Ads**. In that mode the
token is encrypted with AES-256-GCM using a key derived from the WordPress auth
salt. Only ciphertext is stored in the WordPress options table, and the token
field is never prefilled or sent back to the browser. Rotating WordPress auth
salts requires entering the token again.

## WordPress REST endpoints

The adapter exposes two same-origin tracking endpoints:

- `GET /wp-json/ccf-google-ads/v1/bootstrap` returns the short-lived page signature and is marked `no-store`.
- `POST /wp-json/ccf-google-ads/v1/event` validates and forwards an approved normalized event.

The event endpoint requires the current page signature, an allowed Origin/Referer host, advertising consent, and the local rate limit.

It also exposes an authenticated control surface:

- `GET /wp-json/ccf-sites/v1/status`
- `GET /wp-json/ccf-sites/v1/inventory`
- `GET /wp-json/ccf-sites/v1/content/{id}`
- `POST /wp-json/ccf-sites/v1/changes/preview`
- `POST /wp-json/ccf-sites/v1/changes/apply`
- `POST /wp-json/ccf-sites/v1/changes/rollback`

Control calls use a five-minute timestamp, one-time nonce and HMAC-SHA256
signature. Mutations require an approval ID, idempotency key and the checksum
from the latest preview. The plugin records before/after snapshots and blocks
apply or rollback if the target changed in the meantime.

## Consent integration

Tracking is disabled by default. The adapter does not assume consent.

After the website consent manager confirms the applicable consent state, call:

```js
window.CCFGoogleAds.setConsent({
  analytics: true,
  ads: true,
  source: 'site-consent-manager'
});
```

If `ads` is not exactly `true`, the browser bridge returns `skipped` and sends nothing. Once advertising consent is granted, the bridge obtains its short-lived bootstrap signature automatically.

On YOOtheme sites the adapter listens to `yootheme:consent.init` and
`yootheme:consent.change`. It maps `statistics.google_analytics` to analytics
consent and `marketing.google_ads` to advertising consent. Other sites can
continue to call `window.CCFGoogleAds.setConsent(...)` explicitly.

## Automatic click signals

After advertising consent is granted, the browser bridge automatically recognizes:

- `tel:` links as `phone_click`
- `mailto:` links as `email_click`
- `wa.me`, `api.whatsapp.com`, and WhatsApp-hosted links as `whatsapp_click`

No phone number, email address, WhatsApp destination, link text, or other raw content is included in the event.

An optional conversion action key can be supplied on the link:

```html
<a href="tel:+431234567" data-ccf-action-key="phone_click">Call</a>
```

The destination itself is intentionally not forwarded.

## Form signals

Forms are opt-in to avoid counting search, login, newsletter, or unrelated forms as leads.

Mark an approved form with:

```html
<form data-ccf-google-ads-event="form_submit" data-ccf-action-key="contact_form">
```

Only the normalized event and, when present, the form element ID are sent. Form fields, names, email addresses, phone numbers, messages, and uploaded files are never copied into the event.

## Manual events

Approved integrations may call:

```js
await window.CCFGoogleAds.track('lead', {
  conversion: {
    action_key: 'qualified_lead',
    value: 0,
    currency: 'EUR'
  },
  metadata: {
    source_component: 'contact-confirmation'
  }
});
```

Metadata must remain non-personal and minimal.

## Context and attribution privacy

Page and referrer URLs are reduced to scheme, host, port, and path. Query strings and fragments are stripped before forwarding so arbitrary URL parameters do not leak through the context fields.

`gclid`, `gbraid`, and `wbraid` are handled separately and read only from the current page URL after advertising consent is true. The adapter deliberately does not persist attribution identifiers into cookies or local storage.

A future consent-reviewed attribution persistence layer can be added separately if required.

## Retry and idempotency

The browser generates one stable `event_id`. WordPress forwards that same ID and retries a temporary upstream failure once. The control service treats an already accepted `event_id` as a successful duplicate, preventing double counting at the ingestion boundary.

## Production checklist

Before production activation:

1. Deploy `ccf-google-ads-control` behind HTTPS.
2. Generate and store a dedicated site token in server-side secret configuration.
3. Configure a unique client/site registry entry for the customer in the control service.
4. Verify the WordPress consent-manager callback and keep tracking disabled until consent is granted.
5. Mark only approved lead forms with `data-ccf-google-ads-event`.
6. Test phone, email, WhatsApp, and approved form events in a non-production environment first.
7. Verify the `/bootstrap` response is not cached by any reverse proxy/CDN.
8. Keep Google Ads credentials and Ads writes in the central service, never in WordPress.
