<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
  exit;
}

// Flexible Page endpoint
add_action('rest_api_init', function () {
  register_rest_route('smdp/v1', '/page/(?P<identifier>[^/]+)', [
    'methods'  => 'GET',
    'callback' => 'smdp_getPage',
    'permission_callback' => 'smdp_check_application_password_auth',
    'args'     => [
      'identifier' => [
        'required' => true,
        'validate_callback' => function ($param) {
          return !empty($param);
        }
      ]
    ]
  ]);
});

function smdp_getPage(WP_REST_Request $request)
{
  $logData = [
    'timestamp'  => date('Y-m-d H:i:s'),
    'origin'     => $request->get_header('origin'),
  ];

  // Step 1: Get identifier
  $identifier = $request->get_param('identifier');
  $identifier = trim(urldecode($identifier),  "\"'");
  $logData['identifier_raw'] = $identifier;

  $id   = null;
  $page = null;

  // Step 2: Determine if numeric or slug
  if (is_numeric($identifier)) {
    $id   = (int) $identifier;
    $page = get_post($id);
    $logData['identifier_type'] = 'numeric';
    $logData['id_from_numeric'] = $id;
    $logData['page_found_by_id'] = ($page && $page->post_type === 'page');
  } else {
    $identifier = trim(urldecode($identifier), '"');
    $logData['identifier_type'] = 'slug';

    $posts = get_posts([
      'post_type'      => 'page',
      'name'           => $identifier,
      'posts_per_page' => 1,
    ]);
    $logData['posts_query_result'] = wp_list_pluck($posts, 'ID');

    if (!empty($posts)) {
      $id   = $posts[0]->ID;
      $page = get_post($id);
      $logData['id_from_slug'] = $id;
      $logData['page_found_by_slug'] = ($page && $page->post_type === 'page');
    }
  }

  // Step 3: Page existence check
  if (!$page || $page->post_type !== 'page') {
    $logData['error'] = 'Page not found';
    logging($logData, 'page_by_id_steps.json');
    return new WP_Error('not_found', 'Page not found ' . $identifier, ['status' => 404]);
  }

  // Step 4: Collect page data
  $slug     = $page->post_name;
  $content  = apply_filters('the_content', $page->post_content);
  $title_bn = get_post_meta($page->ID, '_title_bn', true);

  $page_data = [
    'id'       => $page->ID,
    'slug'     => $slug,
    'title'    => get_the_title($page->ID),
    'title_bn' => $title_bn ?: '',
    'excerpt'  => get_the_excerpt($page->ID),
    'content'  => str_replace(["\r", "\n", "\t"], '', $content),
  ];

  // $logData['final_return'] = 'page_data_returned';
  // logging($logData, 'page_by_id_steps.json');

  return rest_ensure_response($page_data);
}


add_action('rest_api_init', function () {
  register_rest_route('smdp/v1', '/pages', [
    'methods' => 'GET',
    'callback' => 'smdp_get_pages_with_auth',
    'permission_callback' => 'smdp_check_application_password_auth',
  ]);
});

add_action('rest_api_init', function () {
  register_rest_route('smdp/v1', '/all', [
    'methods' => 'GET',
    'callback' => 'smdp_getPages',
    'permission_callback' => 'smdp_check_application_password_auth',
  ]);
});

