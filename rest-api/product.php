<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('smdp/v1', '/products', [
        'methods' => 'GET',
        'callback' => 'smdp_get_products_with_auth',
        'permission_callback' => 'smdp_check_application_password_auth',
    ]);
});


function smdp_get_products_with_authx(WP_REST_Request $request)
{

    // Set default values and sanitize
    $per_page = max(1, min(100, intval($request->get_param('per_page') ?? 20)));
    $page = max(1, intval($request->get_param('page') ?? 1));
    $include = $request->get_param('include');
    // Log the request regardless of validation

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
    ];
    // Add include parameter if specified
    if (!empty($include)) {
        if (is_array($include)) {
            $args['post__in'] = array_map('intval', $include);
        } else {
            $args['post__in'] = [intval($include)];
        }
        // When using include, we want exact matches only
        $args['posts_per_page'] = -1;
        $args['paged'] = 1;
    }
    if (!$primary_category_id) {
          $primary_category_id = get_post_meta($product->get_id(), '_primary_term_product_cat', true);
          
          // Some plugins might store it differently
          if (!$primary_category_id) {
              $primary_category_id = get_post_meta($product->get_id(), 'rank_math_primary_product_cat', true); // Rank Math
          }



    $query = new WP_Query($args);
    $products = [];



    while ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());
        $product_id = $product->get_id();
        $title_bn         = get_post_meta(get_the_ID(), '_title_bn', true);
        $mfr              = $product->get_attribute('pa_manufacturer-part-number');
        $easyeda          = $product->get_attribute('pa_easyeda-id');
        $documents        = get_post_meta(get_the_ID(), '_extra_documents', true) ?: [];
        $moq              = get_post_meta(get_the_ID(), '_smdp_moq', true) ?: 1; // Default to 1 if not set

        $unit_of_measure  = get_post_meta(get_the_ID(), 'woodmart_price_unit_of_measure', true);
        $brand            = $product->get_attribute('pa_brand');
        $attribute_data = get_filtered_product_attributes($product, ['pa_brand', 'pa_manufacturer-part-number', 'pa_easyeda-id']);
        $modified = get_post_modified_time('Y-m-d H:i:s', true, $product->get_id());

        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        $primary_category_id = false;

        // Cache key for this product's category paths
        $cache_key = 'product_cat_paths_' . $product->get_id();
        $cached_paths = get_transient($cache_key);
        $breadcrumbs = [];

        if (false === $cached_paths) {
            // Only fetch all categories if we don't have cache
            $all_categories = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'fields' => 'id=>slug'
            ]);

            $cached_paths = [];

            foreach ($categories as $category) {
                $ancestors = get_ancestors($category->term_id, 'product_cat');
                $relative_slug = '';

                foreach (array_reverse($ancestors) as $ancestor_id) {
                    $relative_slug .= $all_categories[$ancestor_id] . '/';
                }
                $relative_slug .= $category->slug;

                $cached_paths[$category->term_id] = $relative_slug;
            }

            // Cache for 1 day (adjust as needed)
            set_transient($cache_key, $cached_paths, DAY_IN_SECONDS);
        }
        if (!$primary_category_id) {
            $primary_category_id = get_post_meta($product->get_id(), '_primary_term_product_cat', true);

            // Some plugins might store it differently
            if (!$primary_category_id) {
                $primary_category_id = get_post_meta($product->get_id(), 'rank_math_primary_product_cat', true); // Rank Math
            }

            $breadcrumbs = catBreadcrumb($primary_category_id);
        }
        $cleaned_categories = array_map(function ($category) use ($primary_category_id, $cached_paths) {

            return [
                'id' => $category->term_id,
                'title' => $category->name,
                'slug' => $category->slug,
                'relative_slug' => $cached_paths[$category->term_id] ?? $category->slug,
                'parent' => $category->parent,
                'count' => $category->count,
                'is_primary' => ($category->term_id == $primary_category_id)
            ];
        }, $categories);


        // Get product images
        $images = [];
        $image_ids = $product->get_gallery_image_ids();
        array_unshift($image_ids, get_post_thumbnail_id($product_id));
        foreach ($image_ids as $image_id) {
            $src = wp_get_attachment_url($image_id);
            if (!$src) {
                continue;
            }
            $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $post = get_post($image_id);
            $name = $post ? $post->post_title : '';

            $images[] = [
                'id'   => $image_id,
                'src'  => $src,
                'name' => $name,
                'alt'  => $alt,
            ];
        }
        // Get tiered pricing if the Tier Pricing Table plugin is active
        $tiered_pricing = [];
        if (
            class_exists('\TierPricingTable\PriceManager') &&
            method_exists('\TierPricingTable\PriceManager', 'getPricingRule')
        ) {

            $pricingRule = \TierPricingTable\PriceManager::getPricingRule($product->get_id());

            if (is_object($pricingRule) && method_exists($pricingRule, 'getRules')) {
                $rules = $pricingRule->getRules();

                // Transform the rules into the desired format
                foreach ($rules as $quantity => $price) {
                    $tiered_pricing[] = [
                        'qty' => (int) $quantity,  // Ensure quantity is integer
                        'price' => (float) $price  // Ensure price is float
                    ];
                }

                // Sort by quantity ascending
                usort($tiered_pricing, function ($a, $b) {
                    return $a['qty'] - $b['qty'];
                });
            }
        }
        // Get related products (cross-sells) instead of upsells
        $related_ids = [];
        $related_ids = array_map('intval', wc_get_related_products($product->get_id()));


        // Prepare product data
        $products[] = [
            'id'                  => $product->get_id(),
            'modified'            => $product->get_date_modified()->date('U'),
            'sku'                 => $product->get_sku(),
            'mfr'                 => $mfr,
            'lcsc'                => $easyeda,
            'slug'                => $product->get_slug(),
            'brand'               => $brand,
            'title'               => $product->get_name(),
            'title_bn'            => $title_bn ?: '',
            'description'         => str_replace(["\r", "\n", "\t"], '', $product->get_description()),
            'short_description'   => str_replace(["\r", "\n", "\t"], '', $product->get_short_description()),
            'featured'            => $product->get_featured(),
            'stock_quantity'      => (int)$product->get_stock_quantity(),
            'stock_status'        => $product ? $product->get_stock_status() === 'instock' : false,
            'sold_individually'   => $product->get_sold_individually(),
            'rating_count'        => $product->get_rating_count(),
            'average_rating'      => $product->get_average_rating(),
            'price'               => (float)$product->get_price(),
            'regular_price'       => (float)$product->get_regular_price(),
            'sale_price'          => (float)$product->get_sale_price(),
            'tiered_pricing'      => $tiered_pricing,
            'unit_of_measure'     => $unit_of_measure,
            'moq'                 => (int)$moq,
            'attributes'          => $attribute_data,
            'categories'          => $cleaned_categories,
            'related'             => $related_ids,
            'documents'           => $documents,
            'images'              => $images,
            'breadcrumbs'         => $breadcrumbs,

        ];
    }
