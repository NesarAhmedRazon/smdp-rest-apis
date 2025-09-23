SMDP REST Products Endpoint (Optimized)

Overview

This plugin extends WordPress + WooCommerce with an optimized custom
REST API endpoint for retrieving product data.
It provides a flexible, cache-friendly /smdp/v1/products endpoint with
multiple modes (slug_only, compact, full) and performance optimizations.

---

Endpoint URL

    /wp-json/smdp/v1/products

---

Parameters

---

Parameter Type Default Description

---

per_page integer 20 Number of products
per page. Range:
1–50.

page integer 1 Pagination page
number.

mode string full Output mode:
slug_only, compact,
full.

include array/int/csv — Optional. Product
IDs to include
(array or
comma-separated).

---

---

Modes

slug_only

Minimal response. Includes only product ID, title, and slug.

Example response:

    {
      "id": 123,
      "title": "Sample Product",
      "slug": "sample-product"
    }

compact

Adds basic commerce fields.

    {
      "id": 123,
      "title": "Sample Product",
      "slug": "sample-product",
      "price": 25.99,
      "stock_status": true,
      "sku": "SKU123"
    }

full

Full product details including images, categories, tiered pricing (if
TierPricingTable plugin active), related products, and descriptions.

    {
      "id": 123,
      "title": "Sample Product",
      "slug": "sample-product",
      "sku": "SKU123",
      "price": 25.99,
      "regular_price": 30.00,
      "sale_price": 25.99,
      "stock_quantity": 15,
      "stock_status": true,
      "featured": false,
      "rating_count": 2,
      "average_rating": 4.5,
      "images": [
        {
          "id": 456,
          "src": "https://example.com/image.jpg",
          "name": "Product Image",
          "alt": "Alt text"
        }
      ],
      "categories": [
        {
          "id": 12,
          "title": "Category",
          "slug": "category",
          "relative_slug": "parent/category",
          "parent": 5,
          "count": 42
        }
      ],
      "tiered_pricing": [
        { "qty": 10, "price": 20.00 },
        { "qty": 50, "price": 18.00 }
      ],
      "related": [234, 345, 456],
      "description": "Full product description...",
      "short_description": "Short desc..."
    }

---

Caching

- Per-Page Transient Caching: Responses are cached by
  mode + page + per_page + include.
- Cache Versioning: Product save events increment a cache version
  number to invalidate stale caches.
- In-Request Caching: Category paths and tiered pricing are cached per
  request to avoid duplicate DB calls.

---

Authentication

- Default: Requires logged-in user with read capability.
- Optional: API key support (header x-smdp-api-key or query param
  smdp_api_key) if configured in WP options.
- Replace the permission_callback with your preferred method (e.g., WP
  Application Passwords).

---

Example Requests

Get first page of products (full mode):

    GET /wp-json/smdp/v1/products

Get products with minimal info:

    GET /wp-json/smdp/v1/products?mode=slug_only&per_page=10

Get specific product IDs (full detail):

    GET /wp-json/smdp/v1/products?include=123,124,125

Paginated request:

    GET /wp-json/smdp/v1/products?page=2&per_page=5

---

Response Metadata

Response always includes:

    {
      "products": [...],
      "total": 250,
      "total_pages": 13,
      "per_page": 20,
      "page": 1
    }

- total: Total number of products matching query
- total_pages: Total number of pages
- per_page: Page size used
- page: Current page index

---

Performance Notes

- Designed for catalogs up to thousands of products.
- For very large exports, consider background jobs.
- Persistent object cache (Redis/Memcached) strongly recommended.
- Rate limit the endpoint at reverse proxy / WAF for public access.

---

License

MIT or GPL-compatible, depending on your project.
