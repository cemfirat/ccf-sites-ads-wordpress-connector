<?php

defined('ABSPATH') || exit;

/**
 * Low-risk content administration surface for CCF Sites & Ads.
 *
 * Published content is deliberately not changed here. Existing published
 * content continues to use the preview/approval/apply path in CCF_Sites_Control.
 * This surface is intended for creating and preparing drafts, taxonomies,
 * ACF data and media before publication.
 */
final class CCF_Sites_Content_Admin {
    private const REST_NAMESPACE = 'ccf-sites/v1';
    private const MAX_IMAGE_BYTES = 10485760;
    private const IDEMPOTENCY_TTL = 86400;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $read = [
            'methods' => 'GET',
            'permission_callback' => [CCF_Sites_Control::class, 'authorize'],
        ];
        $write = [
            'methods' => 'POST',
            'permission_callback' => [CCF_Sites_Control::class, 'authorize'],
        ];

        register_rest_route(self::REST_NAMESPACE, '/content-admin/status', $read + [
            'callback' => [self::class, 'status'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/taxonomies', $read + [
            'callback' => [self::class, 'taxonomies'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/taxonomies/(?P<taxonomy>[A-Za-z0-9_-]+)/terms', $read + [
            'callback' => [self::class, 'terms'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/taxonomies/(?P<taxonomy>[A-Za-z0-9_-]+)/terms', $write + [
            'callback' => [self::class, 'create_term'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/create', $write + [
            'callback' => [self::class, 'create_content'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/terms', $write + [
            'callback' => [self::class, 'assign_terms'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/acf', $read + [
            'callback' => [self::class, 'acf_fields'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/acf', $write + [
            'callback' => [self::class, 'update_acf_fields'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/media', $read + [
            'callback' => [self::class, 'media'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/media/import', $write + [
            'callback' => [self::class, 'import_media'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/featured-image', $write + [
            'callback' => [self::class, 'set_featured_image'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/trash', $write + [
            'callback' => [self::class, 'trash_content'],
        ]);
    }

    public static function status() {
        return self::response([
            'status' => 'ready',
            'mode' => 'draft-first',
            'capabilities' => [
                'wordpress.content.create-draft',
                'wordpress.content.trash-unpublished',
                'wordpress.taxonomy.read',
                'wordpress.taxonomy.write',
                'wordpress.media.read',
                'wordpress.media.import-image',
                'wordpress.featured-image.write-unpublished',
                'acf.read',
                'acf.write-unpublished',
            ],
            'integrations' => [
                'acf' => function_exists('get_field_objects') && function_exists('update_field'),
            ],
        ]);
    }

    public static function taxonomies($request) {
        $post_type = sanitize_key((string) $request->get_param('post_type'));
        if ($post_type !== '' && !self::is_public_post_type($post_type)) {
            return self::error('ccf_post_type_forbidden', 'The requested post type is not public.', 400);
        }

        $objects = get_taxonomies(['public' => true], 'objects');
        $items = [];
        foreach ((array) $objects as $taxonomy) {
            if (!is_object($taxonomy) || empty($taxonomy->name)) {
                continue;
            }
            $object_types = array_values(array_filter(array_map('sanitize_key', (array) $taxonomy->object_type)));
            if ($post_type !== '' && !in_array($post_type, $object_types, true)) {
                continue;
            }
            $items[] = [
                'name' => (string) $taxonomy->name,
                'label' => isset($taxonomy->label) ? (string) $taxonomy->label : (string) $taxonomy->name,
                'hierarchical' => !empty($taxonomy->hierarchical),
                'object_types' => $object_types,
            ];
        }
        return self::response(['items' => $items]);
    }

    public static function terms($request) {
        $taxonomy = self::public_taxonomy((string) $request['taxonomy']);
        if (is_wp_error($taxonomy)) {
            return $taxonomy;
        }
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $search = sanitize_text_field((string) $request->get_param('search'));
        $args = [
            'taxonomy' => $taxonomy->name,
            'hide_empty' => false,
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'orderby' => 'name',
            'order' => 'ASC',
        ];
        if ($search !== '') {
            $args['search'] = $search;
        }
        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return $terms;
        }
        $count = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);
        $items = [];
        foreach ((array) $terms as $term) {
            $items[] = self::term_snapshot($term);
        }
        return self::response([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => is_wp_error($count) ? count($items) : (int) $count,
            ],
        ]);
    }

    public static function create_term($request) {
        $taxonomy = self::public_taxonomy((string) $request['taxonomy']);
        if (is_wp_error($taxonomy)) {
            return $taxonomy;
        }
        $body = self::body($request);
        $name = sanitize_text_field((string) ($body['name'] ?? ''));
        if ($name === '' || strlen($name) > 200) {
            return self::error('ccf_term_invalid', 'A term name between 1 and 200 characters is required.', 400);
        }
        $slug = isset($body['slug']) ? sanitize_title((string) $body['slug']) : '';
        $existing = term_exists($slug !== '' ? $slug : $name, $taxonomy->name);
        if ($existing) {
            $term_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
            $term = get_term($term_id, $taxonomy->name);
            return is_wp_error($term) || !$term
                ? self::error('ccf_term_lookup_failed', 'The existing term could not be read.', 500)
                : self::response(['term' => self::term_snapshot($term), 'created' => false]);
        }
        $args = [];
        if ($slug !== '') $args['slug'] = $slug;
        if (!empty($taxonomy->hierarchical) && isset($body['parent'])) {
            $parent = (int) $body['parent'];
            if ($parent > 0 && !term_exists($parent, $taxonomy->name)) {
                return self::error('ccf_term_parent_invalid', 'The requested parent term does not exist.', 400);
            }
            $args['parent'] = max(0, $parent);
        }
        $result = wp_insert_term($name, $taxonomy->name, $args);
        if (is_wp_error($result)) return $result;
        $term = get_term((int) $result['term_id'], $taxonomy->name);
        if (is_wp_error($term) || !$term) {
            return self::error('ccf_term_lookup_failed', 'The created term could not be read.', 500);
        }
        return self::response(['term' => self::term_snapshot($term), 'created' => true], 201);
    }

    public static function create_content($request) {
        $body = self::body($request);
        $idempotency = self::idempotency_key($body);
        if (is_wp_error($idempotency)) return $idempotency;
        $replayed = self::idempotency_get($idempotency);
        if (is_array($replayed)) {
            return self::response(['content' => $replayed, 'replayed' => true]);
        }

        $post_type = sanitize_key((string) ($body['post_type'] ?? 'post'));
        if (!self::is_public_post_type($post_type) || $post_type === 'attachment') {
            return self::error('ccf_post_type_forbidden', 'Only public non-attachment post types may be created.', 400);
        }
        $title = sanitize_text_field((string) ($body['title'] ?? ''));
        if ($title === '' || strlen($title) > 500) {
            return self::error('ccf_content_invalid', 'A title between 1 and 500 characters is required.', 400);
        }
        $status = sanitize_key((string) ($body['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'pending', 'private'], true)) {
            return self::error('ccf_content_status_forbidden', 'New content must start as draft, pending or private.', 400);
        }
        $postarr = [
            'post_type' => $post_type,
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => wp_kses_post((string) ($body['content'] ?? '')),
            'post_excerpt' => sanitize_textarea_field((string) ($body['excerpt'] ?? '')),
        ];
        if (isset($body['slug']) && (string) $body['slug'] !== '') {
            $postarr['post_name'] = sanitize_title((string) $body['slug']);
        }
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) return $post_id;
        $snapshot = self::content_snapshot((int) $post_id);
        if (is_wp_error($snapshot)) return $snapshot;
        self::idempotency_put($idempotency, $snapshot);
        return self::response(['content' => $snapshot, 'replayed' => false], 201);
    }

    public static function assign_terms($request) {
        $post = self::editable_unpublished_post((int) $request['id']);
        if (is_wp_error($post)) return $post;
        $body = self::body($request);
        $taxonomy = self::public_taxonomy((string) ($body['taxonomy'] ?? ''));
        if (is_wp_error($taxonomy)) return $taxonomy;
        if (!in_array($post->post_type, (array) $taxonomy->object_type, true)) {
            return self::error('ccf_taxonomy_not_attached', 'The taxonomy is not attached to this post type.', 400);
        }
        if (!isset($body['term_ids']) || !is_array($body['term_ids'])) {
            return self::error('ccf_terms_invalid', 'term_ids must be an array.', 400);
        }
        $term_ids = array_values(array_unique(array_filter(array_map('intval', $body['term_ids']), function ($id) {
            return $id > 0;
        })));
        foreach ($term_ids as $term_id) {
            if (!term_exists($term_id, $taxonomy->name)) {
                return self::error('ccf_term_invalid', 'One or more requested terms do not exist.', 400);
            }
        }
        $append = !empty($body['append']);
        $result = wp_set_object_terms((int) $post->ID, $term_ids, $taxonomy->name, $append);
        if (is_wp_error($result)) return $result;
        return self::response([
            'post_id' => (int) $post->ID,
            'taxonomy' => (string) $taxonomy->name,
            'term_ids' => array_values(array_map('intval', (array) $result)),
        ]);
    }

    public static function acf_fields($request) {
        $post_id = (int) $request['id'];
        if (!get_post($post_id)) {
            return self::error('ccf_content_not_found', 'WordPress content was not found.', 404);
        }
        if (!function_exists('get_field_objects')) {
            return self::error('ccf_acf_unavailable', 'ACF is not available on this website.', 409);
        }
        $objects = get_field_objects($post_id, false, true);
        $fields = [];
        foreach ((array) $objects as $field) {
            if (!is_array($field) || empty($field['key']) || empty($field['name'])) continue;
            $fields[] = [
                'key' => (string) $field['key'],
                'name' => (string) $field['name'],
                'label' => isset($field['label']) ? (string) $field['label'] : (string) $field['name'],
                'type' => isset($field['type']) ? (string) $field['type'] : '',
                'value' => array_key_exists('value', $field) ? $field['value'] : null,
            ];
        }
        return self::response(['post_id' => $post_id, 'fields' => $fields]);
    }

    public static function update_acf_fields($request) {
        $post = self::editable_unpublished_post((int) $request['id']);
        if (is_wp_error($post)) return $post;
        if (!function_exists('get_field_object') || !function_exists('update_field')) {
            return self::error('ccf_acf_unavailable', 'ACF is not available on this website.', 409);
        }
        $body = self::body($request);
        if (!isset($body['fields']) || !is_array($body['fields']) || count($body['fields']) > 100) {
            return self::error('ccf_acf_invalid', 'fields must be an object with at most 100 entries.', 400);
        }
        $updated = [];
        foreach ($body['fields'] as $selector => $value) {
            $selector = sanitize_text_field((string) $selector);
            if (!preg_match('/^(field_[A-Za-z0-9]+|[A-Za-z0-9_-]{1,128})$/', $selector)) {
                return self::error('ccf_acf_selector_invalid', 'An ACF field selector is invalid.', 400);
            }
            $field = get_field_object($selector, (int) $post->ID, false, false);
            if (!is_array($field) || empty($field['key'])) {
                return self::error('ccf_acf_field_not_found', 'An ACF field could not be resolved for this post.', 404);
            }
            $ok = update_field((string) $field['key'], $value, (int) $post->ID);
            if ($ok === false && get_field((string) $field['key'], (int) $post->ID, false) !== $value) {
                return self::error('ccf_acf_update_failed', 'An ACF field could not be updated.', 500);
            }
            $updated[] = [
                'key' => (string) $field['key'],
                'name' => isset($field['name']) ? (string) $field['name'] : $selector,
            ];
        }
        return self::response(['post_id' => (int) $post->ID, 'updated' => $updated]);
    }

    public static function media($request) {
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $search = sanitize_text_field((string) $request->get_param('search'));
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $per_page,
            'paged' => $page,
            's' => $search,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
        ]);
        $items = [];
        foreach ((array) $query->posts as $attachment) {
            $items[] = self::media_snapshot((int) $attachment->ID);
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

    public static function import_media($request) {
        $body = self::body($request);
        $idempotency = self::idempotency_key($body);
        if (is_wp_error($idempotency)) return $idempotency;
        $replayed = self::idempotency_get($idempotency);
        if (is_array($replayed)) {
            return self::response(['media' => $replayed, 'replayed' => true]);
        }
        $url = esc_url_raw(trim((string) ($body['url'] ?? '')));
        if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return self::error('ccf_media_url_invalid', 'Only HTTPS image URLs are allowed.', 400);
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '' || self::host_is_private($host)) {
            return self::error('ccf_media_url_forbidden', 'The media URL host is not allowed.', 400);
        }
        $parent_id = isset($body['post_id']) ? (int) $body['post_id'] : 0;
        if ($parent_id > 0) {
            $post = self::editable_unpublished_post($parent_id);
            if (is_wp_error($post)) return $post;
        }
        if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('media_handle_sideload')) require_once ABSPATH . 'wp-admin/includes/media.php';
        if (!function_exists('wp_read_image_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 20);
        if (is_wp_error($tmp)) return $tmp;
        $size = @filesize($tmp);
        if ($size === false || $size < 1 || $size > self::MAX_IMAGE_BYTES) {
            @unlink($tmp);
            return self::error('ccf_media_size_invalid', 'The image is empty or exceeds the 10 MB limit.', 400);
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $name = sanitize_file_name(basename($path));
        if ($name === '' || strpos($name, '.') === false) $name = 'ccf-image.jpg';
        $filetype = wp_check_filetype_and_ext($tmp, $name);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (empty($filetype['type']) || !in_array((string) $filetype['type'], $allowed, true)) {
            @unlink($tmp);
            return self::error('ccf_media_type_forbidden', 'Only JPEG, PNG, GIF and WebP images are allowed.', 400);
        }
        if (!empty($filetype['proper_filename'])) $name = sanitize_file_name((string) $filetype['proper_filename']);
        $file = ['name' => $name, 'tmp_name' => $tmp];
        $title = sanitize_text_field((string) ($body['title'] ?? ''));
        $attachment_id = media_handle_sideload($file, $parent_id, $title);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        if (isset($body['alt_text'])) {
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $body['alt_text']));
        }
        $snapshot = self::media_snapshot((int) $attachment_id);
        self::idempotency_put($idempotency, $snapshot);
        return self::response(['media' => $snapshot, 'replayed' => false], 201);
    }

    public static function set_featured_image($request) {
        $post = self::editable_unpublished_post((int) $request['id']);
        if (is_wp_error($post)) return $post;
        $body = self::body($request);
        $attachment_id = (int) ($body['attachment_id'] ?? 0);
        if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
            return self::error('ccf_featured_image_invalid', 'attachment_id must identify an image attachment.', 400);
        }
        if (!set_post_thumbnail((int) $post->ID, $attachment_id)) {
            return self::error('ccf_featured_image_failed', 'The featured image could not be set.', 500);
        }
        return self::response([
            'post_id' => (int) $post->ID,
            'featured_image' => self::media_snapshot($attachment_id),
        ]);
    }

    public static function trash_content($request) {
        $post = self::editable_unpublished_post((int) $request['id']);
        if (is_wp_error($post)) return $post;
        $trashed = wp_trash_post((int) $post->ID);
        if (!$trashed) {
            return self::error('ccf_trash_failed', 'The content could not be moved to Trash.', 500);
        }
        return self::response([
            'post_id' => (int) $post->ID,
            'status' => 'trash',
        ]);
    }

    private static function editable_unpublished_post(int $post_id) {
        $post = get_post($post_id);
        if (!$post) return self::error('ccf_content_not_found', 'WordPress content was not found.', 404);
        if ((string) $post->post_status === 'publish') {
            return self::error(
                'ccf_published_content_requires_approval',
                'Published content must be changed through the preview/approval workflow.',
                409
            );
        }
        return $post;
    }

    private static function is_public_post_type(string $post_type): bool {
        $object = get_post_type_object($post_type);
        return is_object($object) && !empty($object->public);
    }

    private static function public_taxonomy(string $name) {
        $name = sanitize_key($name);
        $taxonomy = $name !== '' ? get_taxonomy($name) : false;
        if (!$taxonomy || empty($taxonomy->public)) {
            return self::error('ccf_taxonomy_forbidden', 'The requested taxonomy is not public.', 400);
        }
        return $taxonomy;
    }

    private static function term_snapshot($term): array {
        return [
            'id' => (int) $term->term_id,
            'taxonomy' => (string) $term->taxonomy,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'parent' => isset($term->parent) ? (int) $term->parent : 0,
            'count' => isset($term->count) ? (int) $term->count : 0,
        ];
    }

    private static function content_snapshot(int $post_id) {
        $post = get_post($post_id);
        if (!$post) return self::error('ccf_content_not_found', 'WordPress content was not found.', 404);
        return [
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'excerpt' => (string) $post->post_excerpt,
            'permalink' => (string) get_permalink((int) $post->ID),
        ];
    }

    private static function media_snapshot(int $attachment_id): array {
        $attachment = get_post($attachment_id);
        return [
            'id' => $attachment_id,
            'title' => $attachment ? (string) $attachment->post_title : '',
            'mime_type' => (string) get_post_mime_type($attachment_id),
            'url' => (string) wp_get_attachment_url($attachment_id),
            'alt_text' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'width' => (int) (wp_get_attachment_metadata($attachment_id)['width'] ?? 0),
            'height' => (int) (wp_get_attachment_metadata($attachment_id)['height'] ?? 0),
        ];
    }

    private static function body($request): array {
        $value = $request->get_json_params();
        return is_array($value) ? $value : [];
    }

    private static function idempotency_key(array $body) {
        $key = sanitize_text_field((string) ($body['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            return self::error('ccf_idempotency_invalid', 'A valid idempotency_key is required.', 400);
        }
        return $key;
    }

    private static function idempotency_get(string $key) {
        $value = get_transient('ccf_content_admin_' . hash('sha256', $key));
        return is_array($value) ? $value : null;
    }

    private static function idempotency_put(string $key, array $value): void {
        set_transient('ccf_content_admin_' . hash('sha256', $key), $value, self::IDEMPOTENCY_TTL);
    }

    private static function host_is_private(string $host): bool {
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') return true;
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function response(array $data, int $status = 200) {
        return new WP_REST_Response(['data' => $data, 'error' => null], $status);
    }

    private static function error(string $code, string $message, int $status) {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
