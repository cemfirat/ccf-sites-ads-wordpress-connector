<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('CCF_GADS_SITE_TOKEN', 'test-control-token-test-control-token-1234567890');
define('CCF_GADS_CLIENT_ID', 'example-customer');
define('CCF_GADS_SITE_ID', 'example-site');

$GLOBALS['ccf_transients'] = [];
$GLOBALS['ccf_meta'] = [
    'rank_math_title' => 'Old SEO title',
    '_yootheme_builder' => '{"type":"layout","children":[]}',
];
$GLOBALS['ccf_post'] = [
    'ID' => 42,
    'post_type' => 'post',
    'post_name' => 'services',
    'post_status' => 'draft',
    'post_title' => 'Old title',
    'post_content' => '<div uk-grid>Old & content \\ path</div>',
    'post_excerpt' => '',
    'post_date' => '2026-09-17 10:00:00',
    'post_date_gmt' => '2026-09-17 08:00:00',
    'post_author' => 7,
    'post_modified_gmt' => '2026-09-17 08:00:00',
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

class Test_Request implements ArrayAccess {
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
    public function get_param($name) { return $this->params[$name] ?? null; }
    public function offsetExists($offset): bool { return array_key_exists($offset, $this->params); }
    public function offsetGet($offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet($offset, $value): void { $this->params[$offset] = $value; }
    public function offsetUnset($offset): void { unset($this->params[$offset]); }
}

class Test_WPDB {
    public $prefix = 'wp_';
    private $rows = [];
    public function get_charset_collate(): string { return ''; }
    public function insert($table, $data, $formats = []) {
        $this->rows[$data['id']] = $data;
        return 1;
    }
    public function update($table, $data, $where, $data_formats = [], $where_formats = []) {
        $id = (string) ($where['id'] ?? '');
        if (!isset($this->rows[$id])) return false;
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return 1;
    }
    public function prepare($query, $value) { return [$query, $value]; }
    public function get_row($prepared, $format = null) {
        [$query, $value] = $prepared;
        if (strpos($query, 'idempotency_key') !== false) {
            foreach ($this->rows as $row) {
                if (($row['idempotency_key'] ?? '') === $value) return $row;
            }
            return null;
        }
        return $this->rows[(string) $value] ?? null;
    }
}
$GLOBALS['wpdb'] = new Test_WPDB();

function add_action(...$args): void {}
function add_filter($tag, $callback, $priority = 10, $args = 1): void { $GLOBALS['ccf_filters'][$tag][$priority][] = $callback; }
function remove_filter($tag, $callback, $priority = 10): void { $GLOBALS['ccf_filters'][$tag][$priority] = array_filter($GLOBALS['ccf_filters'][$tag][$priority] ?? [], fn($item) => $item !== $callback); }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function register_rest_route(...$args): void {}
function register_activation_hook(...$args): void {}
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
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Vienna'); }
function wp_timezone_string(): string { return 'Europe/Vienna'; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-09-17 12:00:00', wp_timezone()); }
function get_gmt_from_date($date, $format = 'Y-m-d H:i:s'): string {
    $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $date, wp_timezone());
    return $local->setTimezone(new DateTimeZone('UTC'))->format($format);
}
function get_date_from_gmt($date, $format = 'Y-m-d H:i:s'): string {
    $gmt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $date, new DateTimeZone('UTC'));
    return $gmt->setTimezone(wp_timezone())->format($format);
}
function get_user_by($field, $id) {
    $id = (int) $id;
    if (!in_array($id, [7, 8], true)) return false;
    return (object) ['ID' => $id, 'display_name' => $id === 7 ? 'Old Author' : 'AMBRA Redaktion'];
}
function user_can($user, $capability): bool { return in_array((int) ($user->ID ?? 0), [7, 8], true) && $capability === 'edit_posts'; }
function get_author_posts_url($id): string { return 'https://customer.example/author/' . (int) $id . '/'; }
function get_post($id) {
    if ((int) $id !== 42) return null;
    return (object) $GLOBALS['ccf_post'];
}
function wp_update_post($data, $wp_error = false) {
    if ((int) ($data['ID'] ?? 0) !== 42) return $wp_error ? new WP_Error('missing', 'missing') : 0;
    $postarr = $data;
    // Simulate core unslashing and KSES filtering before wp_insert_post_data.
    $data['post_content'] = str_replace(' uk-grid', '', $data['post_content'] ?? '');
    foreach ($GLOBALS['ccf_filters']['wp_insert_post_data'] ?? [] as $callbacks) foreach ($callbacks as $callback) $data = $callback($data, $postarr);
    $data = wp_unslash($data);
    unset($data['ID']);
    $GLOBALS['ccf_post'] = array_merge($GLOBALS['ccf_post'], $data);
    $GLOBALS['ccf_post']['post_modified_gmt'] = '2026-09-17 10:30:00';
    return 42;
}
function get_post_meta($post_id, $key, $single = false) { return $GLOBALS['ccf_meta'][$key] ?? ''; }
function update_post_meta($post_id, $key, $value) { $GLOBALS['ccf_meta'][$key] = $value; return true; }
function delete_post_meta($post_id, $key) { unset($GLOBALS['ccf_meta'][$key]); return true; }
function get_permalink($post_id): string { return 'https://customer.example/services/'; }
function update_option($name, $value, $autoload = null): bool { return true; }
function wp_generate_uuid4(): string { return '12345678-1234-1234-1234-123456789abc'; }

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

$schedule_request = new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/preview',
    json_encode([
        'operation' => 'post.update',
        'target' => ['post_id' => 42],
        'changes' => [
            'post_status' => 'future',
            'post_date' => '2026-09-21 08:00:00',
            'post_author' => 8,
        ],
    ], JSON_THROW_ON_ERROR)
);
$schedule_preview = CCF_Sites_Control::preview($schedule_request);
if (!$schedule_preview instanceof WP_REST_Response || $schedule_preview->status !== 200) {
    throw new RuntimeException('Scheduled publishing preview failed.');
}
$schedule_plan = $schedule_preview->data['data']['preview'] ?? null;
if (($schedule_plan['diff']['post_status']['after'] ?? '') !== 'future') {
    throw new RuntimeException('Scheduled status was not accepted.');
}
if (($schedule_plan['diff']['post_date']['after'] ?? '') !== '2026-09-21 08:00:00' || ($schedule_plan['diff']['post_date_gmt']['after'] ?? '') !== '2026-09-21 06:00:00') {
    throw new RuntimeException('Scheduled date timezone conversion failed.');
}
if (($schedule_plan['diff']['post_author']['after'] ?? 0) !== 8) {
    throw new RuntimeException('Author change preview failed.');
}

