<?php

defined('ABSPATH') || exit;

/**
 * Safe editorial author support for CCF Sites & Ads.
 *
 * Read operations expose only public author identity data. The profile write
 * surface is deliberately restricted to the public display name; e-mail,
 * login, password, roles and capabilities cannot be changed here.
 */
final class CCF_Sites_Authors {
    private const READ_CAPABILITY = 'wordpress.content.author.read';
    private const PROFILE_WRITE_CAPABILITY = 'wordpress.author.profile.write';
    private const REST_NAMESPACE = 'ccf-sites/v1';

    public static function init(): void {
        add_filter('rest_post_dispatch', [self::class, 'augment_control_response'], 10, 3);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/authors/(?P<id>\\d+)/profile', [
            'methods' => 'POST',
            'permission_callback' => [CCF_Sites_Control::class, 'authorize'],
            'callback' => [self::class, 'update_profile'],
        ]);
    }

    public static function eligible_authors(): array {
        if (!function_exists('get_users') || !function_exists('user_can')) {
            return [];
        }

        $users = get_users([
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        $authors = [];

        foreach ((array) $users as $user) {
            $snapshot = self::author_snapshot($user);
            if ($snapshot !== null) {
                $authors[] = $snapshot;
            }
        }

        usort($authors, static function (array $a, array $b): int {
            $name = strcasecmp((string) $a['display_name'], (string) $b['display_name']);
            return $name !== 0 ? $name : ((int) $a['id'] <=> (int) $b['id']);
        });

        return $authors;
    }

    public static function update_profile($request) {
        $id = isset($request['id']) ? (int) $request['id'] : 0;
        if ($id <= 0 || !function_exists('get_user_by')) {
            return self::error('ccf_author_invalid', 'A valid author ID is required.', 400);
        }

        $user = get_user_by('id', $id);
        if (!$user || !function_exists('user_can') || !user_can($user, 'edit_posts')) {
            return self::error('ccf_author_not_found', 'An eligible WordPress author was not found.', 404);
        }

        $body = self::body($request);
        $display_name = sanitize_text_field((string) ($body['display_name'] ?? ''));
        if ($display_name === '' || strlen($display_name) > 250) {
            return self::error('ccf_author_display_name_invalid', 'display_name must contain between 1 and 250 characters.', 400);
        }
        if (!function_exists('wp_update_user')) {
            return self::error('ccf_author_update_unavailable', 'WordPress user updates are unavailable.', 500);
        }

        $result = wp_update_user([
            'ID' => $id,
            'display_name' => $display_name,
        ]);
        if (is_wp_error($result)) {
            return $result;
        }

        $updated = get_user_by('id', $id);
        $snapshot = self::author_snapshot($updated);
        if ($snapshot === null || $snapshot['display_name'] !== $display_name) {
            return self::error('ccf_author_update_failed', 'The author profile update could not be verified.', 500);
        }

        return self::response(['author' => $snapshot]);
    }

    public static function augment_control_response($response, $server, $request) {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $response;
        }
        if (!is_object($response) || !method_exists($response, 'get_data') || !method_exists($response, 'set_data')) {
            return $response;
        }

        $route = (string) $request->get_route();
        if ($route !== '/ccf-sites/v1/status' && $route !== '/ccf-sites/v1/inventory') {
            return $response;
        }

        $payload = $response->get_data();
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return $response;
        }

        if ($route === '/ccf-sites/v1/status') {
            $capabilities = isset($payload['data']['capabilities']) && is_array($payload['data']['capabilities'])
                ? $payload['data']['capabilities']
                : [];
            foreach ([self::READ_CAPABILITY, self::PROFILE_WRITE_CAPABILITY] as $capability) {
                if (!in_array($capability, $capabilities, true)) {
                    $capabilities[] = $capability;
                }
            }
            $payload['data']['capabilities'] = array_values($capabilities);
        } else {
            $payload['data']['authors'] = self::eligible_authors();
        }

        $response->set_data($payload);
        return $response;
    }

    private static function author_snapshot($user): ?array {
        if (!is_object($user) || !isset($user->ID) || !function_exists('user_can') || !user_can($user, 'edit_posts')) {
            return null;
        }
        $id = (int) $user->ID;
        if ($id <= 0) {
            return null;
        }
        $display_name = isset($user->display_name) ? trim((string) $user->display_name) : '';
        $slug = isset($user->user_nicename) ? trim((string) $user->user_nicename) : '';
        $author_url = function_exists('get_author_posts_url') ? (string) get_author_posts_url($id, $slug !== '' ? $slug : null) : '';
        return [
            'id' => $id,
            'display_name' => $display_name,
            'slug' => $slug,
            'author_url' => $author_url,
            'can_edit_posts' => true,
        ];
    }

    private static function body($request): array {
        $body = json_decode((string) $request->get_body(), true);
        return is_array($body) ? $body : [];
    }

    private static function response(array $data, int $status = 200) {
        return new WP_REST_Response(['data' => $data, 'error' => null], $status);
    }

    private static function error(string $code, string $message, int $status) {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
