<?php

defined('ABSPATH') || exit;

/**
 * Adds a safe, read-only list of eligible WordPress post authors to the
 * existing inventory response and advertises the matching capability.
 *
 * Deliberately excludes e-mail addresses, login names, roles and raw
 * capabilities. Only public author identity data required for editorial
 * attribution is returned.
 */
final class CCF_Sites_Authors {
    private const CAPABILITY = 'wordpress.content.author.read';

    public static function init(): void {
        add_filter('rest_post_dispatch', [self::class, 'augment_control_response'], 10, 3);
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
            if (!is_object($user) || !isset($user->ID) || !user_can($user, 'edit_posts')) {
                continue;
            }

            $id = (int) $user->ID;
            if ($id <= 0) {
                continue;
            }

            $display_name = isset($user->display_name) ? trim((string) $user->display_name) : '';
            $slug = isset($user->user_nicename) ? trim((string) $user->user_nicename) : '';
            $author_url = function_exists('get_author_posts_url') ? (string) get_author_posts_url($id, $slug !== '' ? $slug : null) : '';

            $authors[] = [
                'id' => $id,
                'display_name' => $display_name,
                'slug' => $slug,
                'author_url' => $author_url,
                'can_edit_posts' => true,
            ];
        }

        usort($authors, static function (array $a, array $b): int {
            $name = strcasecmp((string) $a['display_name'], (string) $b['display_name']);
            return $name !== 0 ? $name : ((int) $a['id'] <=> (int) $b['id']);
        });

        return $authors;
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
            if (!in_array(self::CAPABILITY, $capabilities, true)) {
                $capabilities[] = self::CAPABILITY;
            }
            $payload['data']['capabilities'] = array_values($capabilities);
        } else {
            $payload['data']['authors'] = self::eligible_authors();
        }

        $response->set_data($payload);
        return $response;
    }
}