$apply_request = new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/apply',
    json_encode([
        'approval_id' => 'approval-schedule-1',
        'idempotency_key' => 'schedule-apply-1',
        'expected_before_checksum' => $schedule_plan['before_checksum'],
        'operation' => 'post.update',
        'target' => ['post_id' => 42],
        'changes' => [
            'post_status' => 'future',
            'post_date' => '2026-09-21 08:00:00',
            'post_author' => 8,
        ],
    ], JSON_THROW_ON_ERROR)
);
$applied = CCF_Sites_Control::apply($apply_request);
if (!$applied instanceof WP_REST_Response || $applied->status !== 201) {
    throw new RuntimeException('Scheduled publishing apply failed.');
}
if (($GLOBALS['ccf_post']['post_status'] ?? '') !== 'future' || ($GLOBALS['ccf_post']['post_author'] ?? 0) !== 8 || ($GLOBALS['ccf_post']['post_date_gmt'] ?? '') !== '2026-09-21 06:00:00') {
    throw new RuntimeException('Scheduled publishing was not applied and verified.');
}
$change_id = $applied->data['data']['change']['id'] ?? '';
if (!is_string($change_id) || strpos($change_id, 'change_') !== 0) {
    throw new RuntimeException('Apply did not return a change ID.');
}

$rollback_request = new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/rollback',
    json_encode(['change_id' => $change_id], JSON_THROW_ON_ERROR)
);
$rolled_back = CCF_Sites_Control::rollback($rollback_request);
if (!$rolled_back instanceof WP_REST_Response || ($GLOBALS['ccf_post']['post_status'] ?? '') !== 'draft' || ($GLOBALS['ccf_post']['post_author'] ?? 0) !== 7) {
    throw new RuntimeException('Scheduled publishing rollback failed.');
}

$drift_preview = CCF_Sites_Control::preview($schedule_request);
$drift_plan = $drift_preview->data['data']['preview'] ?? null;
$GLOBALS['ccf_post']['post_title'] = 'Drifted title';
$drift_apply = new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/apply',
    json_encode([
        'approval_id' => 'approval-drift-01',
        'idempotency_key' => 'schedule-drift-01',
        'expected_before_checksum' => $drift_plan['before_checksum'],
        'operation' => 'post.update',
        'target' => ['post_id' => 42],
        'changes' => ['post_status' => 'future', 'post_date' => '2026-09-21 08:00:00'],
    ], JSON_THROW_ON_ERROR)
);
$drift_result = CCF_Sites_Control::apply($drift_apply);
if (!$drift_result instanceof WP_Error || $drift_result->code !== 'ccf_change_drift') {
    throw new RuntimeException('Drift protection did not block stale scheduled publishing.');
}

$invalid_author = CCF_Sites_Control::preview(new Test_Request(
    'POST',
    '/ccf-sites/v1/changes/preview',
    json_encode(['operation' => 'post.update', 'target' => ['post_id' => 42], 'changes' => ['post_author' => 999]], JSON_THROW_ON_ERROR)
));
if (!$invalid_author instanceof WP_Error || $invalid_author->code !== 'ccf_change_invalid') {
    throw new RuntimeException('Invalid author was not rejected.');
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

if ($GLOBALS['ccf_post']['post_content'] !== '<div uk-grid>Old & content \\ path</div>') throw new RuntimeException('Existing HTML or backslashes were altered.');
if (!empty($GLOBALS['ccf_filters']['wp_insert_post_data'][PHP_INT_MAX])) throw new RuntimeException('Snapshot filter leaked beyond the write.');

echo "WordPress control signature, scheduling, author, apply/verify, rollback, drift and SEO previews passed.\n";
