# 04 — API Reference

Base URL: `http://localhost:8000/api` (with `php artisan serve`).

All routes require a tenant identifier — see [Tenant resolution](#tenant-resolution). There is no authentication; routes are public (Sanctum is installed but not enforced).

## Tenant resolution

`ResolveTenant` middleware resolves the tenant in this order:

1. `X-Tenant-Id` header — tenant **id** (numeric string).
2. `X-Tenant-Subdomain` header — note: the value is cast to `(int)` and looked up by **id**, not by subdomain string ([known-issue #6](08-known-issues.md)).
3. First subdomain of the request host (`acme.example.com` → `acme`) — also cast to int and looked up by id, which only works when the subdomain *is* a numeric id.

| Condition | Result |
| --------- | ------ |
| No identifier found | `401` — `Tenant identifier missing` |
| Identifier doesn't match a tenant | `404` (Laravel not-found) |

---

## GET /api/stock-levels

Current stock per (product, warehouse) for the resolved tenant.

### Query parameters

| Name | Type | Default | Description |
| ---- | ---- | ------- | ----------- |
| `product_id` | int | — | Filter by product |
| `warehouse_id` | int | — | Filter by warehouse |
| `page` | int | 1 | Pagination page (25 per page) |

### Example

```bash
curl "http://localhost:8000/api/stock-levels?product_id=1" \
  -H "X-Tenant-Id: 1"
```

### Response `200`

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "tenant_id": 1,
      "product_id": 1,
      "warehouse_id": 1,
      "quantity": "50.000",
      "created_at": "2026-11-03T10:00:00.000000Z",
      "updated_at": "2026-11-03T10:00:00.000000Z"
    }
  ],
  "first_page_url": "http://localhost/api/stock-levels?page=1",
  "from": 1,
  "last_page": 1,
  "links": [ … ],
  "next_page_url": null,
  "path": "http://localhost/api/stock-levels",
  "per_page": 25,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

Note `quantity` serializes as a string (`"50.000"`) due to the `decimal:3` model cast — the column itself is an integer.

---

## POST /api/stock-movements

Record a movement and update stock levels atomically.

### Request body

| Field | Type | Required | Rules |
| ----- | ---- | -------- | ----- |
| `type` | string | yes | `in`, `out`, or `transfer` |
| `product_id` | int | yes | Must belong to the tenant (checked in handler) |
| `warehouse_id` | int | yes | Must belong to the tenant. For transfers: the **source** warehouse |
| `quantity` | int | yes | ≥ 1 |
| `reference` | string | no | ≤ 255 chars (e.g. PO number) |
| `meta` | object | no | For `transfer`: `{ from_warehouse_id, to_warehouse_id }` required |

### Examples

Stock in:

```bash
curl -X POST http://localhost:8000/api/stock-movements \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: 1" \
  -d '{
    "type": "in",
    "product_id": 1,
    "warehouse_id": 1,
    "quantity": 50,
    "reference": "PO-1234"
  }'
```

Stock out:

```bash
curl -X POST http://localhost:8000/api/stock-movements \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: 1" \
  -d '{
    "type": "out",
    "product_id": 1,
    "warehouse_id": 1,
    "quantity": 10,
    "reference": "SO-0042"
  }'
```

Transfer:

```bash
curl -X POST http://localhost:8000/api/stock-movements \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: 1" \
  -d '{
    "type": "transfer",
    "product_id": 1,
    "warehouse_id": 1,
    "quantity": 10,
    "meta": { "from_warehouse_id": 1, "to_warehouse_id": 2 }
  }'
```

### Response `201`

```json
{
  "id": 42,
  "type": "in",
  "quantity": 50,
  "created_at": "2026-11-03T10:00:00.000000Z"
}
```

⚠️ The returned `id`/`created_at` come from re-querying the tenant's latest movement, which can be the wrong row under concurrent writes ([known-issue #4](08-known-issues.md)). The level update itself is correct.

### Errors

| Status | Condition | Body |
| ------ | --------- | ---- |
| `422` | Validation failure | `{ "message": "...", "errors": { field: [ … ] } }` |
| `422` | Product/warehouse not owned by tenant | `{ "error": "Product does not belong to this tenant" }` |
| `422` | Transfer meta missing / same warehouse / missing stock-level rows | `{ "error": "…" }` |
| `500` | Insufficient stock — `InsufficientStockException` class doesn't exist ([known-issue #5](08-known-issues.md)) | fatal error page (with `APP_DEBUG=true`, a stack trace) |

---

## GET /api/products/{sku}/history

Movement history for a product by SKU, newest first, 25 per page.

### URL parameters

| Name | Type | Description |
| ---- | ---- | ----------- |
| `sku` | string | Product SKU (unique within the tenant) |

### Example

```bash
curl "http://localhost:8000/api/products/TEST-SKU-001/history" \
  -H "X-Tenant-Id: 1"
```

### Response `200`

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 3,
      "tenant_id": 1,
      "product_id": 1,
      "warehouse_id": 1,
      "type": "transfer",
      "quantity": 10,
      "reference": null,
      "meta": { "from_warehouse_id": 1, "to_warehouse_id": 2 },
      "created_at": "2026-11-03T11:00:00.000000Z",
      "product": {
        "id": 1,
        "tenant_id": 1,
        "sku": "TEST-SKU-001",
        "name": "Test Product",
        "unit_price": "10.00",
        "created_at": "…",
        "updated_at": "…"
      }
    }
  ],
  "per_page": 25,
  "total": 1
}
```

Unknown SKU → `200` with empty `data`, not 404.

---

## Debug route (not production API)

`GET /api/test-tenant` — middleware alias `tenant` (`SetTenant`), returns all products for the tenant from `X-Tenant-ID`. Used during development; expect to remove it.

## Non-API endpoints

- `GET /` — Laravel welcome view (web).
- `GET /up` — health check (returns 200 when the app boots).

## Conventions

- All responses are JSON; errors from middleware (401/404) use Laravel's default exception rendering (JSON when the request expects JSON).
- Movement `warehouse_id` on a transfer is the **source**; the pair also lives in `meta` — treat `meta` as authoritative for replay.
- Pagination is 25/page via Laravel's paginator on all list endpoints.
