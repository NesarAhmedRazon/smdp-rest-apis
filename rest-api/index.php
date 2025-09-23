<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load parts
require_once SMDP_REST_APIS_DIR . 'rest-api/endpoints.php';
require_once SMDP_REST_APIS_DIR . 'rest-api/utils.php';
require_once SMDP_REST_APIS_DIR . 'rest-api/page.php';
require_once SMDP_REST_APIS_DIR . 'rest-api/product/endpoint.php';
require_once SMDP_REST_APIS_DIR . 'rest-api/categories.php';
require_once SMDP_REST_APIS_DIR . 'rest-api/by_slug_or_id.php';
