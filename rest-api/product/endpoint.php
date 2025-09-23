<?php
/**
 * Plugin Name: SMDP REST Products Endpoint (Optimized)
 * Description: Optimized /smdp/v1/products REST API endpoint with caching, modes, and reduced DB calls.
 * Version: 1.0.0
 * Author: Your Name
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the products REST API endpoint
 */
add_action('rest_api_init', function () {
    register_rest_route('smdp/v1', '/products', [
        'methods' => 'GET',
        'callback' => 'smdp_products_endpoint_callback',
        'permission_callback' => 'smdp_check_application_password_auth', // replace with your auth
    ]);
});

/**
 * Permission callback
 *
 * NOTE: This is a simple default that requires the user to be authenticated.
 * Replace or extend this function to validate application passwords / API keys / token as required.
 */
function smdp_check_application_password_auth(WP_REST_Request $request) {
    // 1) Allow authenticated users
    if (is_user_logged_in() && current_user_can('read')) {
        return true;
    }

    // 2) Optionally allow an API key via query param or header (example)
    //    Store a key in WP option 'smdp_api_key' (not created by this plugin).
    $provided = $request->get_param('smdp_api_key') ?: $request->get_header('x-smdp-api-key');
    $expected = get_option('smdp_api_key', false);
    if ($expected && $provided && hash_equals($expected, $provided)) {
        return true;
    }

    // By default deny. Replace with proper application password validation if you use WP Application Passwords.
    return new WP_Error('rest_forbidden', 'You are not authorized to access this endpoint', ['status' => 403]);
}

/**
 * Cache version handling
 *
 * We use a numeric version stored in an option to allow bulk invalidation:
 * - Cache keys include the version integer.
 * - When a product is saved, we increment the version so old transients are considered stale.
 */
function smdp_get_cache_version(): int {
    $v = (int) get_option('smdp_products_cache_version', 1);
    if ($v < 1) {
        $v = 1;
        update_option('smdp_products_cache_version', $v);
    }
    return $v;
}

function smdp_bump_cache_version() {
    // Increment, but don't let it grow unbounded — wrap around after a large number.
    $v = smdp_get_cache_version();
    $v++;
    if ($v > 9999999) $v = 1;
    update_option('smdp_products_cache_version', $v);
}

/**
 * When a product is saved, bump cache version (invalidates per-page transients)
 */
add_action('save_post_product', function ($post_id, $post, $update) {
    // Only bump when not an auto-draft/trash and when product is published or updated.
    if (wp_is_post_revision($post_id)) return;
    smdp_bump_cache_version();
}, 10, 3);

/**
 * In-request global cache: category paths
 *
 * Build a map: term_id => [id,title,slug,relative_slug,parent,count]
 * This runs once per request and avoids repeated get_terms() calls per product.
 */
function smdp_get_all_category_paths(): array {
    static $cached_paths = null;
    if ($cached_paths !== null) {
        return $cached_paths;
    }

    // Try a transient first (optional) - comment/uncomment if you want cross-request caching
    // $trans = get_transient('smdp_all_category_paths_v' . smdp_get_cache_version());
    // if ($trans !== false) {
    //     $cached_paths = $trans;
    //     return $cached_paths;
    // }

    $all_categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'fields' => 'id=>slug',
    ]);

    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ]);

    $cached_paths = [];

    if (!is_wp_error($categories) && !empty($categories)) {
        foreach ($categories as $category) {
            $ancestors = get_ancestors($category->term_id, 'product_cat');
            $path = '';
            foreach (array_reverse($ancestors) as $ancestor_id) {
                $path .= ($all_categories[$ancestor_id] ?? '') . '/';
            }
            $path .= $category->slug;

            $cached_paths[$category->term_id] = [
                'id' => $category->term_id,
                'title' => $category->name,
                'slug' => $category->slug,
                'relative_slug' => $path,
                'parent' => $category->parent,
                'count' => $category->count,
            ];
        }
    }

    // Optionally set transient to reuse across requests
    // set_transient('smdp_all_category_paths_v' . smdp_get_cache_version(), $cached_paths, DAY_IN_SECONDS);

    return $cached_paths;
}

