<?php

defined('ABSPATH') || exit;

final class CCF_Sites_Status_Overlay {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_route'], 20);
    }

    public static function register_route(): void {
        register_rest_route('ccf-sites/v1', '/status', [
            'methods' => 'GET',
            'permission_callback' => [CCF_Sites_Control::class, 'authorize'],
            'callback' => [self::class, 'status'],
        ], true);
    }

    public static function status() {
        $response = CCF_Sites_Control::status();
        if (!$response instanceof WP_REST_Response) {
            return $response;
        }
        $envelope = $response->get_data();
        if (!is_array($envelope) || !isset($envelope['data']) || !is_array($envelope['data'])) {
            return $response;
        }
        $data = $envelope['data'];
        $data['plugin'] = [
            'name' => 'CCF Sites & Ads Connector',
            'version' => defined('CCF_SITES_ADS_PLUGIN_VERSION') ? CCF_SITES_ADS_PLUGIN_VERSION : '1.2.0',
        ];
        $capabilities = isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : [];
        $data['capabilities'] = array_values(array_unique(array_merge($capabilities, [
            'wordpress.content.create-draft',
            'wordpress.content.trash-unpublished',
            'wordpress.taxonomy.read',
            'wordpress.taxonomy.write',
            'wordpress.media.read',
            'wordpress.media.import-image',
            'wordpress.featured-image.write-unpublished',
            'acf.read',
            'acf.write-unpublished',
            'rank-math.draft-write',
        ])));
        $envelope['data'] = $data;
        $response->set_data($envelope);
        return $response;
    }
}
