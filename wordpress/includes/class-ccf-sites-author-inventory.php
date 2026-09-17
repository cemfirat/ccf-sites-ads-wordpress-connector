<?php

defined('ABSPATH') || exit;

/**
 * Adds a privacy-safe list of eligible post authors to the existing
 * CCF Sites & Ads inventory response. No logins, e-mail addresses or roles
 * are exposed.
 */
final class CCF_Sites_Author_Inventory {
    public static function init(): void {
        add_filter('rest_post_dispatch', [self::class, 'extend_inventory_response'], 10, 3);
    }

    public static function extend_inventory_response($response, $server, $request) {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $response;
        }
        if ((string) $request->get_route() !== '/ccf-sites/v1/inventory') {
            return $response;
        }
        if (!$response instanceof WP_REST_Response) {
            return $response;
        }

        $payload = $response->get_data();
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return $response;
        }

        $authors = [];
        foreach ((array) get_users(['orderby' => 'display_name', 'order' => 'ASC']) as $user) {
            if (!is_object($user) || !isset($user->ID)) {
                continue;
            }
            $user_id = (int) $user->ID;
            if ($user_id <= 0 || !user_can($user, 'edit_posts')) {
                continue;
            }
            $authors[] = [
                'id' => $user_id,
                'display_name' => isset($user->display_name) ? (string) $user->display_name : '',
                'url' => get_author_posts_url($user_id),
                'can_edit_posts' => true,
            ];
        }

        $payload['data']['authors'] = $authors;
        $response->set_data($payload);
        return $response;
    }
}
