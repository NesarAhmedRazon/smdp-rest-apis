<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
  exit;
}

// Register settings and fields in General Settings
add_action('admin_init', function () {
    // Register Hook URL
    register_setting('general', 'next_hook_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);

    add_settings_field(
        'next_hook_url',
        __('Next Hook URL', SMDP_PM_TEXTDOMAIN),
        function () {
            $value = get_option('next_hook_url', '');
            echo '<input type="url" id="next_hook_url" name="next_hook_url" value="' . esc_attr($value) . '" class="regular-text ltr" />';
            echo '<p class="description">Webhook endpoint URL (e.g. from Next.js API).</p>';
        },
        'general'
    );

    // Register Hook Access Token
    register_setting('general', 'hook_access_token', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);

    add_settings_field(
        'hook_access_token',
        __('Hook Access Token', SMDP_PM_TEXTDOMAIN),
        function () {
            $value = get_option('hook_access_token', '');
            echo '<input type="password" id="hook_access_token" name="hook_access_token" value="' . esc_attr($value) . '" class="regular-text ltr" />';
            echo '<p class="description">Secure token to authorize outgoing webhooks.</p>';
        },
        'general'
    );
});

