<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('smdp/v1', '/utils', [
        'methods'             => 'GET',
        'callback'            => 'smdp_UtilsEndpoint',
        'args'                => [
            'q' => [
                'required'          => true,
                'validate_callback' => function ($param) {
                    return !empty($param);
                }
            ],
        ]
    ]);

    register_rest_route('smdp/v1', '/single', [
        'methods'             => 'GET',
        'callback'            => 'smdp_getSinglePostEndpoint',
        'args'                => [
            'q' => [
                'required'          => true,
                'validate_callback' => function ($param) {
                    return !empty($param);
                }
            ],
        ]
    ]);

    register_rest_route('smdp/v1', '/attributes-with-terms', [
        'methods'             => 'GET',
        'callback'            => 'smdp_get_all_attributes_with_terms',
        'permission_callback' => '__return_true', // ← ✅ this makes it public
    ]);
});