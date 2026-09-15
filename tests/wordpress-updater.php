<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['ccf_filters'] = [];
$GLOBALS['ccf_transients'] = [];

function add_action(...$args): void {}
function add_filter(string $name, $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['ccf_filters'][$name][] = [$callback, $priority, $accepted_args];
}
function remove_filter(string $name, $callback, int $priority = 10): bool {
    if (!isset($GLOBALS['ccf_filters'][$name])) return false;
    $before = count($GLOBALS['ccf_filters'][$name]);
    $GLOBALS['ccf_filters'][$name] = array_values(array_filter(
        $GLOBALS['ccf_filters'][$name],
        static function ($entry) use ($callback, $priority) {
            return !($entry[0] === $callback && $entry[1] === $priority);
        }
    ));
    return count($GLOBALS['ccf_filters'][$name]) < $before;
}
function plugin_basename(string $file): string { return 'ccf-google-ads-site-connector/' . basename($file); }
function get_option(string $name, $default = false) { return $default; }
function get_transient(string $name) { return $GLOBALS['ccf_transients'][$name] ?? false; }
function set_transient(string $name, $value, int $ttl): bool { $GLOBALS['ccf_transients'][$name] = $value; return $ttl > 0; }
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response): string { return (string) ($response['body'] ?? ''); }
function esc_url_raw(string $value): string { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function wp_remote_get(string $url, array $options): array {
    if ($url !== 'https://api.github.com/repos/cemfirat/ccf-sites-ads-wordpress-connector/releases/latest') {
        throw new RuntimeException('Unexpected release API URL.');
    }
    if (($options['headers']['User-Agent'] ?? '') !== 'CCF-Sites-Ads-WordPress-Updater/1.2.0') {
        throw new RuntimeException('Updater user agent is missing or stale.');
    }
    return [
        'response' => ['code' => 200],
        'body' => json_encode([
            'tag_name' => 'wordpress-v1.3.0',
            'html_url' => 'https://github.com/cemfirat/ccf-sites-ads-wordpress-connector/releases/tag/wordpress-v1.3.0',
            'draft' => false,
            'prerelease' => false,
            'target_commitish' => 'main',
            'body' => 'Updater test release',
            'assets' => [[
                'name' => 'ccf-sites-ads-connector.zip',
                'browser_download_url' => 'https://github.com/cemfirat/ccf-sites-ads-wordpress-connector/releases/download/wordpress-v1.3.0/ccf-sites-ads-connector.zip',
            ]],
        ], JSON_THROW_ON_ERROR),
    ];
}

require __DIR__ . '/../wordpress/ccf-google-ads-site-connector.php';

if (!defined('CCF_SITES_ADS_PLUGIN_VERSION') || CCF_SITES_ADS_PLUGIN_VERSION !== '1.2.0') {
    throw new RuntimeException('Plugin version constant is not v1.2.0.');
}

$transient = (object) [
    'checked' => ['ccf-google-ads-site-connector/ccf-google-ads-site-connector.php' => '1.2.0'],
    'response' => [],
];
$updated = CCF_Sites_Ads_Plugin_Updater::check($transient);
$offer = $updated->response['ccf-google-ads-site-connector/ccf-google-ads-site-connector.php'] ?? null;
if (!is_object($offer) || $offer->new_version !== '1.3.0') {
    throw new RuntimeException('A newer stable release was not offered.');
}
if ($offer->package !== 'https://github.com/cemfirat/ccf-sites-ads-wordpress-connector/releases/download/wordpress-v1.3.0/ccf-sites-ads-connector.zip') {
    throw new RuntimeException('Updater accepted an unexpected package URL.');
}

echo "WordPress GitHub updater discovery passed.\n";
