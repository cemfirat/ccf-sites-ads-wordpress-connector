<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('RANK_MATH_VERSION', '1.0.278');

$GLOBALS['ccf_actions'] = [];
$GLOBALS['ccf_transients'] = [];
$GLOBALS['ccf_current_user_id'] = 0;

function add_action(string $name, $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['ccf_actions'][$name][] = [$callback, $priority, $accepted_args];
}
function register_rest_route(...$args): void {}
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_key($value): string { return strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', (string) $value)); }
function wp_json_encode($value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function get_transient(string $key) { return $GLOBALS['ccf_transients'][$key] ?? false; }
function set_transient(string $key, $value, int $ttl): bool { $GLOBALS['ccf_transients'][$key] = $value; return $ttl > 0; }
function get_current_user_id(): int { return (int) $GLOBALS['ccf_current_user_id']; }
function wp_set_current_user(int $id): int { $GLOBALS['ccf_current_user_id'] = $id; return $id; }
function get_users(array $args): array { return [1]; }

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
function is_wp_error($value): bool { return $value instanceof WP_Error; }

class WP_REST_Response {
    private $data;
    public $status;
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
}

class CCF_Sites_Control {
    public static function authorize($request) { return true; }
}

class Test_Request {
    private $body;
    public function __construct(array $body = []) { $this->body = $body; }
    public function get_json_params(): array { return $this->body; }
}

class Test_Ability {
    private $name;
    private $readonly;
    private $calls = 0;

    public function __construct(string $name, bool $readonly) {
        $this->name = $name;
        $this->readonly = $readonly;
    }
    public function get_name(): string { return $this->name; }
    public function get_label(): string { return $this->name; }
    public function get_description(): string { return 'Test ability'; }
    public function get_category(): string { return 'rank-math-test'; }
    public function get_input_schema(): array { return ['type' => 'object']; }
    public function get_output_schema(): array { return ['type' => 'object']; }
    public function get_meta(): array {
        return [
            'public' => true,
            'annotations' => [
                'readonly' => $this->readonly,
                'destructive' => !$this->readonly,
                'idempotent' => true,
            ],
        ];
    }
    public function execute($input = null): array {
        $this->calls++;
        return [
            'user_id' => get_current_user_id(),
            'input' => $input,
            'calls' => $this->calls,
            'seo_score' => 66,
        ];
    }
}

$GLOBALS['ccf_abilities'] = [
    'rank-math/get-post-seo-meta' => new Test_Ability('rank-math/get-post-seo-meta', true),
    'rank-math/set-homepage-seo' => new Test_Ability('rank-math/set-homepage-seo', false),
    'other-plugin/ignored' => new Test_Ability('other-plugin/ignored', true),
];

function wp_get_abilities(): array { return $GLOBALS['ccf_abilities']; }
function wp_get_ability(string $name) { return $GLOBALS['ccf_abilities'][$name] ?? null; }

require __DIR__ . '/../wordpress/includes/class-ccf-sites-rank-math.php';

$list = CCF_Sites_Rank_Math::list_abilities();
if (!$list instanceof WP_REST_Response) throw new RuntimeException('Rank Math ability listing failed.');
$listData = $list->get_data()['data'] ?? [];
if (($listData['rank_math_version'] ?? '') !== '1.0.278') throw new RuntimeException('Rank Math version missing.');
if (($listData['count'] ?? 0) !== 2) throw new RuntimeException('Ability filtering failed.');
if (($listData['abilities'][0]['name'] ?? '') !== 'rank-math/get-post-seo-meta') throw new RuntimeException('Ability sorting failed.');

$read = CCF_Sites_Rank_Math::execute(new Test_Request([
    'ability' => 'rank-math/get-post-seo-meta',
    'mode' => 'readonly',
    'input' => ['post_id' => 42],
]));
if (!$read instanceof WP_REST_Response) throw new RuntimeException('Readonly Rank Math execution failed.');
$readData = $read->get_data()['data'] ?? [];
if (($readData['result']['seo_score'] ?? 0) !== 66) throw new RuntimeException('SEO score was not returned.');
if (($readData['result']['user_id'] ?? 0) !== 1) throw new RuntimeException('Rank Math execution did not use the service administrator.');
if (get_current_user_id() !== 0) throw new RuntimeException('WordPress user context was not restored.');

$blocked = CCF_Sites_Rank_Math::execute(new Test_Request([
    'ability' => 'rank-math/set-homepage-seo',
    'mode' => 'readonly',
    'input' => ['title' => 'Example'],
]));
if (!$blocked instanceof WP_Error || $blocked->code !== 'ccf_rank_math_write_ability_blocked') {
    throw new RuntimeException('Readonly mode did not block a Rank Math mutation.');
}

$writeBody = [
    'ability' => 'rank-math/set-homepage-seo',
    'mode' => 'write',
    'input' => ['title' => 'Example'],
    'idempotency_key' => 'rank-math-write-test-0001',
];
$write = CCF_Sites_Rank_Math::execute(new Test_Request($writeBody));
if (!$write instanceof WP_REST_Response) throw new RuntimeException('Rank Math write execution failed.');
$writeData = $write->get_data()['data'] ?? [];
if (($writeData['replayed'] ?? true) !== false) throw new RuntimeException('First write was incorrectly marked as replayed.');

$replay = CCF_Sites_Rank_Math::execute(new Test_Request($writeBody));
if (!$replay instanceof WP_REST_Response) throw new RuntimeException('Rank Math write replay failed.');
$replayData = $replay->get_data()['data'] ?? [];
if (($replayData['replayed'] ?? false) !== true) throw new RuntimeException('Rank Math idempotency replay was not detected.');
if (($replayData['result']['calls'] ?? 0) !== 1) throw new RuntimeException('Rank Math write executed twice despite idempotency.');

$conflictBody = $writeBody;
$conflictBody['input'] = ['title' => 'Different'];
$conflict = CCF_Sites_Rank_Math::execute(new Test_Request($conflictBody));
if (!$conflict instanceof WP_Error || $conflict->code !== 'ccf_rank_math_idempotency_conflict') {
    throw new RuntimeException('Rank Math idempotency conflict was not blocked.');
}

echo "Rank Math native ability discovery, SEO score read, execution mode and idempotency passed.\n";
