=== CCF Sites & Ads Connector ===
Contributors: cemfirat
Requires PHP: 7.4
Stable tag: 1.3.8
License: Proprietary / private project

Secure WordPress control, content administration and conversion connector for CCF Sites & Ads.

== Description ==

CCF Sites & Ads Connector is the universal WordPress-side connector for websites managed through CCF Sites & Ads. The same plugin can be installed on different customer websites; customer separation and Google provider credentials remain in the central CCF platform.

It supports authenticated inventory/content reads and controlled WordPress, Rank Math and YOOtheme changes with preview, approval binding, verification and rollback.

Version 1.2 adds a draft-first content administration surface for preparing new content without bypassing the approval boundary for published content. It can create public post types as draft, pending or private, manage public taxonomies, read and update ACF data on unpublished content, work with image media and set featured images.

Direct publication is intentionally not available through the draft-first surface. Existing published content continues to use the preview/approval/apply workflow.

Google Ads, GA4 and Search Console credentials are never stored in this WordPress plugin. Those provider connections are managed centrally by CCF Sites & Ads.

Tracking is disabled until advertising consent is explicitly provided by the website consent integration.

Supported normalized signals include approved phone, email and WhatsApp clicks, opt-in form submissions and manual lead/conversion events.

Raw form contents, names, email addresses, phone numbers, message bodies and uploaded files are not forwarded by the plugin.

== Installation ==

1. Install the generated ccf-sites-ads-connector.zip package on the customer WordPress website.
2. Configure the plugin under Settings > CCF Sites & Ads or supply the documented server-side constants.
3. Activate CCF Sites & Ads Connector.
4. Pair the site with the corresponding customer workspace in CCF Sites & Ads.
5. Verify the YOOtheme consent mapping or connect another consent manager to window.CCFGoogleAds.setConsent().
6. Mark only approved forms for form-submit tracking.

The same plugin package is used for every customer website. Google Ads, GA4 and Search Console are selected centrally in the customer's CCF workspace and do not require additional WordPress plugins.

See README.md in the project repository for the complete configuration and production checklist.

== Privacy ==

The plugin intentionally minimizes the event payload. Page and referrer URLs have query strings/fragments removed. Advertising click identifiers are handled only after advertising consent and are not persisted by this adapter in cookies or local storage.

== Security ==

The site token is server-side only. Browser requests use a short-lived same-origin bootstrap signature before WordPress forwards an event to the control service. Production control endpoints must use HTTPS.

Content-administration calls use the same signed server-to-server control boundary. Media import is limited to public HTTPS image sources and blocks private/reserved destinations, unsupported MIME types and files larger than 10 MB. Published content cannot be modified or trashed through the direct draft-first endpoints.

== Changelog ==

= 1.3.8 =
* Add read-only performance inventory for database size, autoloaded options, revisions, transients, cron, Action Scheduler and persistent object cache.

= 1.3.2 =
* Restore the complete author-discovery implementation in the installable ZIP.
* Package every maintained PHP include so new connector modules cannot be omitted silently.
* Validate the required author module and PHP syntax in the built release artifact.
* Rotate release metadata caching for immediate update discovery.

= 1.3.1 =
* Add safe discovery of WordPress authors who can edit posts.
* Expose only public author identity fields and advertise the author-read capability.

= 1.2.2 =
* Preserve WordPress native update metadata even when the connector is already current, so the automatic-update toggle remains available.
* Stop forcing an automatic-update preference; WordPress administrators control that setting normally.
* Add current-version/no-update test coverage for the GitHub Update URI path.
* Reduce release metadata cache duration from six hours to one hour.

= 1.2.1 =
* Added WordPress' native Update URI host hook for GitHub-hosted update discovery.
* Kept the existing transient-based update path as a compatibility fallback.
* Rotated the release metadata cache key so the new updater state is detected immediately after upgrading.
* Added tests for the native GitHub update hook and release package validation.

= 1.2.0 =
* Added draft-first creation of public WordPress post types.
* Added public taxonomy and term discovery, creation and assignment.
* Added ACF field discovery and updates on unpublished content.
* Added image-library search, protected HTTPS image import and featured-image assignment.
* Added safe trashing of unpublished content.
* Added idempotency for draft and media creation.
* Preserved the existing preview, approval, verification and rollback boundary for published content.
* Consolidated the plugin entrypoint for future universal CCF Sites & Ads releases.

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
