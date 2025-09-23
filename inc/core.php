<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
  exit;
}


// Check if the user is authenticated via Application Passwords
function smdp_check_application_password_auth()
{
  if (function_exists('rest_get_authenticated_user')) {
    $user = rest_get_authenticated_user();
    return $user && !is_wp_error($user);
  }
  // Fallback (if not in REST context or very early)
  return is_user_logged_in();
}

// /**
//  * Permission callback
//  *
//  * NOTE: This is a simple default that requires the user to be authenticated.
//  * Replace or extend this function to validate application passwords / API keys / token as required.
//  */
// function smdp_check_application_password_auth(WP_REST_Request $request) {
//     // 1) Allow authenticated users
//     if (is_user_logged_in() && current_user_can('read')) {
//         return true;
//     }

//     // 2) Optionally allow an API key via query param or header (example)
//     //    Store a key in WP option 'smdp_api_key' (not created by this plugin).
//     $provided = $request->get_param('smdp_api_key') ?: $request->get_header('x-smdp-api-key');
//     $expected = get_option('smdp_api_key', false);
//     if ($expected && $provided && hash_equals($expected, $provided)) {
//         return true;
//     }

//     // By default deny. Replace with proper application password validation if you use WP Application Passwords.
//     return new WP_Error('rest_forbidden', 'You are not authorized to access this endpoint', ['status' => 403]);
// }

// Validate WooCommerce REST API credentials
function smdp_validate_wc_api_auth()
{
  if (!is_ssl()) {
    return new WP_Error('rest_forbidden', 'HTTPS required.', ['status' => 403]);
  }
  $token = $request->get_param('token');
  $consumer_key = $_GET['consumer_key'] ?? '';
  $consumer_secret = $_GET['consumer_secret'] ?? '';

  if (!$consumer_key || !$consumer_secret) {
    return new WP_Error('rest_forbidden', 'Missing API credentials.', ['status' => 401]);
  }

  $keys = wc_get_consumer_key_data($consumer_key);

  if (
    !$keys ||
    !hash_equals($keys['consumer_secret'], $consumer_secret) ||
    $keys['permissions'] !== 'read'
  ) {
    return new WP_Error('rest_forbidden', 'Invalid WooCommerce API credentials.', ['status' => 401]);
  }

  wp_set_current_user($keys['user_id']);

  return true;
}


// Extract HTML content from Elementor JSON structure
function extract_elementor_editor_html($elementor_json)
{
  if (empty($elementor_json)) return '';

  $data = is_string($elementor_json) ? json_decode($elementor_json, true) : $elementor_json;
  if (!is_array($data)) return '';

  $html_parts = [];

  foreach ($data as $el) {
    if (isset($el['elements'])) {
      foreach ($el['elements'] as $child) {
        if (
          $child['elType'] === 'widget' &&
          $child['widgetType'] === 'text-editor' &&
          !empty($child['settings']['editor'])
        ) {
          $html_parts[] = $child['settings']['editor'];
        }
      }
    }
  }

  return implode("\n", $html_parts);
}


/**
 * Get product attributes with filtering options and proper naming
 *
 * @param int|WC_Product $product Product ID or WC_Product object
 * @param array $attributes_to_skip Array of attribute slugs to exclude
 * @return array Formatted attribute data
 */
function get_filtered_product_attributes($product, $attributes_to_skip = array()) {
    // Convert product ID to object if needed
    if (is_numeric($product)) {
        $product = wc_get_product($product);
    }
    
    // Validate product
    if (!$product || !is_a($product, 'WC_Product')) {
        return array();
    }

    $attribute_data = array();
    
    foreach ($product->get_attributes() as $attribute_key => $attribute) {
        // Skip invalid or non-visible attributes
        if (!$attribute || !$attribute->get_visible()) {
            continue;
        }
        
        // Clean the attribute slug (remove 'pa_' prefix)
        $clean_slug = str_replace('pa_', '', $attribute_key);
        
        // Skip excluded attributes
        if (in_array($attribute_key, $attributes_to_skip) || in_array($clean_slug, $attributes_to_skip)) {
            continue;
        }
        
        // Get the proper display name
        $display_name = $attribute->get_name();
        
        // For taxonomy attributes, get the proper label
        if ($attribute->is_taxonomy()) {
            $taxonomy = get_taxonomy($attribute_key);
            if ($taxonomy && !is_wp_error($taxonomy)) {
                $display_name = $taxonomy->labels->singular_name;
            }
        }
        
        // Process options
        $options = array();
        if ($attribute->is_taxonomy()) {
            $terms = wc_get_product_terms($product->get_id(), $attribute_key, array('fields' => 'all'));
            foreach ($terms as $term) {
                $options[] = array(
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'id'   => $term->term_id
                );
            }
        } else {
            foreach ($attribute->get_options() as $option) {
                $options[] = array(
                    'name' => $option,
                    'slug' => sanitize_title($option)
                );
            }
        }
        
        // Skip if only one option with name '-'
        if (count($options) === 1 && $options[0]['name'] === '-') {
            continue;
        }
        
        if (!empty($options)) {
            $attribute_data[] = array(
                'id'        => $attribute->get_id(),
                'name'      => $display_name,
                'slug'      => $clean_slug,
                'position'  => $attribute->get_position(),
                'options'   => $options
            );
        }
    }
    
    return $attribute_data;
}

// Add this where you update categories
add_action('edited_product_cat', function ($term_id) {
  // Find all products in this category and clear their caches
  $products = get_posts([
    'post_type' => 'product',
    'tax_query' => [[
      'taxonomy' => 'product_cat',
      'terms' => $term_id
    ]],
    'fields' => 'ids'
  ]);

  foreach ($products as $product_id) {
    delete_transient('product_cat_paths_' . $product_id);
  }
});