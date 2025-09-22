<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('smdp/v1', '/all_categories', [
        'methods' => 'GET',
        'callback' => 'smdp_getCategories',
        'permission_callback' => 'smdp_check_application_password_auth',
    ]);
});

function smdp_getCategories(WP_REST_Request $request) {
    try {
        $per_page = max(1, min(100, intval($request->get_param('per_page') ?? 20)));
        $page = max(1, intval($request->get_param('page') ?? 1));
        $any = (bool) $request->get_param('any') ?? false;
        $offset = ($page - 1) * $per_page;

        // Get total count for pagination
        $all_categories_ids = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'fields'     => 'ids',
        ]);

        if (is_wp_error($all_categories_ids)) {
            return new WP_Error('categories_error', 'Failed to fetch categories', ['status' => 500]);
        }

        $total_categories = count($all_categories_ids);
        $total_pages      = ceil($total_categories / $per_page);

        // Get paginated categories
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'menu_order',
            'order'      => 'ASC',
            'number'     => $per_page,
            'offset'     => $offset,
        ]);

        if (is_wp_error($categories)) {
            return new WP_Error('categories_error', 'Failed to fetch categories', ['status' => 500]);
        }

        $formatted_categories = array_map(function ($category) {
            $prodCount = (int) $category->count;

            

            $is_primary = (bool) get_term_meta($category->term_id, 'is_primary', true);
            
            // Category Image
            $thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
            $image = null;
            if ($thumbnail_id) {
                $image_src = wp_get_attachment_image_src($thumbnail_id, 'full');
                if ($image_src) {
                    $image = [
                        'id'    => (int) $thumbnail_id,
                        'src'   => $image_src[0],
                        'title' => get_the_title($thumbnail_id),
                        'alt'   => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true) ?: $category->name,
                    ];
                }
            }

            // Relative Slug
            $ancestors = get_ancestors($category->term_id, 'product_cat');
            $relative_slug = strtolower($category->slug);
            if (!empty($ancestors)) {
                $ancestor_slugs = array_map(function($ancestor_id) {
                    $ancestor = get_term($ancestor_id, 'product_cat');
                    return strtolower($ancestor->slug);
                }, array_reverse($ancestors));
                $relative_slug = implode('/', $ancestor_slugs) . '/' . $relative_slug;
            }

            return [
                'id'            => (int) $category->term_id,
                'title'         => $category->name,
                'slug'          => $category->slug,
                'relative_slug' => $relative_slug,
                'is_primary'    => $is_primary,
                'parent'        => (int) $category->parent,
                'description'   => $category->description ?: '',
                'image'         => $image,
                'menu_order'    => property_exists($category, 'term_order') ? (int) $category->term_order : 0,
                'count'         => (int) $category->count,
            ];
        }, $categories);
$category_index = [];

// Step 1: Build initial structure
foreach ($categories as $category) {
    $product_ids = get_objects_in_term($category->term_id, 'product_cat'); // all products directly in this term
    $category_index[$category->term_id] = [
        'term'        => $category,
        'products'    => $product_ids,
        'children'    => [],
    ];
}

// Step 2: Build hierarchy
foreach ($category_index as $id => &$cat) {
    if ($cat['term']->parent && isset($category_index[$cat['term']->parent])) {
        $category_index[$cat['term']->parent]['children'][] = &$cat;
    }
}
unset($cat);

// Step 3: Recursive function to collect unique product IDs
function collect_products(&$cat) {
    $all_products = $cat['products'];
    foreach ($cat['children'] as &$child) {
        $all_products = array_merge($all_products, collect_products($child));
    }
    $cat['unique_products'] = array_unique($all_products);
    $cat['count'] = count($cat['unique_products']);
    return $cat['unique_products'];
}

// Step 4: Run collection
foreach ($category_index as &$cat) {
    if ($cat['term']->parent == 0) {
        collect_products($cat);
    }
}
unset($cat);

        return rest_ensure_response([
            'categories'  => array_values($formatted_categories),
            'total'       => (int) $total_categories,
            'totalPages'  => (int) $total_pages,
            'per_page'    => $per_page,
            'page'        => $page,
        ]);

    } catch (Exception $e) {
        return new WP_Error('server_error', $e->getMessage(), ['status' => 500]);
    }
}
