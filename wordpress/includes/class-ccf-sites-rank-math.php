<?php

defined('ABSPATH') || exit;

/**
 * Signed bridge from CCF Sites & Ads to Rank Math's native WordPress Abilities.
 *
 * Rank Math 1.0.272+ exposes its MCP surface through the WordPress Abilities API.
 * This bridge deliberately discovers those abilities dynamically so future
 * Rank Math abilities become visible to CCF without hard-coding plugin internals.
 */
final class CCF_Sites_Rank_Math {
    private const REST_NAMESPACE = 'ccf-sites/v1';
    private const ABILITY_PREFIX = 'rank-math/';
    private const IDEMPOTENCY_TTL = 21600;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes'], 30);
    }

    public static function register_routes(): void {
        $permission = [CCF_Sites_Control::class, 'authorize'];

        register_rest_route(self::REST_NAMESPACE, '/rank-math/abilities', [
            'methods' => 'GET',
            'permission_callback' => $permission,
            'callback' => [self::class, 'list_abilities'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/rank-math/execute', [
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => [self::class, 'execute'],
        ]);
    }

    public static function list_abilities() {
        if (!function_exists('wp_get_abilities')) {
            return new WP_Error(
                'ccf_rank_math_abilities_unavailable',
                'The WordPress Abilities API is not available on this site.',
                ['status' => 501]
            );
        }

        $items = [];
        foreach ((array) wp_get_abilities() as $ability) {
            if (!is_object($ability) || !method_exists($ability, 'get_name')) {
                continue;
            }

            $name = (string) $ability->get_name();
            if (strpos($name, self::ABILITY_PREFIX) !== 0) {
                continue;
            }

            $meta = method_exists($ability, 'get_meta') ? (array) $ability->get_meta() : [];
            if (empty($meta['public'])) {
                continue;
            }

            $items[] = self::ability_snapshot($ability);
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return self::response([
            'rank_math_version' => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : '',
            'count' => count($items),
            'abilities' => $items,
        ]);
    }

    public static function execute($request) {
        if (!function_exists('wp_get_ability')) {
            return new WP_Error(
                'ccf_rank_math_abilities_unavailable',
                'The WordPress Abilities API is not available on this site.',
                ['status' => 501]
            );
        }

        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        $name = isset($body['ability']) ? sanitize_text_field((string) $body['ability']) : '';
        if ($name === '' || strpos($name, self::ABILITY_PREFIX) !== 0) {
            return new WP_Error(
                'ccf_rank_math_ability_invalid',
                'A valid Rank Math ability name is required.',
                ['status' => 400]
            );
        }

        $mode = isset($body['mode']) ? sanitize_key((string) $body['mode']) : 'readonly';
        if (!in_array($mode, ['readonly', 'write'], true)) {
            return new WP_Error(
                'ccf_rank_math_mode_invalid',
                'mode must be readonly or write.',
                ['status' => 400]
            );
        }

        $ability = wp_get_ability($name);
        if (!$ability || !is_object($ability) || !method_exists($ability, 'execute')) {
            return new WP_Error(
                'ccf_rank_math_ability_not_found',
                'The requested Rank Math ability is not registered.',
                ['status' => 404]
            );
        }

        $meta = method_exists($ability, 'get_meta') ? (array) $ability->get_meta() : [];
        if (empty($meta['public'])) {
            return new WP_Error(
                'ccf_rank_math_ability_not_public',
                'The requested Rank Math ability is not public.',
                ['status' => 403]
            );
        }

        $annotations = isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];
        $readonly = isset($annotations['readonly']) && $annotations['readonly'] === true;

        if ($mode === 'readonly' && !$readonly) {
            return new WP_Error(
                'ccf_rank_math_write_ability_blocked',
                'This Rank Math ability changes the site and cannot run in readonly mode.',
                ['status' => 409]
            );
        }
        if ($mode === 'write' && $readonly) {
            return new WP_Error(
                'ccf_rank_math_read_ability_mismatch',
                'This Rank Math ability is readonly and must run through readonly mode.',
                ['status' => 409]
            );
        }

        $input = array_key_exists('input', $body) ? $body['input'] : null;
        $fingerprint = hash('sha256', (string) wp_json_encode([
            'ability' => $name,
            'mode' => $mode,
            'input' => $input,
        ]));

        $idempotency_key = '';
        $transient_key = '';
        if ($mode === 'write') {
            $idempotency_key = isset($body['idempotency_key']) ? sanitize_text_field((string) $body['idempotency_key']) : '';
            if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotency_key)) {
                return new WP_Error(
                    'ccf_rank_math_idempotency_invalid',
                    'A valid idempotency_key is required for Rank Math write abilities.',
                    ['status' => 400]
                );
            }

            $transient_key = 'ccf_rm_exec_' . hash('sha256', $idempotency_key);
            $cached = get_transient($transient_key);
            if (is_array($cached)) {
                if (($cached['fingerprint'] ?? '') !== $fingerprint) {
                    return new WP_Error(
                        'ccf_rank_math_idempotency_conflict',
                        'The idempotency key was already used with different Rank Math input.',
                        ['status' => 409]
                    );
                }

                return self::response([
                    'ability' => $name,
                    'readonly' => false,
                    'replayed' => true,
                    'result' => $cached['result'] ?? null,
                ]);
            }
        }

        $previous_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $service_user_id = self::service_user_id();
        if ($service_user_id <= 0 || !function_exists('wp_set_current_user')) {
            return new WP_Error(
                'ccf_rank_math_service_user_unavailable',
                'No WordPress administrator is available for authenticated Rank Math execution.',
                ['status' => 503]
            );
        }

        wp_set_current_user($service_user_id);
        try {
            $result = $ability->execute($input);
        } finally {
            wp_set_current_user($previous_user_id);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        if ($mode === 'write' && $transient_key !== '') {
            set_transient($transient_key, [
                'fingerprint' => $fingerprint,
                'result' => $result,
            ], self::IDEMPOTENCY_TTL);
        }

        return self::response([
            'ability' => $name,
            'readonly' => $readonly,
            'replayed' => false,
            'result' => $result,
        ]);
    }

    private static function service_user_id(): int {
        if (!function_exists('get_users')) {
            return 0;
        }

        $ids = get_users([
            'role' => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        return is_array($ids) && isset($ids[0]) ? (int) $ids[0] : 0;
    }

    private static function ability_snapshot($ability): array {
        $meta = method_exists($ability, 'get_meta') ? (array) $ability->get_meta() : [];
        $annotations = isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];

        return [
            'name' => (string) $ability->get_name(),
            'label' => method_exists($ability, 'get_label') ? (string) $ability->get_label() : '',
            'description' => method_exists($ability, 'get_description') ? (string) $ability->get_description() : '',
            'category' => method_exists($ability, 'get_category') ? (string) $ability->get_category() : '',
            'input_schema' => method_exists($ability, 'get_input_schema') ? (array) $ability->get_input_schema() : [],
            'output_schema' => method_exists($ability, 'get_output_schema') ? (array) $ability->get_output_schema() : [],
            'annotations' => [
                'readonly' => isset($annotations['readonly']) ? (bool) $annotations['readonly'] : false,
                'destructive' => isset($annotations['destructive']) ? (bool) $annotations['destructive'] : false,
                'idempotent' => isset($annotations['idempotent']) ? (bool) $annotations['idempotent'] : false,
            ],
        ];
    }

    private static function response(array $data) {
        return new WP_REST_Response(['data' => $data, 'error' => null], 200);
    }
}
