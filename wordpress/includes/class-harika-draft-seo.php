<?php

defined('ABSPATH') || exit;

final class CCF_Sites_Draft_SEO {
    private const REST_NAMESPACE = 'ccf-sites/v1';
    private const SEO_META_KEYS = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_canonical_url',
        'rank_math_robots',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
    ];

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $permission = [CCF_Sites_Control::class, 'authorize'];
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\\d+)/seo-draft', [
            'methods' => 'GET',
            'permission_callback' => $permission,
            'callback' => [self::class, 'read'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\\d+)/seo-draft', [
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => [self::class, 'write'],
        ]);
    }

    public static function read($request) {
        $post = get_post((int) $request['id']);
        if (!$post) {
            return new WP_Error('ccf_content_not_found', 'WordPress content was not found.', ['status' => 404]);
        }
        return self::response([
            'post_id' => (int) $post->ID,
            'status' => (string) $post->post_status,
            'seo' => self::snapshot((int) $post->ID),
        ]);
    }

    public static function write($request) {
        $post = get_post((int) $request['id']);
        if (!$post) {
            return new WP_Error('ccf_content_not_found', 'WordPress content was not found.', ['status' => 404]);
        }
        if ((string) $post->post_status === 'publish') {
            return new WP_Error(
                'ccf_published_content_requires_approval',
                'Published SEO must be changed through the preview/approval workflow.',
                ['status' => 409]
            );
        }
        $body = $request->get_json_params();
        $fields = is_array($body) && isset($body['fields']) && is_array($body['fields']) ? $body['fields'] : null;
        if ($fields === null || count($fields) > count(self::SEO_META_KEYS)) {
            return new WP_Error('ccf_seo_invalid', 'fields must contain only supported Rank Math values.', ['status' => 400]);
        }
        foreach ($fields as $key => $value) {
            $key = sanitize_key((string) $key);
            if (!in_array($key, self::SEO_META_KEYS, true)) {
                return new WP_Error('ccf_seo_invalid', 'A Rank Math field is not allowlisted.', ['status' => 400]);
            }
            if ($key === 'rank_math_robots') {
                if (!is_array($value)) {
                    return new WP_Error('ccf_seo_invalid', 'rank_math_robots must be an array.', ['status' => 400]);
                }
                $robots = array_values(array_unique(array_filter(array_map('sanitize_key', $value))));
                update_post_meta((int) $post->ID, $key, $robots);
                continue;
            }
            if (!is_string($value)) {
                return new WP_Error('ccf_seo_invalid', 'Rank Math values must be strings.', ['status' => 400]);
            }
            $sanitized = $key === 'rank_math_canonical_url'
                ? esc_url_raw(trim($value))
                : sanitize_text_field($value);
            if ($sanitized === '') {
                delete_post_meta((int) $post->ID, $key);
            } else {
                update_post_meta((int) $post->ID, $key, $sanitized);
            }
        }
        return self::response([
            'post_id' => (int) $post->ID,
            'status' => (string) $post->post_status,
            'seo' => self::snapshot((int) $post->ID),
        ]);
    }

    private static function snapshot(int $post_id): array {
        $result = [];
        foreach (self::SEO_META_KEYS as $key) {
            $result[$key] = get_post_meta($post_id, $key, true);
        }
        return $result;
    }

    private static function response(array $data) {
        return new WP_REST_Response(['data' => $data, 'error' => null], 200);
    }
}
