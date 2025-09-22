<?php

/**
 * Plugin Name: SMDP: REST APIs
 * Plugin URI: https://github.com/NesarAhmedRazon/smdp-rest-apis
 * Description: A WordPress plugin to log data into JSON files.
 * Version: 1.0.0
 * Author: Nesar Ahmed
 * Author URI: https://nesarahmed.dev/
 * License: GPLv2 or later
 * Text Domain: smdp-rest-apis
 * Domain Path: /languages/
 */


// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}


if (!defined('SMDP_PM_TEXTDOMAIN')) {
    define('SMDP_PM_TEXTDOMAIN', 'smdp-rest-apis');
}

if (!defined('SMDP_REST_APIS_DIR')) {
    define('SMDP_REST_APIS_DIR', plugin_dir_path(__FILE__));
}

if (!defined('SMDP_REST_APIS_URL')) {
    define('SMDP_REST_APIS_URL', plugin_dir_url(__FILE__));
}

if (!defined('SMDP_REST_APIS_FILE')) {
    define('SMDP_REST_APIS_FILE', __FILE__);
}


require_once SMDP_REST_APIS_DIR . 'inc/logger.php';
require_once SMDP_REST_APIS_DIR . 'inc/settings.php';
require_once SMDP_REST_APIS_DIR . 'inc/core.php';
require_once SMDP_REST_APIS_DIR . 'rest-api/index.php';