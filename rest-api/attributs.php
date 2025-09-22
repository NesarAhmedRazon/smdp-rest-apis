<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Make sure WooCommerce is active
function smdp_get_all_attributes_with_terms()
{
  $attribute_taxonomies = wc_get_attribute_taxonomies();
  $results = [];

  foreach ($attribute_taxonomies as $attr) {
    $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);

    if (!taxonomy_exists($taxonomy)) {
      continue;
    }

    $terms = get_terms([
      'taxonomy'   => $taxonomy,
      'hide_empty' => false,
    ]);

    $formatted_terms = array_map(function ($term) {
      return [
        'id'    => $term->term_id,
        'name'  => $term->name,
        'slug'  => $term->slug,
        'count' => $term->count,
      ];
    }, $terms);

    $aco = get_term_meta($attr->attribute_id, 'smdPicker_cat_to_attr', true);

    $results[] = [
      'id'             => $attr->attribute_id,
      'name'           => $attr->attribute_label,
      'slug'           => $taxonomy,
      'parent'         => 0,
      'aco_categories' => is_array($aco) ? $aco : [],
      'terms'          => $formatted_terms,
    ];
  }

  return rest_ensure_response($results);
}