/**
 * In-request tiered pricing cache for a product
 * Avoids repeated calls into TierPricingTable plugin per product in the same request.
 */
function smdp_get_product_tiered_pricing(int $product_id): array {
    static $tier_cache = [];

    if (isset($tier_cache[$product_id])) {
        return $tier_cache[$product_id];
    }

    $tiered_pricing = [];

    if (class_exists('\TierPricingTable\PriceManager') &&
        method_exists('\TierPricingTable\PriceManager', 'getPricingRule')) {

        try {
            $pricingRule = \TierPricingTable\PriceManager::getPricingRule($product_id);
            if (is_object($pricingRule) && method_exists($pricingRule, 'getRules')) {
                $rules = $pricingRule->getRules();
                if (is_array($rules)) {
                    foreach ($rules as $qty => $price) {
                        $tiered_pricing[] = ['qty' => (int)$qty, 'price' => (float)$price];
                    }
                    usort($tiered_pricing, function ($a, $b) {
                        return $a['qty'] <=> $b['qty'];
                    });
                }
            }
        } catch (Throwable $e) {
            // Do nothing — return empty tiered pricing on failure
            $tiered_pricing = [];
        }
    }

    $tier_cache[$product_id] = $tiered_pricing;
    return $tiered_pricing;
}

/**
 * Format a single product for output
 *
 * $mode: 'slug_only' | 'compact' | 'full'
 */
function smdp_format_product_data($product, string $mode = 'full') {
    if (!$product) return null;

    $product_id = (int) $product->get_id();

    $base = [
        'id' => $product_id,
        'title' => $product->get_name(),
        'slug' => $product->get_slug(),
    ];

    if ($mode === 'slug_only') {
        return $base;
    }

    if ($mode === 'compact') {
        $base['price'] = (float) $product->get_price();
        $base['stock_status'] = $product->get_stock_status() === 'instock';
        $base['sku'] = $product->get_sku();
        return $base;
    }

    // FULL mode
    // images: only include thumbnail + gallery ids that have valid URLs
    $images = [];
    $image_ids = $product->get_gallery_image_ids();
    array_unshift($image_ids, get_post_thumbnail_id($product_id));
    $image_ids = array_unique($image_ids);

    foreach ($image_ids as $image_id) {
        $image_id = (int) $image_id;
        if ($image_id <= 0) continue;
        $src = wp_get_attachment_url($image_id);
        if (!$src) continue;
        $post = get_post($image_id);
        $images[] = [
            'id' => $image_id,
            'src' => $src,
            'name' => ($post ? $post->post_title : ''),
            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
        ];
    }

    // categories using global in-request cache
    $all_category_paths = smdp_get_all_category_paths();
    $categories = wp_get_post_terms($product_id, 'product_cat');
    $category_data = [];

    if (!is_wp_error($categories) && !empty($categories)) {
        foreach ($categories as $cat) {
            $cat_id = (int) $cat->term_id;
            if (isset($all_category_paths[$cat_id])) {
                // mark primary? You may enhance by retrieving primary term if needed
                $category_data[] = $all_category_paths[$cat_id];
            } else {
                // fallback minimal
                $category_data[] = [
                    'id' => $cat_id,
                    'title' => $cat->name,
                    'slug' => $cat->slug,
                    'relative_slug' => $cat->slug,
                    'parent' => $cat->parent,
                    'count' => $cat->count,
                ];
            }
        }
    }

    // tiered pricing (from in-request cache)
    $tiered_pricing = smdp_get_product_tiered_pricing($product_id);

    // related (IDs only to avoid heavy load)
    $related_ids = array_values(array_map('intval', wc_get_related_products($product_id)));

    $full = array_merge($base, [
        'sku' => $product->get_sku(),
        'price' => (float) $product->get_price(),
        'regular_price' => (float) $product->get_regular_price(),
        'sale_price' => (float) $product->get_sale_price(),
        'stock_quantity' => (int) $product->get_stock_quantity(),
        'stock_status' => $product->get_stock_status() === 'instock',
        'featured' => (bool) $product->get_featured(),
        'rating_count' => (int) $product->get_rating_count(),
        'average_rating' => (float) $product->get_average_rating(),
        'images' => $images,
        'categories' => $category_data,
        'tiered_pricing' => $tiered_pricing,
        'related' => $related_ids,
        'description' => wp_strip_all_tags( str_replace(["\r","\n","\t"], ' ', $product->get_description() ) ),
        'short_description' => wp_strip_all_tags( str_replace(["\r","\n","\t"], ' ', $product->get_short_description() ) ),
    ]);

    return $full;
}

