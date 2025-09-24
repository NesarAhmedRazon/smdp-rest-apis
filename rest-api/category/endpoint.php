<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('smdp/v1', '/categories', [
        'methods'             => 'GET',
        'callback'            => 'smdp_get_categories_with_auth',
        'permission_callback' => 'smdp_check_application_password_auth',
    ]);
});

/**
 * Format category data according to mode
 */
function smdp_format_category_data($term, $mode = 'compact') {
    // Build relative slug using ancestors
    $ancestors = get_ancestors($term->term_id, 'product_cat');
    $ancestors = array_reverse($ancestors);
    $slugs     = [];

    foreach ($ancestors as $ancestor_id) {
        $ancestor = get_term($ancestor_id, 'product_cat');
        if ($ancestor && !is_wp_error($ancestor)) {
            $slugs[] = $ancestor->slug;
        }
    }
    $slugs[]       = $term->slug; // Add current category
    $relative_slug = implode('/', $slugs);

    if ($mode === 'slug_only') {
        return [
            'id'   => $term->term_id,
            'title' => $term->name,
            'slug' => $relative_slug,
        ];
    }

    if ($mode === 'compact') {
        return [
            'id'    => $term->term_id,
            'title'  => $term->name,
            'slug'  => $relative_slug,
            'count' => (int) $term->count,
            'parent' => $term->parent,
        ];
    }

    if ($mode === 'full') {
        $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
        $image_url    = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null;

        return [
            'id'          => $term->term_id,
            'title'        => $term->name,
            'slug'        => $relative_slug,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => (int) $term->count,
            'image'       => $image_url,
        ];
    }

    return [];
}

/**
 * Categories REST API handler
 */
function smdp_get_categories_with_auth(WP_REST_Request $request) {
    $per_page = max(1, min(50, intval($request->get_param('per_page') ?? 10)));
    $page     = max(1, intval($request->get_param('page') ?? 1));
    $mode     = $request->get_param('mode') ?? 'compact';
    $include  = $request->get_param('include');
    $search   = sanitize_text_field($request->get_param('search'));
    $parent   = $request->get_param('parent');
    $orderby  = $request->get_param('orderby') ?? 'name';
    $order    = strtoupper($request->get_param('order') ?? 'ASC');

    $args = [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
        'orderby'    => $orderby,
        'order'      => $order,
        'search'     => $search,
    ];

    if (!empty($parent)) {
        $args['parent'] = intval($parent);
    }

    if (!empty($include)) {
        $ids           = is_array($include) ? array_map('intval', $include) : array_map('intval', explode(',', $include));
        $args['include'] = $ids;
        // disable pagination if include is provided
        $args['number'] = 0;
        $args['offset'] = 0;
    }

    $query = new WP_Term_Query($args);

    $categories = [];
    if (!empty($query->terms)) {
        foreach ($query->terms as $term) {
            $categories[] = smdp_format_category_data($term, $mode);
        }
    }

    // Total count (only if include not provided)
    $total = 0;
    if (empty($include)) {
        $count_query = new WP_Term_Query([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'fields'     => 'count',
            'search'     => $search,
            'parent'     => !empty($parent) ? intval($parent) : '',
        ]);
        $total = (int) $count_query->get_terms();
    } else {
        $total = count($categories);
    }

    return rest_ensure_response([
        'type'        => 'category',
        'total'      => $total,
        'total_pages' => (int) ceil($total / $per_page),
        'per_page'   => $per_page,
        'current_page' => $page,
        'data'       => $categories,
    ]);
}
