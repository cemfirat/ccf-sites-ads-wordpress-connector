# Changelog

All notable changes to the Harika Connector are documented here.

## 1.3.13 - 2026-10-08

- Name the release package `harika-wordpress-connector-v<version>.zip`, matching the tag.
- Keep attaching `harika-wordpress-connector.zip` and `ccf-sites-ads-connector.zip` so connectors already installed can download this release.
- Prefer the versioned package when a release contains more than one filename.

## 1.3.12 - 2026-10-08

- Rename the PHP classes to `Harika_*` and publish `harika-wordpress-connector.zip`.
- Keep attaching `ccf-sites-ads-connector.zip` and keep the installed plugin directory `ccf-google-ads-site-connector`, so an existing site updates without deactivating.
- Prefer `harika-wordpress-connector.zip` when both package names are on the release. Connectors that still request the previous filename keep working.
- Leave `wp-config` constants, REST namespaces, stored options, request headers, and `window.CCFGoogleAds` unchanged. Installed sites and the running control services already use those names.

## 1.3.11 - 2026-10-07

- Publish releases as `harika-wordpress-connector-v<version>`.
- Keep accepting the existing `wordpress-v<version>` tags, including `wordpress-v1.3.10`.

## 1.3.10 - 2026-10-07

- Rename the installable connector to Harika Connector.
- Point the release channel at `cemfirat/harika-wordpress-connector` and keep accepting the previous repository URL until existing sites have updated.
- Keep the WordPress plugin slug and the `ccf-sites-ads-connector.zip` asset name so an installed connector updates in place.

## 1.3.9 - 2026-10-07

- Publish Harika connector 1.3.9 as the installable WordPress package.
- Carry the WordPress 6.0 requirement and package text domain into the release asset. Those header fields landed after `wordpress-v1.3.8` and were not part of that runtime package.
- Leave control, content, tracking, and updater behavior unchanged.

## 1.3.8 - 2026-09-22

- Add performance inventory for CCF diagnostics.
- Keep the installable WordPress package and GitHub release asset at the existing 1.3.8 runtime version.
- Repository-only documentation and shared branding changes made after the release do not change the packaged plugin version.
