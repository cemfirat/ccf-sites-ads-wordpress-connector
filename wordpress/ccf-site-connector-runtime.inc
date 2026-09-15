<?php
/**
 * Plugin Name: CCF Sites & Ads Connector
 * Description: Secure WordPress control and conversion connector for CCF Sites & Ads.
 * Version: 1.1.1
 * Author: Cem Firat
 * Requires PHP: 7.4
 * Update URI: https://github.com/cemfirat/ccf-sites-ads-wordpress-connector
 */

defined('ABSPATH') || exit;

final class CCF_Google_Ads_Site_Connector {
    private const VERSION = '1.1.1';
    private const PLUGIN_SLUG = 'ccf-google-ads-site-connector';
    private const GITHUB_REPOSITORY = 'cemfirat/ccf-sites-ads-wordpress-connector';
    private const RELEASE_ASSET = 'ccf-sites-ads-connector.zip';
    private const RELEASE_CACHE_KEY = 'ccf_sites_ads_github_release_v1';
    private const REST_NAMESPACE = 'ccf-google-ads/v1';
    private const OPTION_NAME = 'ccf_gads_settings';
    private const ENCRYPTION_CONTEXT = 'ccf-google-ads-site-token-v1';
    private const SIGNATURE_TTL = 300;
    private const RATE_LIMIT = 60;
    private const RATE_WINDOW = 300;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_script']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check_for_update']);
        add_filter('plugins_api', [self::class, 'plugin_information'], 20, 3);
    }

    public static function check_for_update($transient) {
        if (!is_object($transient) || !isset($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }
        $release = self::latest_release();
        if ($release === null || version_compare(self::VERSION, $release['version'], '>=')) {
            return $transient;
        }
        $plugin = plugin_basename(__FILE__);
        $transient->response[$plugin] = (object) [
            'id' => 'https://github.com/' . self::GITHUB_REPOSITORY,
            'slug' => self::PLUGIN_SLUG,
            'plugin' => $plugin,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'tested' => $release['tested'],
            'requires_php' => '7.4',
        ];
        return $transient;
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::PLUGIN_SLUG) {
            return $result;
        }
        $release = self::latest_release();
        if ($release === null) {
            return $result;
        }
        return (object) [
            'name' => 'CCF Sites & Ads Connector',
            'slug' => self::PLUGIN_SLUG,
            'version' => $release['version'],
            'author' => 'Cem Firat',
            'homepage' => 'https://github.com/' . self::GITHUB_REPOSITORY,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => $release['tested'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Sicherer WordPress-, Rank-Math-, YOOtheme- und Conversion-Connector für CCF Sites & Ads.',
                'changelog' => nl2br(esc_html($release['notes'])),
            ],
        ];
    }

    private static function latest_release(): ?array {
        $cached = get_transient(self::RELEASE_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPOSITORY . '/releases/latest',
            [
                'timeout' => 10,
                'redirection' => 2,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'CCF-Sites-Ads-WordPress-Updater/' . self::VERSION,
                ],
            ]
        );
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !empty($payload['draft']) || !empty($payload['prerelease'])) {
            return null;
        }
        $tag = isset($payload['tag_name']) ? (string) $payload['tag_name'] : '';
        $version = preg_replace('/^(?:wordpress-)?v/i', '', $tag);
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }
        $release_url = isset($payload['html_url']) ? esc_url_raw((string) $payload['html_url']) : '';
        $release_prefix = 'https://github.com/' . self::GITHUB_REPOSITORY . '/releases/';
        if ($release_url === '' || strpos($release_url, $release_prefix) !== 0) {
            return null;
        }
        $package = '';
        foreach (($payload['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== self::RELEASE_ASSET) {
                continue;
            }
            $candidate = esc_url_raw((string) ($asset['browser_download_url'] ?? ''));
            if (strpos($candidate, $release_prefix . 'download/') === 0 && substr($candidate, -strlen('/' . self::RELEASE_ASSET)) === '/' . self::RELEASE_ASSET) {
                $package = $candidate;
                break;
            }
        }
        if ($package === '') {
            return null;
        }
        $release = [
            'version' => $version,
            'url' => $release_url,
            'package' => $package,
            'tested' => isset($payload['target_commitish']) ? '6.8' : '6.0',
            'notes' => isset($payload['body']) && is_string($payload['body'])
                ? substr($payload['body'], 0, 8_000)
                : 'Aktualisierung des CCF Sites & Ads Connectors.',
        ];
        set_transient(self::RELEASE_CACHE_KEY, $release, 6 * 60 * 60);
        return $release;
    }

    public static function register_admin_page(): void {
        add_options_page(
            'CCF Sites & Ads',
            'CCF Sites & Ads',
            'manage_options',
            'ccf-google-ads',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting('ccf_gads', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_settings'],
            'default' => [],
        ]);
    }

    public static function sanitize_settings($input): array {
        $current = self::settings();
        $input = is_array($input) ? $input : [];

        $environment = isset($input['environment']) ? sanitize_key((string) $input['environment']) : 'production';
        if (!in_array($environment, ['development', 'staging', 'production'], true)) {
            $environment = 'production';
        }

        $endpoint = isset($input['endpoint']) ? esc_url_raw(trim((string) $input['endpoint'])) : '';
        $scheme = strtolower((string) wp_parse_url($endpoint, PHP_URL_SCHEME));
        if ($endpoint === '' || ($environment === 'production' && $scheme !== 'https')) {
            add_settings_error(self::OPTION_NAME, 'ccf_invalid_endpoint', 'Für die Produktion ist ein gültiger HTTPS-Control-Endpunkt erforderlich.');
            $endpoint = isset($current['endpoint']) ? (string) $current['endpoint'] : '';
        }

        $client_id = isset($input['client_id']) ? sanitize_key((string) $input['client_id']) : '';
        $site_id = isset($input['site_id']) ? sanitize_key((string) $input['site_id']) : '';
        if ($client_id === '' || $site_id === '') {
            add_settings_error(self::OPTION_NAME, 'ccf_invalid_site', 'Client-ID und Site-ID dürfen nicht leer sein.');
            $client_id = $client_id !== '' ? $client_id : (isset($current['client_id']) ? sanitize_key((string) $current['client_id']) : '');
            $site_id = $site_id !== '' ? $site_id : (isset($current['site_id']) ? sanitize_key((string) $current['site_id']) : '');
        }

        $encrypted_token = isset($current['encrypted_token']) ? (string) $current['encrypted_token'] : '';
        if (isset($input['encrypted_token'])) {
            $candidate = (string) $input['encrypted_token'];
            if (self::decrypt_token($candidate) !== '') {
                $encrypted_token = $candidate;
            }
        }
        $token = isset($input['site_token']) ? trim((string) $input['site_token']) : '';
        if ($token !== '') {
            if (strlen($token) < 32) {
                add_settings_error(self::OPTION_NAME, 'ccf_invalid_token', 'Der Site-Token muss mindestens 32 Zeichen lang sein.');
            } else {
                $encrypted = self::encrypt_token($token);
                if ($encrypted === '') {
                    add_settings_error(self::OPTION_NAME, 'ccf_token_encryption_failed', 'Der Site-Token konnte nicht sicher verschlüsselt werden.');
                } else {
                    $encrypted_token = $encrypted;
                }
            }
        }

        return [
            'endpoint' => $endpoint,
            'client_id' => $client_id,
            'site_id' => $site_id,
            'environment' => $environment,
            'encrypted_token' => $encrypted_token,
        ];
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::settings();
        $configured = self::site_token() !== '';
        ?>
        <div class="wrap">
            <h1>CCF Sites &amp; Ads Connector</h1>
            <p>Der Site-Token wird mit den WordPress-Server-Salts verschlüsselt gespeichert und niemals im Browser ausgegeben.</p>
            <?php settings_errors(self::OPTION_NAME); ?>
            <form action="options.php" method="post" autocomplete="off">
                <?php settings_fields('ccf_gads'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccf-gads-endpoint">Control-Endpunkt</label></th>
                        <td><input class="regular-text code" id="ccf-gads-endpoint" name="<?php echo esc_attr(self::OPTION_NAME); ?>[endpoint]" type="url" required value="<?php echo esc_attr(isset($settings['endpoint']) ? (string) $settings['endpoint'] : ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccf-gads-client">Client-ID</label></th>
                        <td><input class="regular-text code" id="ccf-gads-client" name="<?php echo esc_attr(self::OPTION_NAME); ?>[client_id]" type="text" required value="<?php echo esc_attr(isset($settings['client_id']) ? (string) $settings['client_id'] : ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccf-gads-site">Site-ID</label></th>
                        <td><input class="regular-text code" id="ccf-gads-site" name="<?php echo esc_attr(self::OPTION_NAME); ?>[site_id]" type="text" required value="<?php echo esc_attr(isset($settings['site_id']) ? (string) $settings['site_id'] : ''); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccf-gads-environment">Umgebung</label></th>
                        <td>
                            <select id="ccf-gads-environment" name="<?php echo esc_attr(self::OPTION_NAME); ?>[environment]">
                                <?php foreach (['development', 'staging', 'production'] as $value) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected(isset($settings['environment']) ? (string) $settings['environment'] : 'production', $value); ?>><?php echo esc_html($value); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccf-gads-token">Site-Token</label></th>
                        <td>
                            <input class="regular-text code" id="ccf-gads-token" name="<?php echo esc_attr(self::OPTION_NAME); ?>[site_token]" type="password" minlength="32" value="" autocomplete="new-password">
                            <p class="description">Status: <strong><?php echo $configured ? 'konfiguriert' : 'nicht konfiguriert'; ?></strong>. Leer lassen, um den bestehenden Token unverändert zu behalten.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Konfiguration speichern'); ?>
            </form>
        </div>
        <?php
    }

    public static function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/bootstrap', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle_bootstrap'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::REST_NAMESPACE, '/event', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_event'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function enqueue_script(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_script(
            'ccf-google-ads-site-connector',
            plugins_url('assets/ccf-tracking.js', __FILE__),
            [],
            self::VERSION,
            true
        );

        wp_localize_script('ccf-google-ads-site-connector', 'CCFGoogleAdsConfig', [
            'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . '/event')),
            'bootstrapUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . '/bootstrap')),
            'clientId' => self::client_id(),
            'siteId' => self::site_id(),
            'environment' => self::environment(),
        ]);
    }

    public static function handle_bootstrap(WP_REST_Request $request) {
        $issued = time();
        $signature = hash_hmac(
            'sha256',
            self::signature_payload($issued, self::client_id(), self::site_id()),
            wp_salt('auth')
        );

        $response = new WP_REST_Response([
            'issued' => (string) $issued,
            'signature' => $signature,
            'expires_in' => self::SIGNATURE_TTL,
        ], 200);
        $response->header('Cache-Control', 'no-store, max-age=0');
        return $response;
    }

    public static function handle_event(WP_REST_Request $request) {
        $config_error = self::validate_runtime_config();
        if ($config_error instanceof WP_Error) {
            return $config_error;
        }

        $issued = (int) $request->get_header('x-ccf-wp-issued');
        $signature = (string) $request->get_header('x-ccf-wp-signature');
        if (!self::valid_page_signature($issued, $signature)) {
            return new WP_Error('ccf_invalid_signature', 'Invalid or expired connector signature.', ['status' => 403]);
        }

        if (!self::origin_allowed($request)) {
            return new WP_Error('ccf_origin_denied', 'Request origin is not allowed.', ['status' => 403]);
        }

        if (!self::within_rate_limit()) {
            return new WP_Error('ccf_rate_limited', 'Too many connector requests.', ['status' => 429]);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('ccf_invalid_event', 'Event body must be JSON.', ['status' => 400]);
        }

        $event = self::normalize_event($payload);
        if ($event instanceof WP_Error) {
            return $event;
        }

        return self::forward_event($event);
    }

    private static function validate_runtime_config() {
        $endpoint = self::endpoint();
        if ($endpoint === '') {
            return new WP_Error('ccf_not_configured', 'Control endpoint is not configured.', ['status' => 503]);
        }

        $scheme = strtolower((string) wp_parse_url($endpoint, PHP_URL_SCHEME));
        if (self::environment() === 'production' && $scheme !== 'https') {
            return new WP_Error('ccf_insecure_endpoint', 'Production control endpoint must use HTTPS.', ['status' => 503]);
        }

        if (strlen(self::site_token()) < 32) {
            return new WP_Error('ccf_not_configured', 'Site connector token is not configured.', ['status' => 503]);
        }
        if (self::client_id() === '' || self::site_id() === '') {
            return new WP_Error('ccf_not_configured', 'Client ID and site ID are not configured.', ['status' => 503]);
        }
        return true;
    }

    private static function valid_page_signature(int $issued, string $signature): bool {
        if ($issued <= 0 || $signature === '') {
            return false;
        }
        if (abs(time() - $issued) > self::SIGNATURE_TTL) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            self::signature_payload($issued, self::client_id(), self::site_id()),
            wp_salt('auth')
        );

        return hash_equals($expected, $signature);
    }

    private static function signature_payload(int $issued, string $client_id, string $site_id): string {
        return $issued . '|' . $client_id . '|' . $site_id;
    }

    private static function origin_allowed(WP_REST_Request $request): bool {
        $expected_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_string($expected_host) || $expected_host === '') {
            return false;
        }

        $origin = $request->get_header('origin');
        $referer = $request->get_header('referer');
        $candidate = $origin !== '' ? $origin : $referer;
        if ($candidate === '') {
            return false;
        }

        $host = wp_parse_url($candidate, PHP_URL_HOST);
        return is_string($host) && strtolower($host) === strtolower($expected_host);
    }

    private static function within_rate_limit(): bool {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'ccf_gads_rl_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    private static function normalize_event(array $payload) {
        $allowed_types = ['lead', 'phone_click', 'email_click', 'whatsapp_click', 'form_submit', 'conversion'];
        $event_type = isset($payload['event_type']) ? sanitize_key((string) $payload['event_type']) : '';
        if (!in_array($event_type, $allowed_types, true)) {
            return new WP_Error('ccf_invalid_event', 'Unsupported event type.', ['status' => 400]);
        }

        $event_id = isset($payload['event_id']) ? sanitize_text_field((string) $payload['event_id']) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $event_id)) {
            return new WP_Error('ccf_invalid_event', 'Invalid event ID.', ['status' => 400]);
        }

        $occurred_at = isset($payload['occurred_at']) ? sanitize_text_field((string) $payload['occurred_at']) : '';
        if ($occurred_at === '' || strtotime($occurred_at) === false) {
            return new WP_Error('ccf_invalid_event', 'Invalid event timestamp.', ['status' => 400]);
        }

        $consent_in = isset($payload['consent']) && is_array($payload['consent']) ? $payload['consent'] : [];
        $ads = isset($consent_in['ads']) && $consent_in['ads'] === true;
        $analytics = isset($consent_in['analytics']) && $consent_in['analytics'] === true;
        if (!$ads) {
            return new WP_Error('ccf_consent_not_granted', 'Advertising consent is required.', ['status' => 403]);
        }

        $event = [
            'schema_version' => '1.0',
            'event_id' => $event_id,
            'event_type' => $event_type,
            'occurred_at' => $occurred_at,
            'site' => [
                'client_id' => self::client_id(),
                'site_id' => self::site_id(),
                'environment' => self::environment(),
                'hostname' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            ],
            'consent' => [
                'analytics' => $analytics,
                'ads' => true,
                'source' => isset($consent_in['source']) ? substr(sanitize_text_field((string) $consent_in['source']), 0, 64) : 'wordpress-adapter',
            ],
        ];

        if (isset($payload['context']) && is_array($payload['context'])) {
            $context = [];
            if (!empty($payload['context']['page_url'])) {
                $context['page_url'] = self::sanitize_context_url((string) $payload['context']['page_url']);
            }
            if (!empty($payload['context']['page_title'])) {
                $context['page_title'] = substr(sanitize_text_field((string) $payload['context']['page_title']), 0, 512);
            }
            if (!empty($payload['context']['referrer'])) {
                $context['referrer'] = self::sanitize_context_url((string) $payload['context']['referrer']);
            }
            $context = array_filter($context, static function ($value) {
                return $value !== '' && $value !== null;
            });
            if ($context !== []) {
                $event['context'] = $context;
            }
        }

        if (isset($payload['conversion']) && is_array($payload['conversion'])) {
            $conversion = [];
            if (isset($payload['conversion']['value']) && is_numeric($payload['conversion']['value'])) {
                $conversion['value'] = max(0, (float) $payload['conversion']['value']);
            }
            if (!empty($payload['conversion']['currency'])) {
                $currency = strtoupper(sanitize_text_field((string) $payload['conversion']['currency']));
                if (preg_match('/^[A-Z]{3}$/', $currency)) {
                    $conversion['currency'] = $currency;
                }
            }
            if (!empty($payload['conversion']['action_key'])) {
                $conversion['action_key'] = substr(sanitize_key((string) $payload['conversion']['action_key']), 0, 128);
            }
            if ($conversion !== []) {
                $event['conversion'] = $conversion;
            }
        }

        if (isset($payload['attribution']) && is_array($payload['attribution'])) {
            $attribution = [];
            foreach (['gclid', 'gbraid', 'wbraid'] as $key) {
                if (!empty($payload['attribution'][$key])) {
                    $attribution[$key] = substr(sanitize_text_field((string) $payload['attribution'][$key]), 0, 256);
                }
            }
            if ($attribution !== []) {
                $event['attribution'] = $attribution;
            }
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $metadata = [];
            foreach (array_slice($payload['metadata'], 0, 20, true) as $key => $value) {
                $safe_key = sanitize_key((string) $key);
                if ($safe_key === '') {
                    continue;
                }
                if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                    $metadata[$safe_key] = $value;
                } elseif (is_string($value)) {
                    $metadata[$safe_key] = substr(sanitize_text_field($value), 0, 256);
                }
            }
            if ($metadata !== []) {
                $event['metadata'] = $metadata;
            }
        }

        return $event;
    }

    private static function sanitize_context_url(string $value): string {
        $parts = wp_parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $url = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $url .= ':' . (int) $parts['port'];
        }
        $url .= isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';
        return esc_url_raw($url);
    }

    private static function forward_event(array $event) {
        $request_id = 'req_' . wp_generate_uuid4();
        $endpoint = rtrim(self::endpoint(), '/') . '/v1/events';
        $args = [
            'timeout' => 5,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . self::site_token(),
                'X-CCF-Client-ID' => self::client_id(),
                'X-CCF-Site-ID' => self::site_id(),
                'X-CCF-Timestamp' => (string) time(),
                'X-CCF-Request-ID' => $request_id,
            ],
            'body' => wp_json_encode($event),
        ];

        $last_error = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = wp_remote_post($endpoint, $args);
            if (is_wp_error($response)) {
                $last_error = $response;
            } else {
                $status = (int) wp_remote_retrieve_response_code($response);
                $body = json_decode((string) wp_remote_retrieve_body($response), true);
                if ($status >= 200 && $status < 300 && is_array($body)) {
                    return new WP_REST_Response($body, $status);
                }
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    return new WP_REST_Response(
                        is_array($body) ? $body : ['status' => 'error', 'error' => ['code' => 'UPSTREAM_REJECTED']],
                        $status
                    );
                }
                $last_error = new WP_Error('ccf_upstream_temporary', 'Temporary control-service failure.', ['status' => $status]);
            }

            if ($attempt < 2) {
                usleep(250000);
            }
        }

        return new WP_Error(
            'ccf_delivery_failed',
            'Event could not be delivered to the control service.',
            ['status' => 502, 'cause' => is_wp_error($last_error) ? $last_error->get_error_code() : 'unknown']
        );
    }

    public static function client_id(): string {
        if (defined('CCF_GADS_CLIENT_ID')) {
            return sanitize_key((string) CCF_GADS_CLIENT_ID);
        }
        $settings = self::settings();
        return isset($settings['client_id']) && (string) $settings['client_id'] !== '' ? sanitize_key((string) $settings['client_id']) : '';
    }

    public static function site_id(): string {
        if (defined('CCF_GADS_SITE_ID')) {
            return sanitize_key((string) CCF_GADS_SITE_ID);
        }
        $settings = self::settings();
        return isset($settings['site_id']) && (string) $settings['site_id'] !== '' ? sanitize_key((string) $settings['site_id']) : '';
    }

    public static function environment(): string {
        $settings = self::settings();
        $value = defined('CCF_GADS_ENVIRONMENT')
            ? sanitize_key((string) CCF_GADS_ENVIRONMENT)
            : (isset($settings['environment']) ? sanitize_key((string) $settings['environment']) : 'production');
        return in_array($value, ['development', 'staging', 'production'], true) ? $value : 'production';
    }

    private static function endpoint(): string {
        if (defined('CCF_GADS_CONTROL_ENDPOINT') && is_string(CCF_GADS_CONTROL_ENDPOINT)) {
            return esc_url_raw((string) CCF_GADS_CONTROL_ENDPOINT);
        }
        $settings = self::settings();
        return isset($settings['endpoint']) ? esc_url_raw((string) $settings['endpoint']) : '';
    }

    public static function site_token(): string {
        if (defined('CCF_GADS_SITE_TOKEN') && is_string(CCF_GADS_SITE_TOKEN)) {
            return (string) CCF_GADS_SITE_TOKEN;
        }
        $settings = self::settings();
        return isset($settings['encrypted_token']) ? self::decrypt_token((string) $settings['encrypted_token']) : '';
    }

    private static function settings(): array {
        $settings = get_option(self::OPTION_NAME, []);
        return is_array($settings) ? $settings : [];
    }

    private static function encryption_key(): string {
        return hash('sha256', wp_salt('auth') . '|' . self::ENCRYPTION_CONTEXT, true);
    }

    private static function encrypt_token(string $token): string {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }
        try {
            $iv = random_bytes(12);
        } catch (Exception $error) {
            return '';
        }
        $tag = '';
        $ciphertext = openssl_encrypt($token, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, self::ENCRYPTION_CONTEXT);
        if ($ciphertext === false || $tag === '') {
            return '';
        }
        $payload = wp_json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ]);
        return is_string($payload) ? base64_encode($payload) : '';
    }

    private static function decrypt_token(string $encrypted): string {
        if ($encrypted === '' || !function_exists('openssl_decrypt')) {
            return '';
        }
        $json = base64_decode($encrypted, true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return '';
        }
        $iv = isset($payload['iv']) ? base64_decode((string) $payload['iv'], true) : false;
        $tag = isset($payload['tag']) ? base64_decode((string) $payload['tag'], true) : false;
        $ciphertext = isset($payload['ciphertext']) ? base64_decode((string) $payload['ciphertext'], true) : false;
        if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext)) {
            return '';
        }
        $token = openssl_decrypt($ciphertext, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, self::ENCRYPTION_CONTEXT);
        return is_string($token) ? $token : '';
    }
}

CCF_Google_Ads_Site_Connector::init();

require_once __DIR__ . '/includes/class-ccf-sites-control.php';
CCF_Sites_Control::init();
if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, [CCF_Sites_Control::class, 'activate']);
}
