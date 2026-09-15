<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['ccf_transients'] = [];
$GLOBALS['ccf_posts'] = [
    42 => (object) [
        'ID' => 42,
        'post_type' => 'post',
        'post_name' => 'published',
        'post_status' => 'publish',
        'post_title' => 'Published',
        'post_content' => '<p>Published</p>',
        'post_excerpt' => '',
    ],
];
$GLOBALS['ccf_next_post_id'] = 100;

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
    private $body;
    private $params;
    private $query;
    public function __construct($body = '', $params = [], $query = []) {
        $this->body = $body;
        $this->params = $params;
        $this->query = $query;
    }
    public function get_json_params() { return json_decode($this->body, true); }
    public function get_param($name) { return $this->query[$name] ?? null; }
    public function offsetGet($name) { return $this->params[$name] ?? null; }
    public function __get($name) { return $this->params[$name] ?? null; }
    public function get_query_params() { return $this->query; }
}

class CCF_Sites_Control {
    public static function authorize($request) { return true; }
}

function add_action(...$args): void {}
function register_rest_route(...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value): string { return trim((string) $value); }
function sanitize_key($value): string { return strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', (string) $value)); }
function sanitize_title($value): string { return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $value), '-')); }
function wp_kses_post($value): string { return (string) $value; }
function get_transient($key) { return $GLOBALS['ccf_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl): bool { $GLOBALS['ccf_transients'][$key] = $value; return true; }
function get_post_type_object($type) { return in_array($type, ['post', 'page'], true) ? (object) ['public' => true] : null; }
function wp_insert_post($data, $wp_error = false) {
    $id = ++$GLOBALS['ccf_next_post_id'];
    $GLOBALS['ccf_posts'][$id] = (object) [
        'ID' => $id,
        'post_type' => $data['post_type'],
        'post_name' => $data['post_name'] ?? sanitize_title($data['post_title']),
        'post_status' => $data['post_status'],
        'post_title' => $data['post_title'],
        'post_content' => $data['post_content'],
        'post_excerpt' => $data['post_excerpt'],
    ];
    return $id;
}
function get_post($id) { return $GLOBALS['ccf_posts'][(int) $id] ?? null; }
function get_permalink($id): string { return 'https://example.test/?p=' . (int) $id; }
function get_post_mime_type($id): string { return ''; }
function wp_get_attachment_url($id): string { return ''; }
function get_post_meta($id, $key, $single = false) { return ''; }
function wp_get_attachment_metadata($id): array { return []; }

require __DIR__ . '/../wordpress/includes/class-ccf-sites-content-admin.php';

$request = new Test_Request(json_encode([
    'idempotency_key' => 'blog-autumn-2026-001',
    'post_type' => 'post',
    'title' => 'Herbst auf der Terrasse',
    'content' => '<p>Testinhalt</p>',
    'excerpt' => 'Kurztext',
    'status' => 'draft',
], JSON_THROW_ON_ERROR));

$created = CCF_Sites_Content_Admin::create_content($request);
if (!$created instanceof WP_REST_Response || $created->status !== 201) {
    throw new RuntimeException('Draft creation failed.');
}
$content = $created->data['data']['content'] ?? null;
if (!is_array($content) || ($content['status'] ?? '') !== 'draft' || ($content['title'] ?? '') !== 'Herbst auf der Terrasse') {
    throw new RuntimeException('Created draft snapshot is invalid.');
}

$replayed = CCF_Sites_Content_Admin::create_content($request);
if (!$replayed instanceof WP_REST_Response || ($replayed->data['data']['replayed'] ?? false) !== true) {
    throw new RuntimeException('Draft idempotency replay failed.');
}

$publishRequest = new Test_Request(json_encode([
    'idempotency_key' => 'blog-autumn-2026-002',
    'post_type' => 'post',
    'title' => 'Must not publish directly',
    'status' => 'publish',
], JSON_THROW_ON_ERROR));
$publishResult = CCF_Sites_Content_Admin::create_content($publishRequest);
if (!$publishResult instanceof WP_Error || $publishResult->code !== 'ccf_content_status_forbidden') {
    throw new RuntimeException('Direct publishing was not blocked.');
}

$trashPublishedRequest = new Test_Request('', ['id' => 42]);
$trashPublished = CCF_Sites_Content_Admin::trash_content($trashPublishedRequest);
if (!$trashPublished instanceof WP_Error || $trashPublished->code !== 'ccf_published_content_requires_approval') {
    throw new RuntimeException('Published content bypassed the approval gate.');
}

echo "WordPress draft-first content administration passed.\n";