/**
 * Main endpoint callback
 *
 * Supports: per_page (1..50, default 20), page (1..), mode, include
 */
function smdp_products_endpoint_callback(WP_REST_Request $request) {
    // Basic parameter sanitization
    $per_page = max(1, min(50, intval($request->get_param('per_page') ?? 20)));
    $page = max(1, intval($request->get_param('page') ?? 1));
    $mode = in_array($request->get_param('mode'), ['slug_only', 'compact', 'full'], true) ? $request->get_param('mode') : 'full';
    $include = $request->get_param('include');

    // Normalize include param (accept comma-separated string or array)
    if (is_string($include) && strpos($include, ',') !== false) {
        $include = array_map('trim', explode(',', $include));
    }

    // Build cache key with current cache version (so bumping version invalidates all)
    $cache_version = smdp_get_cache_version();
    $include_key = is_array($include) ? md5(json_encode(array_values($include))) : (string) $include;
    $cache_key = "smdp_products_v{$cache_version}_mode_{$mode}_p{$page}_pp{$per_page}_inc_{$include_key}";

    // Try transient cache
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return rest_ensure_response($cached);
    }

    // Build query args
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'no_found_rows' => false, // we need found_posts for pagination
        'fields' => 'ids', // initially fetch only IDs to reduce WP_Query overhead
    ];

    // if include provided, enforce IDs
    if (!empty($include)) {
        $args['post__in'] = is_array($include) ? array_map('intval', $include) : [intval($include)];
        $args['posts_per_page'] = -1;
        $args['paged'] = 1;
        // if we only want specific fields, still use ids first
    }

    // First query to fetch IDs and pagination info
    $query = new WP_Query($args);

    $product_ids = $query->posts ?: [];

    // If there are no products, respond fast
    if (empty($product_ids)) {
        $response = [
            'products' => [],
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'per_page' => $per_page,
            'page' => $page,
        ];
        // Cache short (avoid repeated DB calls for empty results)
        set_transient($cache_key, $response, MINUTE_IN_SECONDS * 2);
        wp_reset_postdata();
        return rest_ensure_response($response);
    }

    // If mode is slug_only or compact, we can avoid heavy product object creation for large sets.
    $products = [];
    $all_category_paths = null; // will be loaded on-demand by smdp_format_product_data()

    foreach ($product_ids as $pid) {
        $pid = (int) $pid;
        // For compact/slug_only: still create WC_Product for consistent fields, but keep small
        $product = wc_get_product($pid);
        if (!$product) continue; // skip deleted/invalid

        $products[] = smdp_format_product_data($product, $mode);
    }

    wp_reset_postdata();

    $response = [
        'products' => $products,
        'total' => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'per_page' => $per_page,
        'page' => $page,
    ];

    // Cache result: longer for stable data, shorter for volatile stores.
    // Use 1 hour by default; adjust if you know products change frequently.
    set_transient($cache_key, $response, HOUR_IN_SECONDS);

    return rest_ensure_response($response);
}