// Get tiered pricing if the Tier Pricing Table plugin is active
    $tiered_pricing = [];
    if (class_exists('\TierPricingTable\PriceManager') && 
        method_exists('\TierPricingTable\PriceManager', 'getPricingRule')) {
        
        $pricingRule = \TierPricingTable\PriceManager::getPricingRule($product->get_id());
        
        if (is_object($pricingRule) && method_exists($pricingRule, 'getRules')) {
            $rules = $pricingRule->getRules();
            
            // Transform the rules into the desired format
            foreach ($rules as $quantity => $price) {
                $tiered_pricing[] = [
                    'qty' => (int) $quantity,  // Ensure quantity is integer
                    'price' => (float) $price  // Ensure price is float
                ];
            }
            
            // Sort by quantity ascending
            usort($tiered_pricing, function($a, $b) {
                return $a['qty'] - $b['qty'];
            });
        }
    }
  // Get related products (cross-sells) instead of upsells
  $related_ids =[];
    $related_ids = array_map('intval', wc_get_related_products($product->get_id()));
    


    wp_reset_postdata();
    return rest_ensure_response([
        'products' => $products,
        'total'    => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'per_page' => $per_page,
        'page'     => $page,
    ]);
}}

function smdp_get_products_with_auth(WP_REST_Request $request)
{
    // Set default values and sanitize
    $per_page = max(1, min(100, intval($request->get_param('per_page') ?? 20)));
    $page     = max(1, intval($request->get_param('page') ?? 1));
    $mode = $request->get_param('mode') ?? 'full'; // full, compact, slug_only
    $include  = $request->get_param('include');

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
    ];

    // Handle include
    if (!empty($include)) {
        $args['post__in'] = is_array($include) ? array_map('intval', $include) : [intval($include)];
        $args['posts_per_page'] = -1;
        $args['paged']          = 1;
    }

    $query    = new WP_Query($args);
    $products = [];

    while ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());

        if ($product) {
            // ✅ Reuse the single product formatter
            $products[] = smdp_format_product_data($product, $mode);
        }
    }
    wp_reset_postdata();

    return rest_ensure_response([
        'products'    => $products,
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'per_page'    => $per_page,
        'page'        => $page,
    ]);
}


// will take a category ID
// find it's parent with loop untill the top-level category
// return relative_slug like 'timing-components/crystal-oscillator' for 'Crystal Oscillator'
// with title/bnTitle
