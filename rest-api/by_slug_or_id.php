<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}





function smdp_getSinglePostEndpoint(WP_REST_Request $request)
{
    $identifier = trim(urldecode($request->get_param('q')), "\"'");
    $mode = $request->get_param('mode') ?? 'full'; // full, compact, slug_only
    $post = null;

    // Detect numeric ID or slug
    if (is_numeric($identifier)) {
        $post = get_post((int) $identifier);
    } else {
        $posts = get_posts([
            'post_type'      => ['post', 'page', 'product'],
            'name'           => $identifier,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ]);
        if (!empty($posts)) {
            $post = $posts[0];
        }
    }

    // Not found
    if (!$post || !in_array($post->post_type, ['post', 'page', 'product'], true)) {
        return new WP_Error(
            'not_found',
            'Content not found: ' . esc_html($identifier),
            ['status' => 404]
        );
    }

    $id   = $post->ID;
    $data = [
        'type' => $post->post_type,
        'data' => []
    ];

    // Product
    if ($post->post_type === 'product' && function_exists('wc_get_product')) {
        $product = wc_get_product($id);
        if ($product) {
            $data['data'] = smdp_format_product_data($product, $mode);
        }
    }

    // Page
    elseif ($post->post_type === 'page') {
        $page = get_post($id);
        if ($page) {
            $title_bn = get_post_meta($page->ID, '_title_bn', true);
            $content  = apply_filters('the_content', $page->post_content);

            $data['data'] = smdp_formatPageData($page, $mode);
        }
    }

    // Post
    elseif ($post->post_type === 'post') {
        $content  = apply_filters('the_content', $post->post_content);

        $data['data'] = [
            'id'        => $post->ID,
            'slug'      => $post->post_name,
            'title'     => get_the_title($post->ID),
            'excerpt'   => get_the_excerpt($post->ID),
            'content'   => str_replace(["\r", "\n", "\t"], '', $content),
        ];
    }

    return rest_ensure_response($data);
}




function smdp_format_product_data($product, $mode = 'full')
{
    if (is_null($product) || !($product instanceof WC_Product)) {
        return null;
    }

    $product_id = $product->get_id();

    $data = [];

    if ($mode == 'full') {
        $title_bn         = get_post_meta(get_the_ID(), '_title_bn', true);
        $mfr              = $product->get_attribute('pa_manufacturer-part-number');
        $easyeda          = $product->get_attribute('pa_easyeda-id');
        $documents        = get_post_meta(get_the_ID(), '_extra_documents', true) ?: [];
        $moq              = get_post_meta(get_the_ID(), '_smdp_moq', true) ?: 1; // Default to 1 if not set
        $unit_of_measure  = get_post_meta(get_the_ID(), 'woodmart_price_unit_of_measure', true);
        $brand            = $product->get_attribute('pa_brand');

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

        // Get product categories
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        $primary_category_id = false;

        // Cache key for this product's category paths
        $cache_key = 'product_cat_paths_' . $product->get_id();
        $cached_paths = get_transient($cache_key);
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

        // Get related products (cross-sells) instead of upsells
        $related_ids = [];
        $related_ids = array_map('intval', wc_get_related_products($product->get_id()));

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


        // Breadcrumbs
        $breadcrumbs = [];

        if (!$primary_category_id) {
            $primary_category_id = get_post_meta($product->get_id(), '_primary_term_product_cat', true);

            // Some plugins might store it differently
            if (!$primary_category_id) {
                $primary_category_id = get_post_meta($product->get_id(), 'rank_math_primary_product_cat', true); // Rank Math
            }

            $breadcrumbs = catBreadcrumb($primary_category_id);
        }
        $attribute_data = get_filtered_product_attributes($product, ['pa_brand', 'pa_manufacturer-part-number', 'pa_easyeda-id']);
        $data = [
            'modified'   => (int) $product->get_date_modified()->getTimestamp() * 1000,
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
    if ($mode == 'compact') {
        $title_bn         = get_post_meta(get_the_ID(), '_title_bn', true);
        $data = [
            'title'     => $product->get_name(),
            'title_bn'  => $title_bn ?: '',
            'slug'      => $product->get_slug(),
            'price'     => (float)$product->get_price(),
        ];
    }
    if ($mode == 'slug_only') {

        $data = [
            'slug'       => $product->get_slug(),
            'modified'   => (int) $product->get_date_modified()->getTimestamp() * 1000,
        ];
    }


    $data = [
        'id'         => $product->get_id(),
    ] + $data;

    return $data;
}


function catBreadcrumb($cat_id)
{
    $term = get_term($cat_id, 'product_cat');
    if (is_wp_error($term) || !$term) {
        return [];
    }

    // Get ancestors (top → parent chain)
    $ancestors = get_ancestors($term->term_id, 'product_cat');
    $ancestors = array_reverse($ancestors);

    $breadcrumb = [];
    $path = '';

    // Build breadcrumb for each ancestor
    foreach ($ancestors as $ancestor_id) {
        $ancestor = get_term($ancestor_id, 'product_cat');
        if ($ancestor && !is_wp_error($ancestor)) {
            $path .= ($path ? '/' : '') . $ancestor->slug;
            $breadcrumb[] = [
                'title'         => $ancestor->name,
                'title_bn'      => get_term_meta($ancestor->term_id, '_title_bn', true) ?: '',
                'slug'          => $ancestor->slug,
                'relative_slug' => $path,
            ];
        }
    }

    // Finally add the current category
    $path .= ($path ? '/' : '') . $term->slug;
    $breadcrumb[] = [
        'title'         => $term->name,
        'title_bn'      => get_term_meta($term->term_id, '_title_bn', true) ?: '',
        'slug'          => $term->slug,
        'relative_slug' => $path,
    ];

    return $breadcrumb;
}
