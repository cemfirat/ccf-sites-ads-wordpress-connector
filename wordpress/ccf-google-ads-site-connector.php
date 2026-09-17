<?php
/**
 * Plugin Name: CCF Sites & Ads Connector
 * Description: Secure WordPress control, content administration and conversion connector for CCF Sites & Ads.
 * Version: 1.3.1
 * Author: Cem Firat
 * Requires PHP: 7.4
 * Update URI: https://github.com/cemfirat/ccf-sites-ads-wordpress-connector
 */

defined('ABSPATH') || exit;

define('CCF_SITES_ADS_PLUGIN_VERSION', '1.3.1');
define('CCF_SITES_ADS_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/ccf-site-connector-runtime.inc';

if (function_exists('remove_filter')) {
    remove_filter('pre_set_site_transient_update_plugins', [CCF_Google_Ads_Site_Connector::class, 'check_for_update']);
    remove_filter('plugins_api', [CCF_Google_Ads_Site_Connector::class, 'plugin_information'], 20);
}

require_once __DIR__ . '/includes/class-ccf-sites-content-admin.php';
CCF_Sites_Content_Admin::init();
require_once __DIR__ . '/includes/class-ccf-sites-draft-seo.php';
CCF_Sites_Draft_SEO::init();
require_once __DIR__ . '/includes/class-ccf-sites-status-overlay.php';
CCF_Sites_Status_Overlay::init();
require_once __DIR__ . '/includes/class-ccf-sites-authors.php';
CCF_Sites_Authors::init();

final class CCF_Sites_Ads_Plugin_Updater {
    private const REPOSITORY = 'cemfirat/ccf-sites-ads-wordpress-connector';
    private const ASSET = 'ccf-sites-ads-connector.zip';
    private const CACHE_KEY = 'ccf_sites_ads_github_release_v4';
    private const CACHE_TTL = 60 * 60;

    public static function init(): void {
        add_filter('update_plugins_github.com', [self::class, 'host_update'], 10, 4);
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check']);
        add_filter('plugins_api', [self::class, 'information'], 20, 3);
    }

    public static function host_update($update, $plugin_data, $plugin_file, $locales) {
        if (($plugin_data['UpdateURI'] ?? '') !== 'https://github.com/' . self::REPOSITORY) {
            return $update;
        }
        $release = self::latest_release();
        if ($release === null) {
            return $update;
        }
        return self::update_payload($release);
    }

    public static function check($transient) {
        if (!is_object($transient) || !isset($transient->checked) || !is_array($transient->checked)) return $transient;
        $plugin = plugin_basename(CCF_SITES_ADS_PLUGIN_FILE);
        if (!array_key_exists($plugin, $transient->checked)) return $transient;
        $release = self::latest_release();
        if ($release === null) return $transient;

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $payload = (object) (self::update_payload($release) + ['plugin' => $plugin]);
        if (version_compare(CCF_SITES_ADS_PLUGIN_VERSION, $release['version'], '<')) {
            unset($transient->no_update[$plugin]);
            $transient->response[$plugin] = $payload;
        } else {
            unset($transient->response[$plugin]);
            $transient->no_update[$plugin] = $payload;
        }
        return $transient;
    }

    public static function information($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== 'ccf-google-ads-site-connector') return $result;
        $release = self::latest_release();
        if ($release === null) return $result;
        return (object) [
            'name' => 'CCF Sites & Ads Connector',
            'slug' => 'ccf-google-ads-site-connector',
            'version' => $release['version'],
            'author' => 'Cem Firat',
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => $release['tested'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Sicherer WordPress-, Rank-Math-, YOOtheme-, ACF-, Content- und Conversion-Connector für CCF Sites & Ads.',
                'changelog' => nl2br(esc_html($release['notes'])),
            ],
        ];
    }

    private static function update_payload(array $release): array {
        return [
            'id' => 'https://github.com/' . self::REPOSITORY,
            'slug' => 'ccf-google-ads-site-connector',
            'version' => $release['version'],
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'tested' => $release['tested'],
            'requires_php' => '7.4',
        ];
    }

    private static function latest_release(): ?array {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) return $cached;
        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            [
                'timeout' => 10,
                'redirection' => 2,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'CCF-Sites-Ads-WordPress-Updater/' . CCF_SITES_ADS_PLUGIN_VERSION,
                ],
            ]
        );
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !empty($payload['draft']) || !empty($payload['prerelease'])) return null;
        $tag = isset($payload['tag_name']) ? (string) $payload['tag_name'] : '';
        $version = preg_replace('/^(?:wordpress-)?v/i', '', $tag);
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) return null;
        $release_url = isset($payload['html_url']) ? esc_url_raw((string) $payload['html_url']) : '';
        $release_prefix = 'https://github.com/' . self::REPOSITORY . '/releases/';
        if ($release_url === '' || strpos($release_url, $release_prefix) !== 0) return null;
        $package = '';
        foreach (($payload['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== self::ASSET) continue;
            $candidate = esc_url_raw((string) ($asset['browser_download_url'] ?? ''));
            if (strpos($candidate, $release_prefix . 'download/') === 0 && substr($candidate, -strlen('/' . self::ASSET)) === '/' . self::ASSET) {
                $package = $candidate;
                break;
            }
        }
        if ($package === '') return null;
        $release = [
            'version' => $version,
            'url' => $release_url,
            'package' => $package,
            'tested' => '6.9',
            'notes' => isset($payload['body']) && is_string($payload['body']) ? substr($payload['body'], 0, 8000) : 'Aktualisierung des CCF Sites & Ads Connectors.',
        ];
        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }
}

CCF_Sites_Ads_Plugin_Updater::init();

if (function_exists('register_activation_hook')) {
    register_activation_hook(CCF_SITES_ADS_PLUGIN_FILE, [CCF_Sites_Control::class, 'activate']);
}
