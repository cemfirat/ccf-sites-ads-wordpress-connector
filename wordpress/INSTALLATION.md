# Generic production installation

Use this checklist separately for every customer website. Installation,
activation and configuration change the live website and require explicit approval.

## Prepared package

Build the validated installable ZIP with:

```bash
npm ci
npm run check
node --check wordpress/assets/ccf-tracking.js
php -l wordpress/ccf-google-ads-site-connector.php
sh scripts/package-wordpress.sh
```

Expected output: `dist/ccf-sites-ads-connector.zip`.

Stable releases are published under a `wordpress-v*` tag. WordPress discovers
those releases automatically and accepts only the canonical
`ccf-sites-ads-connector.zip` asset from this repository.

## Server-side configuration after explicit approval

Preferred: the live WordPress server receives the following constants through
`wp-config.php` or equivalent protected server configuration:

```php
define('CCF_GADS_CONTROL_ENDPOINT', 'https://YOUR-CONTROL-SERVICE');
define('CCF_GADS_SITE_TOKEN', 'VALUE_FROM_SECRET_MANAGER');
define('CCF_GADS_CLIENT_ID', 'UNIQUE-CUSTOMER-ID');
define('CCF_GADS_SITE_ID', 'UNIQUE-SITE-ID');
define('CCF_GADS_ENVIRONMENT', 'production');
```

`CCF_GADS_SITE_TOKEN` must be loaded from Secret Manager resource
`projects/PROJECT_ID/secrets/CUSTOMER_SITE_TOKEN`. Never copy the value
into Git, browser JavaScript, page HTML, tickets or deployment documentation.

If protected file access is unavailable, configure it under
**Settings → CCF Sites & Ads**. The plugin encrypts the token with AES-256-GCM
and a key derived from the WordPress auth salt before storing it. The token is
never rendered back into the admin page.

## Activation gates

Before enabling tracking on the live site:

1. install and activate the ZIP only after explicit approval;
2. confirm the control URL and server-side token without exposing the token;
3. verify the built-in YOOtheme mapping for `marketing.google_ads` and
   `statistics.google_analytics`;
4. keep tracking disabled until advertising consent is exactly `true`;
5. opt in only the approved lead forms;
6. test bootstrap caching, phone, email, WhatsApp and form events;
7. verify each event reaches the control service once and retries as duplicate;
8. confirm no form contents or direct contact data appear in payloads/logs.

After activation, verify the signed `/ccf-sites/v1/status` and `/inventory`
routes before allowing changes. Test preview, apply, verification and rollback
in staging before enabling writes for the production website.
