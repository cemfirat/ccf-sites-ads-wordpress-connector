<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['ccf_filters'] = [];
$GLOBALS['ccf_actions'] = [];
$GLOBALS['ccf_transients'] = [];
$GLOBALS['ccf_release_version'] = '1.3.13';
$GLOBALS['ccf_release_repository'] = 'cemfirat/harika-wordpress-connector';
$GLOBALS['ccf_release_tag_prefix'] = 'harika-wordpress-connector-v';

function add_action(string $name, $callback, int $priority = 10, int $accepted_args = 1): void { $GLOBALS['ccf_actions'][$name][] = [$callback, $priority, $accepted_args]; }
function add_filter(string $name, $callback, int $priority = 10, int $accepted_args = 1): void { $GLOBALS['ccf_filters'][$name][] = [$callback, $priority, $accepted_args]; }
function remove_filter(string $name, $callback, int $priority = 10): bool {
    if (!isset($GLOBALS['ccf_filters'][$name])) return false;
    $before = count($GLOBALS['ccf_filters'][$name]);
    $GLOBALS['ccf_filters'][$name] = array_values(array_filter($GLOBALS['ccf_filters'][$name], static function ($entry) use ($callback, $priority) { return !($entry[0] === $callback && $entry[1] === $priority); }));
    return count($GLOBALS['ccf_filters'][$name]) < $before;
}
function plugin_basename(string $file): string { return 'ccf-google-ads-site-connector/ccf-google-ads-site-connector.php'; }
function get_option(string $name, $default = false) { return $default; }
function get_transient(string $name) { return $GLOBALS['ccf_transients'][$name] ?? false; }
function set_transient(string $name, $value, int $ttl): bool { $GLOBALS['ccf_transients'][$name] = $value; return $ttl > 0; }
function delete_transient(string $name): bool { unset($GLOBALS['ccf_transients'][$name]); return true; }
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response): string { return (string) ($response['body'] ?? ''); }
function esc_url_raw(string $value): string { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function wp_remote_get(string $url, array $options): array {
    $matched = null;
    foreach (['cemfirat/harika-wordpress-connector', 'cemfirat/ccf-sites-ads-wordpress-connector'] as $repository) {
        if ($url === 'https://api.github.com/repos/' . $repository . '/releases/latest') $matched = $repository;
    }
    if ($matched === null) throw new RuntimeException('Unexpected release API URL.');
    if (($options['headers']['User-Agent'] ?? '') !== 'Harika-Connector/1.3.12') throw new RuntimeException('Updater user agent is missing or stale.');
    if ($matched !== (string) $GLOBALS['ccf_release_repository']) return ['response' => ['code' => 404], 'body' => ''];
    $version = (string) $GLOBALS['ccf_release_version'];
    $tag = (string) $GLOBALS['ccf_release_tag_prefix'] . $version;
    $download = 'https://github.com/' . $matched . '/releases/download/' . $tag . '/';
    $assets = [];
    if (empty($GLOBALS['ccf_release_legacy_only'])) {
        $assets[] = ['name' => 'harika-wordpress-connector.zip', 'browser_download_url' => $download . 'harika-wordpress-connector.zip'];
    }
    $assets[] = ['name' => 'ccf-sites-ads-connector.zip', 'browser_download_url' => $download . 'ccf-sites-ads-connector.zip'];
    return ['response' => ['code' => 200], 'body' => json_encode(['tag_name' => $tag, 'html_url' => 'https://github.com/' . $matched . '/releases/tag/' . $tag, 'draft' => false, 'prerelease' => false, 'target_commitish' => 'main', 'body' => 'Updater test release', 'assets' => $assets], JSON_THROW_ON_ERROR)];
}

require __DIR__ . '/../wordpress/harika-wordpress-connector.php';
if (!defined('HARIKA_CONNECTOR_VERSION') || HARIKA_CONNECTOR_VERSION !== '1.3.12') throw new RuntimeException('Plugin version constant is not v1.3.12.');
if (!defined('CCF_SITES_ADS_PLUGIN_VERSION') || CCF_SITES_ADS_PLUGIN_VERSION !== HARIKA_CONNECTOR_VERSION) throw new RuntimeException('Legacy version constant is not aliased to the Harika version.');
$nativeFilters = $GLOBALS['ccf_filters']['update_plugins_github.com'] ?? [];
if (count($nativeFilters) !== 1 || $nativeFilters[0][2] !== 4) throw new RuntimeException('Native Update URI hook is not registered correctly.');
$refreshActions = $GLOBALS['ccf_actions']['load-update-core.php'] ?? [];
if (count($refreshActions) !== 1) throw new RuntimeException('Manual update refresh hook is not registered.');

$native = Harika_Connector_Updater::host_update(false, ['UpdateURI' => 'https://github.com/cemfirat/harika-wordpress-connector'], 'ccf-google-ads-site-connector/ccf-google-ads-site-connector.php', ['de_DE']);
if (!is_array($native) || ($native['version'] ?? '') !== '1.3.13') throw new RuntimeException('Native Update URI hook did not return the newer stable release.');
if (array_key_exists('autoupdate', $native)) throw new RuntimeException('Updater must not force an autoupdate preference.');
if (($native['package'] ?? '') !== 'https://github.com/cemfirat/harika-wordpress-connector/releases/download/harika-wordpress-connector-v1.3.13/harika-wordpress-connector.zip') throw new RuntimeException('Native Update URI hook accepted an unexpected package URL.');
if (($native['id'] ?? '') !== 'https://github.com/cemfirat/harika-wordpress-connector') throw new RuntimeException('Update payload did not use the Harika repository.');
$foreign = Harika_Connector_Updater::host_update('sentinel', ['UpdateURI' => 'https://github.com/example/other'], 'ccf-google-ads-site-connector/ccf-google-ads-site-connector.php', ['de_DE']);
if ($foreign !== 'sentinel') throw new RuntimeException('Updater accepted a foreign Update URI.');

$GLOBALS['ccf_transients']['harika_connector_github_release_v1'] = ['version' => '1.3.4', 'repository' => 'cemfirat/harika-wordpress-connector', 'package' => 'https://github.com/cemfirat/harika-wordpress-connector/releases/download/wordpress-v1.3.4/harika-wordpress-connector.zip'];
$_GET['force-check'] = '1';
Harika_Connector_Updater::refresh_on_manual_check();
if (isset($GLOBALS['ccf_transients']['harika_connector_github_release_v1'])) throw new RuntimeException('Forced WordPress update check did not clear connector release cache.');
unset($_GET['force-check']);

$GLOBALS['ccf_release_version'] = '1.3.12';
delete_transient('harika_connector_github_release_v1');
$nativeCurrent = Harika_Connector_Updater::host_update(false, ['UpdateURI' => 'https://github.com/cemfirat/harika-wordpress-connector'], 'ccf-google-ads-site-connector/ccf-google-ads-site-connector.php', ['de_DE']);
if (!is_array($nativeCurrent) || ($nativeCurrent['version'] ?? '') !== '1.3.12') throw new RuntimeException('Native Update URI hook must return current release metadata when already up to date.');
$info = Harika_Connector_Updater::information(false, 'plugin_information', (object) ['slug' => 'ccf-google-ads-site-connector']);
if (!is_object($info) || ($info->name ?? '') !== 'Harika Connector') throw new RuntimeException('Plugin information did not use the Harika name.');
$currentTransient = (object) ['checked' => ['ccf-google-ads-site-connector/ccf-google-ads-site-connector.php' => '1.3.12'], 'response' => [], 'no_update' => []];
$current = Harika_Connector_Updater::check($currentTransient);
$noUpdate = $current->no_update['ccf-google-ads-site-connector/ccf-google-ads-site-connector.php'] ?? null;
if (!is_object($noUpdate) || ($noUpdate->new_version ?? '') !== '1.3.12') throw new RuntimeException('Current plugin metadata was not preserved in no_update.');
if (isset($current->response['ccf-google-ads-site-connector/ccf-google-ads-site-connector.php'])) throw new RuntimeException('Current version must not be offered as an update.');

delete_transient('harika_connector_github_release_v1');
$GLOBALS['ccf_release_repository'] = 'cemfirat/ccf-sites-ads-wordpress-connector';
$GLOBALS['ccf_release_tag_prefix'] = 'wordpress-v';
$GLOBALS['ccf_release_legacy_only'] = true;
$legacy = Harika_Connector_Updater::host_update(false, ['UpdateURI' => 'https://github.com/cemfirat/ccf-sites-ads-wordpress-connector'], 'ccf-google-ads-site-connector/ccf-google-ads-site-connector.php', ['de_DE']);
if (($legacy['version'] ?? '') !== '1.3.12') throw new RuntimeException('Updater did not read a legacy wordpress-v tag.');
if (($legacy['package'] ?? '') !== 'https://github.com/cemfirat/ccf-sites-ads-wordpress-connector/releases/download/wordpress-v1.3.12/ccf-sites-ads-connector.zip') throw new RuntimeException('Updater did not accept the previous repository URL.');
echo "WordPress updater discovery, forced refresh and auto-update support passed.\n";
