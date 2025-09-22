# SMDP REST APIs

This WordPress plugin provides a set of custom REST API endpoints for WooCommerce and WordPress.  
It is designed to extend functionality, simplify integration with external systems, and allow developers to easily add new routes.

---

## 🚀 Features

- Custom REST API endpoints (`/wp-json/smdp/v1/...`)
- Organized structure:
  - `rest-api/` → endpoint definitions
  - `inc/` → helper/utility functions
  - `smdp-rest-apis.php` → plugin bootstrap
- Easy to extend with new endpoints
- Built for WooCommerce/WordPress environments

---

## 📂 Project Structurec

```
smdp-rest-apis/
├── inc/                  # Helper/utility functions
├── rest-api/             # REST API endpoint definitions
├── smdp-rest-apis.php    # Main plugin bootstrap file
└── README.md             # This file
```

---

## ⚙️ Installation

1. Clone this repository or download it as a ZIP.
2. Place it inside your WordPress `wp-content/plugins/` directory.
3. Activate the plugin from **WordPress Admin > Plugins**.

---

## 🔗 Usage

Once activated, the plugin registers custom REST API endpoints.  
You can access them at:

```
https://yourdomain.com/wp-json/smdp/v1/{endpoint}
```

Example:

```
GET https://yourdomain.com/wp-json/smdp/v1/orders
```

---

## 🛠️ Extending the Plugin

### Adding a new endpoint

1. Create a new PHP file inside `rest-api/`. Example: `orders.php`
2. Register your route:

```php
add_action('rest_api_init', function() {
    register_rest_route('smdp/v1', '/orders', [
        'methods'  => 'GET',
        'callback' => 'smdp_get_orders',
    ]);
});

function smdp_get_orders(WP_REST_Request $request) {
    return [ 'message' => 'Orders endpoint works!' ];
}
```

3. Include the new file in `smdp-rest-apis.php` or autoload it.

---

## 📜 License

This project is open-sourced under the **MIT License**.
