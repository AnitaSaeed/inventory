# 06 — Multitenancy

The service is single-database, shared-schema multi-tenant: every tenant's data lives in the same tables, discriminated by `tenant_id`.

## The moving parts

| Piece | File | Role |
| ----- | ---- | ---- |
| `TenantContext` | `app/Support/TenantContext.php` | Per-request holder of the current `Tenant`. Registered as a **scoped singleton** in `AppServiceProvider::register()` — one instance per request/job lifecycle, not truly global. |
| `ResolveTenant` | `app/Http/Middleware/ResolveTenant.php` | Extracts the tenant identifier, loads the `Tenant`, sets the context, clears it in `finally`. |
| `SetTenant` (alias `tenant`) | `app/Http/Middleware/SetTenant.php` | Stricter variant: numeric `X-Tenant-ID` only; 400 on invalid header. Used by the debug route only. |
| `GlobalTenantScope` | `app/Http/Middleware/GlobalTenantScope.php` | Route middleware that registers closure-based global scopes on the four models for the duration of the request. |
| `TenantScope` | `app/Scopes/TenantScope.php` | Model-level global scope registered in each model's `booted()` — the actual query rewriter. |
| `tenant_id` columns + FKs | migrations | Physical discriminator and cascade rules. |

## Resolution rules (`ResolveTenant`)

```
X-Tenant-Id header        → used as tenant id (cast to int)
else X-Tenant-Subdomain   → also cast to int and used as tenant ID (NOT a subdomain lookup!)
else host's first label   → acme.example.com → "acme" → cast to int → tenant ID
none                      → 401 "Tenant identifier missing"
not found                 → 404
```

Tenant context set → request handled → context cleared (in `finally`, so even exceptions can't leak it to the next request via a long-lived container... although as a scoped binding it's rebuilt per request anyway — the `finally` matters for queue workers/octane-style runtimes).

## Query scoping — two layers

### Layer 1: model global scope (always on)

Every domain model (`Product`, `Warehouse`, `StockMovement`, `StockLevel`) registers `TenantScope` in `booted()`:

```php
public function apply(Builder $builder, Model $model): void
{
    $tenantId = app(TenantContext::class)->getTenantId();

    if ($tenantId === null) {
        $builder->whereRaw('1 = 0');   // fail closed
        return;
    }

    $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
}
```

- **No tenant context → empty result set** (`1 = 0`), never cross-tenant data. This is why the reconcile command (which sets no context) must use explicit `where('tenant_id', …)` query-builder calls — but note that Eloquent relationship queries like `with(['stockLevels'])` still go through the model scopes.
- The scope applies to **reads**; inserts set `tenant_id` explicitly in `create([...])` arrays, so writes are safe by construction.

### Layer 2: request-scoped closure scopes (why `GlobalTenantScope` exists)

`GlobalTenantScope` middleware adds *another* `tenant` global scope on the same models, with one difference: its closure calls `TenantContext::current()` which **throws `RuntimeException`** if no tenant is set, instead of degrading to `1 = 0`.

The practical effect: within an API request, a query executed somehow before tenant resolution (or in a context-free path) fails loudly rather than silently returning nothing. It's defense-in-depth with a different failure mode.

### What scoping does *not* cover

- **The `Tenant` model itself** — unscoped by design (you must look tenants up before you have one).
- **`User`** — unscoped; no tenant column.
- Raw `DB::table(...)` or query-builder queries that bypass Eloquent models — none exist in the app, but this is the class of bypass to watch for in future code.

## Isolation guarantees

| Threat | Covered? | Mechanism |
| ------ | -------- | --------- |
| Read another tenant's rows via API list endpoints | Yes | Global scopes on all read paths + explicit `where('tenant_id')` in controllers |
| Write a movement referencing another tenant's product/warehouse | Yes | `validateOwnership` in `in`/`out` handlers; explicit `tenant_id` on insert |
| Transfer with `meta.from_warehouse_id` of another tenant's warehouse | **No** | `TransferHandler` locks rows filtered by the tenant's `stock_levels` (so foreign rows can't be *found*), but the ids in `meta` are never validated against the tenant's warehouses; `warehouse_id` itself is also not validated (known-issue #7) |
| Guessing tenant ids in `X-Tenant-Id` | **No auth at all** | Any caller can pass any tenant id — there's no user authentication binding requests to tenants ([known-issue #10](08-known-issues.md)) |
| Cross-tenant inference via global sequences/ids | No | Movement ids are global auto-increment; a tenant can infer other tenants' write volume from id gaps. Usually acceptable; noted for completeness. |

## Design assessment

- **Shared schema + row scoping** is the right cheap default here; per-tenant databases would buy stronger isolation but huge operational cost for this scale.
- The **scoped singleton `TenantContext`** is idiomatic Laravel; the `finally` clearing makes it safe for long-running workers.
- The **dual-scope setup** (model scope that fails closed + request middleware scope that fails loud) is redundant but harmless — worth consolidating in a cleanup pass.
- The obvious next step for production: wire **Sanctum authentication** and resolve the tenant from the authenticated user's membership, rather than trusting a caller-supplied header. The commented-out block in `ResolveTenant` (`if (!$request->user()) abort(401)`) shows this was the intent.
