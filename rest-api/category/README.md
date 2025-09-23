# REST API Endpoint: /smdp/v1/categories

This endpoint provides **WooCommerce product categories** with support for multiple response modes, pagination, and filtering.

---

## 📌 Endpoint

**GET** `/wp-json/smdp/v1/categories`

---

## ⚙️ Query Parameters

| Parameter  | Type    | Default | Description                                       |
| ---------- | ------- | ------- | ------------------------------------------------- |
| `mode`     | string  | compact | Response mode: `slug_only`, `compact`, `full`.    |
| `search`   | string  | (none)  | Search by category name or slug.                  |
| `include`  | string  | (none)  | Comma-separated list of category IDs to include.  |
| `parent`   | integer | (none)  | Filter by parent category ID.                     |
| `orderby`  | string  | name    | Field to order by: `id`, `name`, `slug`, `count`. |
| `order`    | string  | asc     | Sort direction: `asc` or `desc`.                  |
| `per_page` | integer | 10      | Number of results per page (max 50).              |
| `page`     | integer | 1       | Current page number.                              |

---

## 🔄 Response Modes

- **slug_only** → minimal response (only `id` + `slug`).
- **compact** → `id`, `name`, `slug`, `count`.
- **full** → All fields: `id`, `name`, `slug`, `description`, `parent`, `count`, `image`.

---

## 📂 Example Requests

### 1. Default compact response (10 per page)

```http
GET /wp-json/smdp/v1/categories
```

### 2. Slug only mode

```http
GET /wp-json/smdp/v1/categories?mode=slug_only
```

### 3. Full details, 20 per page, page 2

```http
GET /wp-json/smdp/v1/categories?mode=full&per_page=20&page=2
```

### 4. Include specific category IDs (5, 12, 33)

```http
GET /wp-json/smdp/v1/categories?include=5,12,33
```

### 5. Search for categories containing "phone"

```http
GET /wp-json/smdp/v1/categories?search=phone
```

---

## 📦 Example Responses

### 🔹 Slug Only Mode

```json
{
  "page": 1,
  "per_page": 10,
  "total": 42,
  "categories": [
    { "id": 5, "slug": "phones" },
    { "id": 12, "slug": "accessories" }
  ]
}
```

### 🔹 Compact Mode

```json
{
  "page": 1,
  "per_page": 10,
  "total": 42,
  "categories": [
    { "id": 5, "name": "Phones", "slug": "phones", "count": 122 },
    { "id": 12, "name": "Accessories", "slug": "accessories", "count": 54 }
  ]
}
```

### 🔹 Full Mode

```json
{
  "page": 1,
  "per_page": 10,
  "total": 42,
  "categories": [
    {
      "id": 5,
      "name": "Phones",
      "slug": "phones",
      "description": "All smartphone products",
      "parent": 0,
      "count": 122,
      "image": "https://example.com/wp-content/uploads/2025/01/phones.jpg"
    }
  ]
}
```

---

## ✅ Notes

- `per_page` is capped at **50 maximum**.
- `include` parameter overrides pagination (returns only requested categories).
- Empty results return an empty array under `categories`.
- Errors are returned as `WP_Error` with details.

---

## 🔒 Permissions

- The endpoint is **publicly accessible** (`permission_callback => __return_true`).
- If needed, restrict access by changing the `permission_callback` in the controller.

---

## 🛠️ Integration

- Use this endpoint to fetch category lists for **filters, dropdowns, navigation menus, or product filters** in your frontend apps.
