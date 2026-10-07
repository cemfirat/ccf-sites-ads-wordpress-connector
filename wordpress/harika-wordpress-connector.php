<?php
/**
 * Plugin Name: Harika Connector
 * Description: Secure WordPress control, content administration and conversion connector for Harika.
 * Version: 1.3.11
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Cem Firat
 * Update URI: https://github.com/cemfirat/harika-wordpress-connector
 * Text Domain: ccf-google-ads-site-connector
 */

defined('ABSPATH') || exit;

define('CCF_SITES_ADS_PLUGIN_VERSION', '1.3.11');
define('CCF_SITES_ADS_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/harika-connector-runtime.inc';

if (function_exists('remove_filter')) {
    remove_filter('pre_set_site_transient_update_plugins', [CCF_Google_Ads_Site_Connector::class, 'check_for_update']);
    remove_filter('plugins_api', [CCF_Google_Ads_Site_Connector::class, 'plugin_information'], 20);
}

require_once __DIR__ . '/includes/class-ccf-sites-content-admin.php';
CCF_Sites_Content_Admin::init();
require_once __DIR__ . '/includes/class-ccf-sites-draft-seo.php';
CCF_Sites_Draft_SEO::init();
require_once __DIR__ . '/includes/class-ccf-sites-rank-math.php';
CCF_Sites_Rank_Math::init();
require_once __DIR__ . '/includes/class-ccf-sites-status-overlay.php';
CCF_Sites_Status_Overlay::init();
require_once __DIR__ . '/includes/class-ccf-sites-authors.php';
CCF_Sites_Authors::init();

final class CCF_Sites_Ads_Plugin_Updater {
    private const REPOSITORY = 'cemfirat/harika-wordpress-connector';
    private const LEGACY_REPOSITORY = 'cemfirat/ccf-sites-ads-wordpress-connector';
    private const ASSET = 'ccf-sites-ads-connector.zip';
    private const CACHE_KEY = 'ccf_sites_ads_github_release_v7';
    private const CACHE_TTL = 5 * 60;

    public static function init(): void {
        add_filter('update_plugins_github.com', [self::class, 'host_update'], 10, 4);
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check']);
        add_filter('plugins_api', [self::class, 'information'], 20, 3);
        add_action('load-update-core.php', [self::class, 'refresh_on_manual_check']);
    }

    public static function refresh_on_manual_check(): void {
        if (isset($_GET['force-check']) && (string) $_GET['force-check'] === '1') {
            delete_transient(self::CACHE_KEY);
        }
    }

    public static function host_update($update, $plugin_data, $plugin_file, $locales) {
        if (!self::is_our_update_uri((string) ($plugin_data['UpdateURI'] ?? ''))) return $update;
        $release = self::latest_release();
        return $release === null ? $update : self::update_payload($release);
    }

    public static function check($transient) {
        if (!is_object($transient) || !isset($transient->checked) || !is_array($transient->checked)) return $transient;
        $plugin = plugin_basename(CCF_SITES_ADS_PLUGIN_FILE);
        if (!array_key_exists($plugin, $transient->checked)) return $transient;
        $release = self::latest_release();
        if ($release === null) return $transient;
        if (!isset($transient->response) || !is_array($transient->response)) $transient->response = [];
        if (!isset($transient->no_update) || !is_array($transient->no_update)) $transient->no_update = [];
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
        return (object) ['name'=>'Harika Connector','slug'=>'ccf-google-ads-site-connector','version'=>$release['version'],'author'=>'Cem Firat','homepage'=>'https://github.com/'.self::REPOSITORY,'requires'=>'6.0','requires_php'=>'7.4','tested'=>$release['tested'],'download_link'=>$release['package'],'sections'=>['description'=>'Sicherer WordPress-, Rank-Math-, YOOtheme-, ACF-, Content- und Conversion-Connector für Harika.','changelog'=>nl2br(esc_html($release['notes']))]];
    }

    private static function update_payload(array $release): array { return ['id'=>'https://github.com/'.$release['repository'],'slug'=>'ccf-google-ads-site-connector','version'=>$release['version'],'new_version'=>$release['version'],'url'=>$release['url'],'package'=>$release['package'],'tested'=>$release['tested'],'requires_php'=>'7.4']; }

    private static function latest_release(): ?array {
        $cached = get_transient(self::CACHE_KEY); if (is_array($cached) && isset($cached['repository'], $cached['package'])) return $cached;
        foreach ([self::REPOSITORY, self::LEGACY_REPOSITORY] as $repository) {
            $release = self::release_from($repository);
            if ($release !== null) { set_transient(self::CACHE_KEY, $release, self::CACHE_TTL); return $release; }
        }
        return null;
    }

    private static function release_from(string $repository): ?array {
        $response = wp_remote_get('https://api.github.com/repos/'.$repository.'/releases/latest',['timeout'=>10,'redirection'=>2,'headers'=>['Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28','User-Agent'=>'Harika-Connector/'.CCF_SITES_ADS_PLUGIN_VERSION]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $payload = json_decode(wp_remote_retrieve_body($response), true); if (!is_array($payload) || !empty($payload['draft']) || !empty($payload['prerelease'])) return null;
        $tag = isset($payload['tag_name']) ? (string)$payload['tag_name'] : ''; $version = self::version_from_tag($tag);
        if ($version === null) return null;
        $release_url = isset($payload['html_url']) ? esc_url_raw((string)$payload['html_url']) : '';
        $package=''; foreach (($payload['assets']??[]) as $asset) { if (!is_array($asset)||($asset['name']??'')!==self::ASSET) continue; $candidate=esc_url_raw((string)($asset['browser_download_url']??'')); if ($candidate!==''){$package=$candidate;break;} }
        $matched = self::matching_repository($release_url, $package);
        if ($matched === null) return null;
        return ['repository'=>$matched,'version'=>$version,'url'=>$release_url,'package'=>$package,'tested'=>'7.1','notes'=>isset($payload['body'])&&is_string($payload['body'])?substr($payload['body'],0,8000):'Aktualisierung des Harika Connectors.'];
    }

    private static function version_from_tag(string $tag): ?string {
        foreach (['harika-wordpress-connector-v', 'wordpress-v', 'v'] as $prefix) {
            if (stripos($tag, $prefix) !== 0) continue;
            $version = substr($tag, strlen($prefix));
            return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) ? $version : null;
        }
        return null;
    }

    private static function is_our_update_uri(string $uri): bool {
        return $uri === 'https://github.com/' . self::REPOSITORY || $uri === 'https://github.com/' . self::LEGACY_REPOSITORY;
    }

    private static function matching_repository(string $release_url, string $package): ?string {
        foreach ([self::REPOSITORY, self::LEGACY_REPOSITORY] as $repository) {
            $prefix = 'https://github.com/' . $repository . '/releases/';
            if ($release_url !== '' && strpos($release_url, $prefix) === 0 && strpos($package, $prefix . 'download/') === 0 && substr($package, -strlen('/' . self::ASSET)) === '/' . self::ASSET) return $repository;
        }
        return null;
    }
}

CCF_Sites_Ads_Plugin_Updater::init();

if (function_exists('register_activation_hook')) register_activation_hook(CCF_SITES_ADS_PLUGIN_FILE, [CCF_Sites_Control::class, 'activate']);
