<?php

defined('ABSPATH') || exit;

/**
 * Authenticated WordPress control surface used by CCF Sites & Ads.
 *
 * All mutations are previewed, bound to an approval/idempotency key, recorded
 * with before/after snapshots and reversible while the target has not drifted.
 */
final class CCF_Sites_Control {
    private const REST_NAMESPACE = 'ccf-sites/v1';
    private const SIGNATURE_TTL = 300;
    private const NONCE_TTL = 600;
    private const MAX_BODY_BYTES = 2097152;
    private const TABLE_SUFFIX = 'ccf_sites_changes';

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

    private const SITE_OPTION_KEYS = [
        'blogname',
        'blogdescription',
        'permalink_structure',
    ];

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function activate(): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }
        $table = self::table_name();
        $charset = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id varchar(64) NOT NULL,
            idempotency_key varchar(128) NOT NULL,
            approval_id varchar(128) NOT NULL,
            operation varchar(64) NOT NULL,
            target_json text NOT NULL,
            before_json longtext NOT NULL,
            after_json longtext NOT NULL,
            before_checksum varchar(80) NOT NULL,
            after_checksum varchar(80) NOT NULL,
            status varchar(24) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY approval_id (approval_id),
            KEY status_updated_at (status, updated_at)
        ) {$charset};";
        if (!function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (is_readable($upgrade)) {
                require_once $upgrade;
            }
        }
        if (function_exists('dbDelta')) {
            dbDelta($sql);
        }
    }

    public static function register_routes(): void {
        $read = [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'authorize'],
        ];
        $write = [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'authorize'],
        ];

        register_rest_route(self::REST_NAMESPACE, '/status', $read + [
            'callback' => [self::class, 'status'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/inventory', $read + [
            'callback' => [self::class, 'inventory'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content', $read + [
            'callback' => [self::class, 'content_list'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)', $read + [
            'callback' => [self::class, 'content'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/changes/preview', $write + [
            'callback' => [self::class, 'preview'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/changes/apply', $write + [
            'callback' => [self::class, 'apply'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/changes/rollback', $write + [
            'callback' => [self::class, 'rollback'],
        ]);
    }

    public static function authorize($request) {
        $token = CCF_Google_Ads_Site_Connector::site_token();
        if (strlen($token) < 32) {
            return new WP_Error('ccf_control_not_configured', 'Control authentication is not configured.', ['status' => 503]);
        }

        $timestamp = (int) $request->get_header('x-ccf-timestamp');
        $nonce = sanitize_text_field((string) $request->get_header('x-ccf-nonce'));
        $signature = strtolower(sanitize_text_field((string) $request->get_header('x-ccf-signature')));
        if ($timestamp <= 0 || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return new WP_Error('ccf_control_unauthorized', 'Missing or invalid control authentication.', ['status' => 401]);
        }
        if (abs(time() - $timestamp) > self::SIGNATURE_TTL) {
            return new WP_Error('ccf_control_stale', 'Control request timestamp is stale.', ['status' => 401]);
        }

        $body = (string) $request->get_body();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new WP_Error('ccf_control_payload_too_large', 'Control request exceeds the size limit.', ['status' => 413]);
        }
        $expected = hash_hmac('sha256', self::signature_payload($request, $timestamp, $nonce, $body), $token);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('ccf_control_unauthorized', 'Control authentication failed.', ['status' => 401]);
        }

        $nonce_key = 'ccf_sites_nonce_' . hash('sha256', $nonce);
        if (get_transient($nonce_key)) {
            return new WP_Error('ccf_control_replay', 'Control request nonce was already used.', ['status' => 409]);
        }
        set_transient($nonce_key, 1, self::NONCE_TTL);
        return true;
    }

    public static function signature_payload($request, int $timestamp, string $nonce, string $body): string {
        $query = $request->get_query_params();
        if (!is_array($query)) {
            $query = [];
        }
        ksort($query);
        $canonical_query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return strtoupper((string) $request->get_method()) . "\n"
            . (string) $request->get_route() . "\n"
            . $canonical_query . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
    }

    public static function status() {
        return self::response([
            'status' => 'ready',
            'plugin' => [
                'name' => 'CCF Sites & Ads Connector',
                'version' => '1.1.1',
            ],
            'capabilities' => [
                'wordpress.inventory.read',
                'wordpress.content.read',
                'wordpress.content.write',
                'rank-math.read',
                'rank-math.write',
                'yootheme.builder.read',
                'yootheme.builder.write',
                'wordpress.site-options.write',
                'change.preview',
                'change.apply',
                'change.rollback',
            ],
            'integrations' => self::integration_status(),
        ]);
    }

    public static function inventory() {
        if (!function_exists('get_plugins')) {
            $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($plugin_file)) {
                require_once $plugin_file;
            }
        }
        $active = (array) get_option('active_plugins', []);
        $plugins = [];
        foreach (function_exists('get_plugins') ? get_plugins() : [] as $file => $plugin) {
            $plugins[] = [
                'file' => (string) $file,
                'name' => isset($plugin['Name']) ? (string) $plugin['Name'] : (string) $file,
                'version' => isset($plugin['Version']) ? (string) $plugin['Version'] : '',
                'active' => in_array($file, $active, true),
            ];
        }
        $theme = wp_get_theme();
        $post_types = [];
        foreach ((array) get_post_types(['public' => true], 'objects') as $type) {
            $post_types[] = [
                'name' => (string) $type->name,
                'label' => (string) $type->label,
            ];
        }
        return self::response([
            'wordpress' => [
                'version' => (string) get_bloginfo('version'),
                'name' => (string) get_bloginfo('name'),
                'url' => home_url('/'),
                'language' => (string) get_bloginfo('language'),
            ],
            'theme' => [
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'stylesheet' => (string) $theme->get_stylesheet(),
                'template' => (string) $theme->get_template(),
            ],
            'plugins' => $plugins,
            'post_types' => $post_types,
            'integrations' => self::integration_status(),
        ]);
    }

    public static function content($request) {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('ccf_content_not_found', 'WordPress content was not found.', ['status' => 404]);
        }
        $snapshot = self::content_snapshot($post);
        return self::response([
            'content' => $snapshot,
            'checksum' => self::checksum($snapshot),
        ]);
    }

    public static function content_list($request) {
        $public_types = array_values((array) get_post_types(['public' => true], 'names'));
        $requested_type = sanitize_key((string) $request->get_param('post_type'));
        if ($requested_type !== '' && !in_array($requested_type, $public_types, true)) {
            return new WP_Error('ccf_content_type_forbidden', 'The requested content type is not public.', ['status' => 400]);
        }
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $page = max(1, (int) $request->get_param('page'));
        $query = new WP_Query([
            'post_type' => $requested_type !== '' ? $requested_type : $public_types,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            's' => sanitize_text_field((string) $request->get_param('search')),
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => false,
        ]);
        $items = [];
        foreach ((array) $query->posts as $post) {
            $snapshot = self::content_snapshot($post);
            $items[] = [
                'id' => $snapshot['id'],
                'type' => $snapshot['type'],
                'slug' => $snapshot['slug'],
                'status' => $snapshot['status'],
                'title' => $snapshot['title'],
                'modified_gmt' => $snapshot['modified_gmt'],
                'permalink' => $snapshot['permalink'],
                'checksum' => self::checksum($snapshot),
            ];
        }
        return self::response([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ]);
    }

    public static function preview($request) {
        $plan = self::build_plan(self::body($request));
        if (is_wp_error($plan)) {
            return $plan;
        }
        return self::response(['preview' => $plan]);
    }

    public static function apply($request) {
        $input = self::body($request);
        $approval_id = isset($input['approval_id']) ? sanitize_text_field((string) $input['approval_id']) : '';
        $idempotency_key = isset($input['idempotency_key']) ? sanitize_text_field((string) $input['idempotency_key']) : '';
        $expected_before = isset($input['expected_before_checksum']) ? sanitize_text_field((string) $input['expected_before_checksum']) : '';
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $approval_id) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotency_key)) {
            return new WP_Error('ccf_change_invalid', 'approval_id and idempotency_key are required.', ['status' => 400]);
        }

        self::activate();
        $existing = self::change_by_idempotency($idempotency_key);
        if ($existing) {
            return self::response(['change' => self::public_change($existing), 'replayed' => true]);
        }
        $plan = self::build_plan($input);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if ($expected_before === '' || !hash_equals($plan['before_checksum'], $expected_before)) {
            return new WP_Error('ccf_change_drift', 'Target changed after preview; a new preview is required.', ['status' => 409]);
        }

        $result = self::apply_snapshot($plan['operation'], $plan['target'], $plan['after']);
        if (is_wp_error($result)) {
            return $result;
        }
        $verified = self::load_snapshot($plan['operation'], $plan['target']);
        if (is_wp_error($verified) || self::checksum($verified) !== $plan['after_checksum']) {
            self::apply_snapshot($plan['operation'], $plan['target'], $plan['before']);
            return new WP_Error('ccf_change_verification_failed', 'Change verification failed and the original snapshot was restored.', ['status' => 500]);
        }

        global $wpdb;
        $change_id = 'change_' . wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'id' => $change_id,
                'idempotency_key' => $idempotency_key,
                'approval_id' => $approval_id,
                'operation' => $plan['operation'],
                'target_json' => wp_json_encode($plan['target']),
                'before_json' => wp_json_encode($plan['before']),
                'after_json' => wp_json_encode($plan['after']),
                'before_checksum' => $plan['before_checksum'],
                'after_checksum' => $plan['after_checksum'],
                'status' => 'applied',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ($inserted === false) {
            self::apply_snapshot($plan['operation'], $plan['target'], $plan['before']);
            return new WP_Error('ccf_change_log_failed', 'Change logging failed and the original snapshot was restored.', ['status' => 500]);
        }
        $change = self::change_by_id($change_id);
        return self::response(['change' => self::public_change($change), 'replayed' => false], 201);
    }

    public static function rollback($request) {
        $input = self::body($request);
        $change_id = isset($input['change_id']) ? sanitize_text_field((string) $input['change_id']) : '';
        if (!preg_match('/^change_[A-Za-z0-9-]{8,64}$/', $change_id)) {
            return new WP_Error('ccf_rollback_invalid', 'A valid change_id is required.', ['status' => 400]);
        }
        $change = self::change_by_id($change_id);
        if (!$change) {
            return new WP_Error('ccf_change_not_found', 'Change record was not found.', ['status' => 404]);
        }
        if ($change['status'] === 'rolled-back') {
            return self::response(['change' => self::public_change($change), 'replayed' => true]);
        }
        $target = json_decode((string) $change['target_json'], true);
        $before = json_decode((string) $change['before_json'], true);
        $current = self::load_snapshot((string) $change['operation'], $target);
        if (is_wp_error($current) || self::checksum($current) !== (string) $change['after_checksum']) {
            return new WP_Error('ccf_rollback_drift', 'Target changed after apply; automatic rollback was blocked.', ['status' => 409]);
        }
        $restored = self::apply_snapshot((string) $change['operation'], $target, $before);
        if (is_wp_error($restored)) {
            return $restored;
        }
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            ['status' => 'rolled-back', 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $change_id],
            ['%s', '%s'],
            ['%s']
        );
        return self::response(['change' => self::public_change(self::change_by_id($change_id)), 'replayed' => false]);
    }

    private static function build_plan(array $input) {
        $operation = isset($input['operation']) ? strtolower(trim(sanitize_text_field((string) $input['operation']))) : '';
        $target = isset($input['target']) && is_array($input['target']) ? $input['target'] : [];
        $changes = isset($input['changes']) && is_array($input['changes']) ? $input['changes'] : [];
        if (!in_array($operation, ['post.update', 'seo.update', 'yootheme.builder.update', 'site.option.update'], true)) {
            return new WP_Error('ccf_change_unsupported', 'The requested operation is not supported.', ['status' => 400]);
        }
        $normalized_target = self::normalize_target($operation, $target);
        if (is_wp_error($normalized_target)) {
            return $normalized_target;
        }
        $before = self::load_snapshot($operation, $normalized_target);
        if (is_wp_error($before)) {
            return $before;
        }
        $after = self::planned_snapshot($operation, $before, $changes);
        if (is_wp_error($after)) {
            return $after;
        }
        if ($before === $after) {
            return new WP_Error('ccf_change_noop', 'The requested change does not modify the target.', ['status' => 400]);
        }
        return [
            'operation' => $operation,
            'target' => $normalized_target,
            'before' => $before,
            'after' => $after,
            'diff' => self::diff($before, $after),
            'before_checksum' => self::checksum($before),
            'after_checksum' => self::checksum($after),
            'validation' => ['valid' => true, 'checks' => ['target-exists', 'operation-allowlisted', 'payload-sanitized']],
            'requires_approval' => true,
        ];
    }

    private static function normalize_target(string $operation, array $target) {
        if ($operation === 'site.option.update') {
            $option = isset($target['option']) ? sanitize_key((string) $target['option']) : '';
            if (!in_array($option, self::SITE_OPTION_KEYS, true)) {
                return new WP_Error('ccf_option_forbidden', 'The WordPress option is not allowlisted.', ['status' => 400]);
            }
            return ['option' => $option];
        }
        $post_id = isset($target['post_id']) ? (int) $target['post_id'] : 0;
        if ($post_id <= 0 || !get_post($post_id)) {
            return new WP_Error('ccf_content_not_found', 'WordPress content was not found.', ['status' => 404]);
        }
        return ['post_id' => $post_id];
    }

    private static function load_snapshot(string $operation, array $target) {
        if ($operation === 'site.option.update') {
            return ['value' => get_option($target['option'], '')];
        }
        $post = get_post((int) $target['post_id']);
        if (!$post) {
            return new WP_Error('ccf_content_not_found', 'WordPress content was not found.', ['status' => 404]);
        }
        if ($operation === 'post.update') {
            return [
                'post_title' => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'post_status' => (string) $post->post_status,
            ];
        }
        if ($operation === 'yootheme.builder.update') {
            return ['builder' => get_post_meta((int) $target['post_id'], '_yootheme_builder', true)];
        }
        $snapshot = [];
        foreach (self::SEO_META_KEYS as $key) {
            $snapshot[$key] = get_post_meta((int) $target['post_id'], $key, true);
        }
        return $snapshot;
    }

    private static function planned_snapshot(string $operation, array $before, array $changes) {
        if ($operation === 'post.update') {
            $allowed = ['post_title', 'post_content', 'post_excerpt', 'post_status'];
            $after = $before;
            foreach ($changes as $key => $value) {
                if (!in_array($key, $allowed, true) || !is_string($value)) {
                    return new WP_Error('ccf_change_invalid', 'Post changes contain an unsupported field or value.', ['status' => 400]);
                }
                if ($key === 'post_status' && !in_array($value, ['draft', 'pending', 'private', 'publish'], true)) {
                    return new WP_Error('ccf_change_invalid', 'The requested post status is not allowed.', ['status' => 400]);
                }
                $after[$key] = $key === 'post_content' ? wp_kses_post($value) : sanitize_text_field($value);
            }
            return $after;
        }
        if ($operation === 'site.option.update') {
            if (!array_key_exists('value', $changes) || !is_string($changes['value'])) {
                return new WP_Error('ccf_change_invalid', 'A string option value is required.', ['status' => 400]);
            }
            return ['value' => sanitize_text_field($changes['value'])];
        }
        if ($operation === 'yootheme.builder.update') {
            if (!array_key_exists('builder', $changes) || (!is_array($changes['builder']) && !is_string($changes['builder']))) {
                return new WP_Error('ccf_change_invalid', 'YOOtheme builder data must be a JSON object or string.', ['status' => 400]);
            }
            $builder = is_array($changes['builder']) ? wp_json_encode($changes['builder']) : (string) $changes['builder'];
            if (!is_string($builder) || strlen($builder) > self::MAX_BODY_BYTES || json_decode($builder, true) === null) {
                return new WP_Error('ccf_change_invalid', 'YOOtheme builder data exceeds the size limit.', ['status' => 400]);
            }
            return ['builder' => $builder];
        }

        $after = $before;
        foreach ($changes as $key => $value) {
            if (!in_array($key, self::SEO_META_KEYS, true)) {
                return new WP_Error('ccf_change_invalid', 'Rank Math changes contain a non-allowlisted field.', ['status' => 400]);
            }
            if ($key === 'rank_math_robots') {
                if (!is_array($value)) {
                    return new WP_Error('ccf_change_invalid', 'rank_math_robots must be an array.', ['status' => 400]);
                }
                $after[$key] = array_values(array_filter(array_map('sanitize_key', $value)));
            } elseif (is_string($value)) {
                $after[$key] = sanitize_text_field($value);
            } else {
                return new WP_Error('ccf_change_invalid', 'Rank Math values must be strings or an allowed robots array.', ['status' => 400]);
            }
        }
        return $after;
    }

    private static function apply_snapshot(string $operation, array $target, array $snapshot) {
        if ($operation === 'site.option.update') {
            return update_option($target['option'], $snapshot['value'], false);
        }
        $post_id = (int) $target['post_id'];
        if ($operation === 'post.update') {
            $result = wp_update_post(['ID' => $post_id] + $snapshot, true);
            return is_wp_error($result) ? $result : true;
        }
        if ($operation === 'yootheme.builder.update') {
            update_post_meta($post_id, '_yootheme_builder', $snapshot['builder']);
            return true;
        }
        foreach (self::SEO_META_KEYS as $key) {
            if (!array_key_exists($key, $snapshot) || $snapshot[$key] === '' || $snapshot[$key] === null) {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $snapshot[$key]);
            }
        }
        return true;
    }

    private static function content_snapshot($post): array {
        $seo = [];
        foreach (self::SEO_META_KEYS as $key) {
            $seo[$key] = get_post_meta((int) $post->ID, $key, true);
        }
        return [
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'permalink' => get_permalink((int) $post->ID),
            'rank_math' => $seo,
            'yootheme_builder' => get_post_meta((int) $post->ID, '_yootheme_builder', true),
        ];
    }

    private static function integration_status(): array {
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $theme_name = $theme ? strtolower((string) $theme->get('Name') . ' ' . (string) $theme->get_template()) : '';
        return [
            'rank_math' => defined('RANK_MATH_VERSION') || class_exists('RankMath'),
            'yootheme' => strpos($theme_name, 'yootheme') !== false || function_exists('YOOtheme'),
            'acf' => class_exists('ACF') || function_exists('get_field'),
        ];
    }

    private static function body($request): array {
        $body = $request->get_json_params();
        return is_array($body) ? $body : [];
    }

    private static function diff(array $before, array $after): array {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $old = array_key_exists($key, $before) ? $before[$key] : null;
            $new = array_key_exists($key, $after) ? $after[$key] : null;
            if ($old !== $new) {
                $diff[$key] = ['before' => $old, 'after' => $new];
            }
        }
        return $diff;
    }

    private static function checksum($value): string {
        return 'sha256:' . hash('sha256', (string) wp_json_encode($value));
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function change_by_idempotency(string $key) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE idempotency_key = %s', $key),
            ARRAY_A
        );
    }

    private static function change_by_id(string $id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %s', $id),
            ARRAY_A
        );
    }

    private static function public_change($row): array {
        if (!is_array($row)) {
            return [];
        }
        return [
            'id' => (string) $row['id'],
            'approval_id' => (string) $row['approval_id'],
            'operation' => (string) $row['operation'],
            'target' => json_decode((string) $row['target_json'], true),
            'before_checksum' => (string) $row['before_checksum'],
            'after_checksum' => (string) $row['after_checksum'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function response(array $data, int $status = 200) {
        return new WP_REST_Response([
            'schema_version' => '1.0',
            'site' => [
                'client_id' => CCF_Google_Ads_Site_Connector::client_id(),
                'site_id' => CCF_Google_Ads_Site_Connector::site_id(),
                'environment' => CCF_Google_Ads_Site_Connector::environment(),
            ],
            'data' => $data,
        ], $status);
    }
}