function smdp_getPages(WP_REST_Request $request)
{
  // Set default values and sanitize
  $per_page = max(1, min(100, intval($request->get_param('per_page') ?? 5)));
  $page_no     = max(1, intval($request->get_param('page') ?? 1));
  $type    = $request->get_param('type') ?? 'product'; // product, page, post
  $mode = $request->get_param('mode') ?? 'full'; // full, compact, slug_only
  $include  = $request->get_param('include');

  $args = [
    'post_type'      => $type,
    'post_status'    => 'publish',
    'posts_per_page' => $per_page,
    'paged'          => $page_no,
  ];

  // Handle include
  if (!empty($include)) {
    $args['post__in'] = is_array($include) ? array_map('intval', $include) : [intval($include)];
    $args['posts_per_page'] = -1;
    $args['paged']          = 1;
  }

  $query    = new WP_Query($args);
  $data = [];

  while ($query->have_posts()) {
    $query->the_post();
    if ($type == 'product') {
      $product = wc_get_product(get_the_ID());
      if ($product) {
        // ✅ Reuse the single product formatter
        $data[] = smdp_format_product_data($product, $mode);
      }
    }
    if ($type == 'page') {
      $removePages = [
        'my-account',
        'tools',
        'products',
        'blog',
        'cart',
        'checkout',
        'controlling-your-esp32-s3-led-with-arduino-and-esp-idf',
        'home',
        'wishlist',
        'capacitance-converter',
        'maintenance',
        'delivery-return-2',
        'custom-404'
      ];
      $page = get_post(get_the_ID());
      if ($page) {
        // ✅ Reuse the single page formatter
        $data[] = smdp_formatPageData($page, $mode);
        // Skip excluded slugs
        if (in_array($page->post_name, $removePages, true)) {
          array_pop($data); // Remove last added page
          continue;
        }
      }
    }
  }
  wp_reset_postdata();

  return rest_ensure_response([
    'type'        => $type,
    'total'       => (int) $query->found_posts,
    'total_pages' => (int) $query->max_num_pages,
    'per_page'    => $per_page,
    'current_page'        => $page_no,
    'data'    => $data,
  ]);
}
function smdp_formatPageData($page, $mode = 'full')
{
  if (is_null($page) || $page->post_type !== 'page') {
    return null;
  }



  $data = [];

  if ($mode == 'slug_only') {
    $data = [
      'slug'      => $page->post_name,
      'modified'   => strtotime($page->post_modified_gmt) * 1000,
    ];
  }

  if ($mode == 'compact') {
    $title_bn = get_post_meta($page->ID, '_title_bn', true);
    $data = [
      'title'     => get_the_title($page->ID),
      'title_bn'  => $title_bn ?: '',
      'slug'      => $page->post_name,
    ];
  }
  if ($mode == 'full') {
    $title_bn = get_post_meta($page->ID, '_title_bn', true);
    $content  = apply_filters('the_content', $page->post_content);
    $data = [
      'slug'      => $page->post_name,
      'title'     => get_the_title($page->ID),
      'title_bn'  => $title_bn ?: '',
      'modified'   => strtotime($page->post_modified_gmt) * 1000,
      'excerpt'   => get_the_excerpt($page->ID),
      'content'   => str_replace(["\r", "\n", "\t"], '', $content),
    ];
  }

  $data = [
    'id'         => $page->ID,
  ] + $data;
  return $data;
}

function smdp_get_pages_with_auth(WP_REST_Request $request)
{
  $pages = get_pages();
  $result = [];
  $removePages = [
    'my-account',
    'tools',
    'products',
    'blog',
    'cart',
    'checkout',
    'controlling-your-esp32-s3-led-with-arduino-and-esp-idf',
    'home',
    'wishlist',
    'capacitance-converter',
    'maintenance',
    'delivery-return-2',
    'custom-404'
  ];

  foreach ($pages as $page) {

    $slug = $page->post_name;
    // Skip excluded slugs
    if (in_array($slug, $removePages, true)) {
      continue;
    }
    // Get post content
    $title_bn = get_post_meta($page->ID, '_title_bn', true);
    $raw_content = get_post_field('post_content', $page->ID);
    $rendered_content = trim(apply_filters('the_content', $raw_content));

    // Fallback to Elementor-rendered content if empty
    if (empty(strip_tags($rendered_content))) {
      $elementor_raw = get_post_meta($page->ID, '_elementor_data', true);

      // Optional: process only text blocks
      $elementor_content = extract_elementor_editor_html($elementor_raw);

      $content = $elementor_content ?: '';
    } else {
      $content = $rendered_content;
    }

    $result[] = [
      'id'    => $page->ID,
      'slug'  => $slug,
      'title' => get_the_title($page->ID),
      'title_bn' => $title_bn ?: '', // ✅ Add Bangla title
      'excerpt' => get_the_excerpt($page->ID),
      'content' => str_replace(["\r", "\n", "\t"], '', $content),
    ];
  }

  return new WP_REST_Response([
    'status' => 'success',
    'total'  => count($result),
    'pages'  => $result,
  ]);
}
