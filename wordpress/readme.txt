=== CCF Sites & Ads Connector ===
Contributors: cemfirat
Requires PHP: 7.4
Stable tag: 1.1.1
License: Proprietary / private project

Secure WordPress control and conversion connector for CCF Sites & Ads.

== Description ==

This private plugin connects an approved WordPress website to CCF Sites & Ads without exposing Google Ads credentials or the server-side site token to browser JavaScript.

It supports authenticated inventory/content reads and controlled WordPress,
Rank Math and YOOtheme changes with preview, approval binding, verification and rollback.

Tracking is disabled until advertising consent is explicitly provided by the website consent integration.

Supported normalized signals include approved phone, email and WhatsApp clicks, opt-in form submissions and manual lead/conversion events.

Raw form contents, names, email addresses, phone numbers, message bodies and uploaded files are not forwarded by the plugin.

== Installation ==

1. Install the generated ccf-sites-ads-connector.zip package.
2. Configure the plugin under Settings > CCF Sites & Ads or supply the documented server-side constants.
3. Activate CCF Sites & Ads Connector.
4. Configure unique client/site/environment values for the customer.
5. Verify the YOOtheme consent mapping or connect another consent manager to window.CCFGoogleAds.setConsent().
6. Mark only approved forms for form-submit tracking.

See README.md in the project repository for the complete configuration and production checklist.

== Privacy ==

The plugin intentionally minimizes the event payload. Page and referrer URLs have query strings/fragments removed. Advertising click identifiers are handled only after advertising consent and are not persisted by this adapter in cookies or local storage.

== Security ==

The site token is server-side only. Browser requests use a short-lived same-origin bootstrap signature before WordPress forwards an event to the control service. Production control endpoints must use HTTPS.

== Changelog ==

= 1.1.1 =
* Use the public CCF Sites & Ads WordPress release channel for direct downloads and automatic updates.

= 1.1.0 =
* Added secure update discovery through versioned GitHub Releases.
* Added a stable latest-release download package for CCF customer onboarding.

= 1.0.0 =
* Added signed WordPress control endpoints for inventory and content reads.
* Added previewed, approval-bound WordPress, Rank Math and YOOtheme writes.
* Added durable snapshots, verification, drift protection and rollback.
* Removed all customer-specific defaults.

= 0.3.1 =
* Preserve encrypted configuration across repeated WordPress sanitization passes.

= 0.3.0 =
* Added encrypted WordPress-admin configuration for environments without wp-config access.
* Added native YOOtheme consent synchronization for marketing and statistics categories.

= 0.2.0 =
* Hardened WordPress adapter with validated installable ZIP packaging.
