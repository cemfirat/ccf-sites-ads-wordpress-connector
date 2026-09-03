<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('CCF_GADS_SITE_TOKEN', 'test-control-token-test-control-token-1234567890');
define('CCF_GADS_CLIENT_ID', 'example-customer');
define('CCF_GADS_SITE_ID', 'example-site');

$GLOBALS['ccf_transients'] = [];
$GLOBALS['ccf_meta'] = [
    'rank_math_title' => 'Old SEO title',
    '_yootheme_builder' => '{"type":"layout","children":[]}',
];

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code, $message, $data = []) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }
}

class Test_Request {
    private $method;
    private $route;
    private $body;
    private $headers;
    private $query;
    private $params;

    public function __construct($method, $route, $body = '', $headers = [], $query = [], $params = []) {
        $this->method = $method;
        $this->route = $route;
        $this->body = $body;
        $this->headers = $headers;
        $this->query = $query;
        $this->params = $params;
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_body() { return $this->body; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? ''; }
    public function get_query_params() { return $this->query; }
    public function get_json_params() { return json_decode($this->body, true); }
}

function add_action(...$args): void {}
function add_filter(...$args): void {}
function register_rest_route(...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_key($value): string { return strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', (string) $value)); }
function wp_kses_post($value): string { return (string) $value; }
function wp_json_encode($value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function get_transient($key) { return $GLOBALS['ccf_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl): bool { $GLOBALS['ccf_transients'][$key] = $value; return true; }
function get_option($name, $default = false) { return $default; }
function wp_salt($scheme): string { return 'test-salt-' . $scheme; }
function esc_url_raw($value): string { return (string) $value; }
function wp_parse_url($value, $component = -1) { return parse_url((string) $value, $component); }
function home_url($path = '/'): string { return 'https://customer.example' . $path; }
function get_post($id) {
    if ((int) $id !== 42) return null;
    return (object) [
        'ID' => 42,
        'post_type' => 'page',
        'post_name' => 'services',
        'post_status' => 'publish',
        'post_title' => 'Old title',
        'post_content' => '<p>Old content</p>',
        'post_excerpt' => '',
        'post_modified_gmt' => '2026-08-29 10:00:00',
    ];
}
function get_post_meta($post_id, $key, $single = false) { return $GLOBALS['ccf_meta'][$key] ?? ''; }
function get_permalink($post_id): string { return 'https://customer.example/services/'; }

require __DIR__ . '/../wordpress/ccf-google-ads-site-connector.php';

$timestamp = time();
$nonce = 'nonce-control-test-12345';
$body = '';
$unsigned = new Test_Request('GET', '/ccf-sites/v1/status', $body);
$payload = CCF_Sites_Control::signature_payload($unsigned, $timestamp, $nonce, $body);
$signature = hash_hmac('sha256', $payload, CCF_GADS_SITE_TOKEN);
$signed = new Test_Request('GET', '/ccf-sites/v1/status', $body, [
    'x-ccf-timestamp' => (string) $timestamp,
    'x-ccf-nonce' => $nonce,
    'x-ccf-signature' => $signature,
]);

if (CCF_Sites_Control::authorize($signed) !== true) {
    throw new RuntimeException('Valid signed control request was rejected.');
}
$replay = CCF_Sites_Control::authorize($signed);
if (!$replay instanceof WP_Error || $replay->code !== 'ccf_control_replay') {
    throw new RuntimeException('Control nonce replay was not blocked.');
}

$preview_request = new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/preview',
    json_encode([
        'operation' => 'post.update',
        'target' => ['post_id' => 42],
        'changes' => ['post_title' => 'New title'],
    ], JSON_THROW_ON_ERROR)
);
$preview = CCF_Sites_Control::preview($preview_request);
if (!$preview instanceof WP_REST_Response || $preview->status !== 200) {
    throw new RuntimeException('Post change preview failed.');
}
$plan = $preview->data['data']['preview'] ?? null;
if (!is_array($plan) || ($plan['diff']['post_title']['before'] ?? '') !== 'Old title' || ($plan['diff']['post_title']['after'] ?? '') !== 'New title') {
    throw new RuntimeException('Post change preview produced an invalid diff.');
}
if (($plan['requires_approval'] ?? false) !== true || strpos((string) ($plan['before_checksum'] ?? ''), 'sha256:') !== 0) {
    throw new RuntimeException('Post change preview is missing approval/checksum safeguards.');
}

$seo_request = new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/preview',
    json_encode([
        'operation' => 'seo.update',
        'target' => ['post_id' => 42],
        'changes' => ['rank_math_title' => 'New SEO title'],
    ], JSON_THROW_ON_ERROR)
);
$seo = CCF_Sites_Control::preview($seo_request);
if (($seo->data['data']['preview']['diff']['rank_math_title']['after'] ?? '') !== 'New SEO title') {
    throw new RuntimeException('Rank Math preview failed.');
}

echo "WordPress control signature, replay protection and previews passed.\n";
