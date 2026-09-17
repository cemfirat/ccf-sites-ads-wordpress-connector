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
function get_users(array $args = []): array {
    return [
        (object) ['ID' => 1, 'display_name' => 'developez', 'user_nicename' => 'developez', 'user_email' => 'hidden@example.test', 'user_login' => 'hidden-login'],
        (object) ['ID' => 4, 'display_name' => 'Cem Cemil Firat', 'user_nicename' => 'cem-cemil-firat', 'user_email' => 'cem@example.test', 'user_login' => 'cem-admin'],
        (object) ['ID' => 5, 'display_name' => 'Subscriber', 'user_nicename' => 'subscriber', 'user_email' => 'subscriber@example.test', 'user_login' => 'subscriber'],
    ];
}
function user_can($user, string $capability): bool { return $capability === 'edit_posts' && in_array((int) $user->ID, [1, 4], true); }
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
    $expected = ['author_url', 'can_edit_posts', 'display_name', 'id', 'slug']; sort($expected);
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

$other = new Test_Response(['data' => ['unchanged' => true]]);
CCF_Sites_Authors::augment_control_response($other, null, new Test_Request('/ccf-sites/v1/content'));
if ($other->get_data() !== ['data' => ['unchanged' => true]]) throw new RuntimeException('Unrelated REST responses were modified.');

echo "WordPress safe author discovery passed.\n";
