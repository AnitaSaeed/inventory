# 05 — Data Model

## Entity relationships

```
tenants (id)
  ├── 1─* products        (cascadeOnDelete)
  ├── 1─* warehouses      (cascadeOnDelete)
  ├── 1─* stock_movements (cascadeOnDelete)
  └── 1─* stock_levels    (cascadeOnDelete)

products  ──* stock_levels  *── warehouses
products  ──* stock_movements *── warehouses
```

## Tables

### tenants (`2026_11_02_105728`)

| Column | Type | Constraints |
| ------ | ---- | ----------- |
| `id` | bigint PK | |
| `name` | varchar(191) | |
| `subdomain` | varchar(191) | **unique** |
| timestamps | | |

### products (`2026_11_02_110813`)

| Column | Type | Constraints |
| ------ | ---- | ----------- |
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | cascade on delete |
| `sku` | varchar(191) | unique **per tenant**: `unique(tenant_id, sku)` |
| `name` | varchar(191) | |
| `unit_price` | decimal(12,2) | |
| timestamps | | |

Also has `unique(tenant_id, id)` — a redundant composite (see [notes](#notes)).

### warehouses (`2026_11_02_114939`)

| Column | Type | Constraints |
| ------ | ---- | ----------- |
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | cascade on delete |
| `name` | varchar(191) | `index(tenant_id, name)` |
| `location` | varchar(191) | nullable |
| timestamps | | |

Also has `unique(tenant_id, id)` (redundant, see [notes](#notes)). Warehouse names are **not** unique per tenant — only indexed.

### stock_movements (`2026_11_02_123854`) — the ledger

| Column | Type | Constraints |
| ------ | ---- | ----------- |
| `id` | bigint PK | insertion order = chronological order |
| `tenant_id` | FK → tenants | cascade on delete |
| `product_id` | FK → products | **restrict** on delete |
| `warehouse_id` | FK → warehouses | **restrict** on delete |
| `type` | enum(`in`,`out`,`transfer`) | |
| `quantity` | integer | always positive; direction implied by type |
| `reference` | varchar(191) | nullable (e.g. PO number) |
| `meta` | json | nullable; for transfers: `{"from_warehouse_id":…,"to_warehouse_id":…}` |
| timestamps | | |
| | | `index(tenant_id, product_id, id)` — supports history replay per product |

### stock_levels (`2026_11_02_124036`) — current quantities

| Column | Type | Constraints |
| ------ | ---- | ----------- |
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | cascade on delete |
| `product_id` | FK → products | **restrict** on delete |
| `warehouse_id` | FK → warehouses | **restrict** on delete |
| `quantity` | integer | default 0 |
| timestamps | | |

⚠️ There is **no unique constraint on (tenant_id, product_id, warehouse_id)** — the natural key of this table. Uniqueness is only enforced by `firstOrCreate` application logic, which is race-unsafe (see [08-known-issues.md](08-known-issues.md) #2). This is the single most important schema gap.

### Supporting tables (Laravel defaults)

- `users`, `password_reset_tokens`, `sessions` (`0001_…_create_users_table`)
- `cache`, `cache_locks` (`0001_…_create_cache_table`) — `CACHE_STORE=database`
- `jobs`, `job_batches`, `failed_jobs` (`0001_…_create_jobs_table`) — `QUEUE_CONNECTION=database`
- `personal_access_tokens` (`2026_11_02_143001`) — Sanctum; migrated but no route authenticates

## Delete semantics

| Delete | Effect |
| ------ | ------ |
| Tenant | **Cascades** to products, warehouses, movements, levels — full tenant wipe. |
| Product | **Restricted** while any movement or level references it → the ledger is preserved by the database, not just convention. |
| Warehouse | Same restriction. |

To "remove" a product's history you'd have to delete movements first — by design, that never happens through the API.

## Notes

- **`unique(tenant_id, id)` on products/warehouses** is redundant: `id` is already globally unique. Harmless, likely intended as `unique(tenant_id, sku)`-style tenant-scoped uniqueness on a different column, or an artifact of an iteration.
- **Model casts vs schema**: `StockMovement.quantity` and `StockLevel.quantity` cast to `decimal:3` (hence `"50.000"` strings in API responses) although the columns are plain integers. Display-only; the decimal cast can also silently truncate a value with more precision than the column holds.
- **Transfer encoding**: the `warehouse_id` column holds the source warehouse; `meta` holds both source and target. Any ledger replay (reconcile, reports) must special-case `type=transfer` and read `meta`.
- **Eloquent scoping**: all four domain models register `TenantScope` in `booted()`, so **every** query through Eloquent gets `where tenant_id = <context>` — or `whereRaw('1 = 0')` when no tenant context is set (fail closed). See [06-multitenancy.md](06-multitenancy.md).
- **Migrations** are vanilla anonymous-class Laravel migrations; no data migrations, no soft deletes, no audit columns beyond `created_at`/`updated_at`.
- **Seeder** (`database/seeders/DatabaseSeeder.php`) creates a `test` subdomain tenant, one product (`TEST-SKU-001`), one warehouse, and one `in` movement of 50 — idempotent via `firstOrCreate`. The movement does **not** create a matching stock level (see [08-known-issues.md](08-known-issues.md) #9).
