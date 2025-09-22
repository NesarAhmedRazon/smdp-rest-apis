<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}



function smdp_UtilsEndpoint($request)
{
    $query = sanitize_text_field($request->get_param('q'));
    $results = [];


    if (empty($query)) {
        return new WP_Error('no_query', 'No query provided', ['status' => 400]);
    }

    /// utils?q=last-modified
    // if q is 'last-modified' get the last modified post time(page or post or product) and return it as a timestamp
    if ($query === 'last-modified') {
        $args = [
            'post_type'      => ['post', 'page', 'product'],
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];

        $last_modified_posts = get_posts($args);

        if (!empty($last_modified_posts)) {
            $last_modified_post_id = $last_modified_posts[0];
            $last_modified_time = get_post_modified_time('U', false, $last_modified_post_id) * 1000;
            $results['last_modified'] = (int)$last_modified_time;
        } else {
            $results['last_modified'] = null;
        }
    } else {
        return new WP_Error('invalid_query', 'Invalid query parameter', ['status' => 400]);
    }
    return rest_ensure_response($results);
}

