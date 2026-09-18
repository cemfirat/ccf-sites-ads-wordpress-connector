<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['ccf_filters'] = [];
$GLOBALS['ccf_actions'] = [];

function add_filter(string $name, $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['ccf_filters'][$name][] = [$callback, $priority, $accepted_args];
}
function add_action(string $name, $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['ccf_actions'][$name][] = [$callback, $priority, $accepted_args];
}
final class Test_User {
    public $ID;
    public $display_name;
    public $user_nicename;
    public $roles;
    public function __construct(int $id, string $displayName, string $nicename, array $roles) {
        $this->ID = $id;
        $this->display_name = $displayName;
        $this->user_nicename = $nicename;
        $this->roles = $roles;
    }
    public function set_role(string $role): void {
        $this->roles = [$role];
        $GLOBALS['ccf_users'][$this->ID] = $this;
    }
}
$GLOBALS['ccf_users'] = [
    1 => new Test_User(1, 'developez', 'developez', ['author']),
    4 => new Test_User(4, 'Cem Cemil Firat', 'cem-cemil-firat', ['administrator']),
    5 => new Test_User(5, 'Subscriber', 'subscriber', ['subscriber']),
];
function get_users(array $args = []): array { return array_values($GLOBALS['ccf_users']); }
function get_user_by(string $field, int $id) { return $field === 'id' ? ($GLOBALS['ccf_users'][$id] ?? false) : false; }
function user_can($user, string $capability): bool {
    $id = (int) $user->ID;
    if ($capability === 'edit_posts') return in_array($id, [1, 4], true);
    if ($id === 4) return in_array($capability, ['manage_options', 'edit_users', 'promote_users', 'create_users', 'delete_users', 'activate_plugins', 'edit_theme_options', 'edit_others_posts', 'delete_others_posts', 'publish_pages'], true);
    return false;
}
function get_role(string $role) { return in_array($role, ['contributor', 'author'], true) ? (object) ['name' => $role] : null; }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function sanitize_text_field(string $value): string { return trim($value); }
function get_author_posts_url(int $id, $nicename = null): string { return 'https://customer.example/author/' . rawurlencode((string) $nicename) . '/'; }

final class Test_Response {
    private $data;
    public function __construct(array $data) { $this->data = $data; }
    public function get_data(): array { return $this->data; }
    public function set_data($data): void { $this->data = $data; }
}
final class Test_Request {
    private $route;
    public function __construct(string $route) { $this->route = $route; }
    public function get_route(): string { return $this->route; }
}

require __DIR__ . '/../wordpress/includes/class-ccf-sites-authors.php';
CCF_Sites_Authors::init();
$filters = $GLOBALS['ccf_filters']['rest_post_dispatch'] ?? [];
if (count($filters) !== 1 || $filters[0][2] !== 3) throw new RuntimeException('Author response augmenter was not registered correctly.');
$actions = $GLOBALS['ccf_actions']['rest_api_init'] ?? [];
if (count($actions) !== 1) throw new RuntimeException('Author profile route registration was not registered correctly.');

$authors = CCF_Sites_Authors::eligible_authors();
if (count($authors) !== 2 || ($authors[0]['display_name'] ?? '') !== 'Cem Cemil Firat' || ($authors[1]['display_name'] ?? '') !== 'developez') throw new RuntimeException('Eligible author discovery or sorting failed.');
foreach ($authors as $author) {
    $keys = array_keys($author); sort($keys);
    $expected = ['author_url', 'can_edit_posts', 'display_name', 'id', 'roles', 'slug']; sort($expected);
    if ($keys !== $expected) throw new RuntimeException('Author discovery exposed unexpected fields.');
    if (($author['can_edit_posts'] ?? false) !== true || strpos((string) ($author['author_url'] ?? ''), 'https://customer.example/author/') !== 0) throw new RuntimeException('Author public identity payload is invalid.');
}

$inventory = new Test_Response(['data' => ['wordpress' => ['name' => 'Example']]]);
CCF_Sites_Authors::augment_control_response($inventory, null, new Test_Request('/ccf-sites/v1/inventory'));
if (($inventory->get_data()['data']['authors'] ?? null) !== $authors) throw new RuntimeException('Inventory was not augmented with authors.');

$status = new Test_Response(['data' => ['capabilities' => ['wordpress.content.author.write']]]);
CCF_Sites_Authors::augment_control_response($status, null, new Test_Request('/ccf-sites/v1/status'));
$statusData = $status->get_data();
if (!in_array('wordpress.content.author.read', $statusData['data']['capabilities'] ?? [], true)) throw new RuntimeException('Author read capability was not advertised.');
if (!in_array('wordpress.author.profile.write', $statusData['data']['capabilities'] ?? [], true)) throw new RuntimeException('Author profile write capability was not advertised.');
if (!in_array('wordpress.author.role.write', $statusData['data']['capabilities'] ?? [], true)) throw new RuntimeException('Author role write capability was not advertised.');

$other = new Test_Response(['data' => ['unchanged' => true]]);
CCF_Sites_Authors::augment_control_response($other, null, new Test_Request('/ccf-sites/v1/content'));
if ($other->get_data() !== ['data' => ['unchanged' => true]]) throw new RuntimeException('Unrelated REST responses were modified.');

echo "WordPress safe author discovery passed.\n";


final class Test_Role_Request implements ArrayAccess {
    private $id;
    private $body;
    public function __construct(int $id, array $body) { $this->id = $id; $this->body = $body; }
    public function offsetExists($offset): bool { return $offset === 'id'; }
    public function offsetGet($offset) { return $offset === 'id' ? $this->id : null; }
    public function offsetSet($offset, $value): void { throw new RuntimeException('read only'); }
    public function offsetUnset($offset): void { throw new RuntimeException('read only'); }
    public function get_body(): string { return json_encode($this->body); }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data, int $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code, $message, $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
    }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }

$roleRequest = new Test_Role_Request(1, ['role' => 'contributor']);
$roleResult = CCF_Sites_Authors::update_role($roleRequest);
$roleData = $roleResult instanceof WP_REST_Response ? $roleResult->get_data() : [];
if (($roleData['data']['changed'] ?? null) !== true) throw new RuntimeException('Safe editorial role change did not report changed=true.');
if (($roleData['data']['author']['roles'] ?? null) !== ['contributor']) throw new RuntimeException('Safe editorial role change was not verified.');

$privileged = CCF_Sites_Authors::update_role(new Test_Role_Request(4, ['role' => 'author']));
if (!($privileged instanceof WP_Error) || $privileged->code !== 'ccf_author_role_forbidden') throw new RuntimeException('Privileged author role changes were not blocked.');

$invalid = CCF_Sites_Authors::update_role(new Test_Role_Request(1, ['role' => 'editor']));
if (!($invalid instanceof WP_Error) || $invalid->code !== 'ccf_author_role_invalid') throw new RuntimeException('Non-editorial role changes were not blocked.');
