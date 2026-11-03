# 02 — Architecture

## Code layout

```
inventory/
├── app/
│   ├── Console/
│   │   ├── Kernel.php                        # schedules commands; loads app/Console/Commands
│   │   └── Commands/
│   │       └── InventoryReconcileCommand.php # `inventory:reconcile {tenant} [--fix]`
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php               # base controller (empty)
│   │   │   ├── StockLevelController.php     # GET /api/stock-levels
│   │   │   ├── StockMovementController.php  # POST /api/stock-movements
│   │   │   └── ProductHistoryController.php # GET /api/products/{sku}/history
│   │   ├── Middleware/
│   │   │   ├── Authenticate.php             # Laravel stub (redirects to login route)
│   │   │   ├── ResolveTenant.php            # resolves tenant from header/subdomain, sets context
│   │   │   ├── GlobalTenantScope.php        # registers tenant global scopes for the request
│   │   │   └── SetTenant.php                # simpler variant: X-Tenant-ID header only (alias 'tenant')
│   │   └── Requests/
│   │       └── StockMovementRequest.php     # validation for POST /api/stock-movements
│   ├── Models/
│   │   ├── Tenant.php                       # name, subdomain (unique)
│   │   ├── Product.php                      # sku, name, unit_price (TenantScope)
│   │   ├── Warehouse.php                    # name, location (TenantScope)
│   │   ├── StockMovement.php                # ledger row (TenantScope)
│   │   ├── StockLevel.php                   # current quantity (TenantScope)
│   │   └── User.php                         # Laravel default auth user
│   ├── Providers/AppServiceProvider.php     # registers TenantContext as a *scoped* singleton
│   ├── Scopes/TenantScope.php               # Eloquent global scope: where tenant_id = context
│   ├── Services/Stock/
│   │   ├── MovementHandler.php              # handler interface
│   │   ├── MovementHandlerFactory.php       # type → handler mapping
│   │   ├── RecordStockMovementService.php   # entry point used by the controller
│   │   └── Handlers/
│   │       ├── InHandler.php                # receive stock
│   │       ├── OutHandler.php               # consume stock
│   │       └── TransferHandler.php          # move stock between warehouses
│   └── Support/TenantContext.php            # per-request tenant holder
├── bootstrap/app.php                        # Laravel 11+ slim bootstrapping (routes, middleware, commands)
├── database/
│   ├── migrations/                          # users, cache, jobs, tenants, products, warehouses,
│   │                                        # stock_movements, stock_levels, personal_access_tokens
│   └── seeders/DatabaseSeeder.php           # one tenant, product, warehouse, one 'in' movement of 50
├── routes/
│   ├── api.php                              # the three API endpoints + a test route
│   ├── web.php                              # welcome view
│   └── console.php                          # `inspire` demo command
└── tests/                                   # default Example tests only — no domain tests yet
```

## Request lifecycle

For an API request, e.g. `POST /api/stock-movements`:

1. **Bootstrap** (`bootstrap/app.php`): the app is configured with routes (`routes/api.php` automatically prefixed `/api` and wrapped in the `api` middleware group), middleware aliases (`'tenant'` → `SetTenant`), and commands from `app/Console/Commands`.
2. **Route matched**, global + group middleware run.
3. **`ResolveTenant`** (route middleware): reads `X-Tenant-Id` (or `X-Tenant-Subdomain`, or the subdomain of the host), aborts with 401 if unresolvable, looks up the `Tenant` (404 if the id doesn't exist), and stores it in `TenantContext` — a **scoped singleton**, so it is shared across everything resolved during this one request and reset afterwards.
4. **`GlobalTenantScope`** (route middleware): registers a `tenant` global scope on `Product`, `StockMovement`, `StockLevel`, and `Warehouse`, each of which adds `where tenant_id = <current>` to every query on those models for the rest of the request.
5. **FormRequest validation** (`StockMovementRequest`): rejects invalid payloads with Laravel's standard 422 validation response before the controller runs.
6. **Controller** (`StockMovementController::__invoke`): calls `RecordStockMovementService::execute()` with the validated data and the current tenant id; catches `InvalidArgumentException` → 422.
7. **Service** (`RecordStockMovementService`): picks a handler via `MovementHandlerFactory::make($type)` and delegates.
8. **Handler** (`InHandler` / `OutHandler` / `TransferHandler`): validates tenant ownership of product/warehouse, locks the affected `stock_levels` rows, checks sufficiency, updates quantities, and inserts the `stock_movements` row — all inside `DB::transaction()`.
9. **Response**: `201` with the movement's id, type, quantity, and created_at (fetched as the latest ledger row for the tenant — see known-issues #4); or `422` with an error message.

## Design patterns used

| Pattern | Where | Why |
| ------- | ----- | --- |
| **Strategy** | `MovementHandler` interface + `InHandler`/`OutHandler`/`TransferHandler` | Each movement type encapsulates its own locking, validation, and ledger semantics. Adding a new type (e.g. `adjust`) means adding a handler + factory case, nothing else. |
| **Factory** | `MovementHandlerFactory::make()` | Central, dependency-free mapping from movement type to strategy; throws `InvalidArgumentException` for unknown types. |
| **Service Layer** | `RecordStockMovementService` | Controllers stay thin; business orchestration (choose handler, return the created movement) is reusable by commands/jobs, not just HTTP. |
| **Ambient Context** | `TenantContext` (a scoped singleton, with `current()` that throws if unset) | Tenant id flows to models/scopes/handlers without threading it through every signature. Middleware sets it in a `try/finally` so it is always cleared. |
| **Global Scope (Eloquent)** | `app/Scopes/TenantScope.php` applied in each model's `booted()` | Safety net: even code that forgets an explicit `where('tenant_id', …)` cannot read another tenant's rows. When no tenant is set, the scope deliberately returns `whereRaw('1 = 0')` (empty result) instead of throwing. |
| **Repository-ish via FormRequest** | `StockMovementRequest` | Validation and (default-allow) authorization isolated from the controller. |
| **Ledger + Materialized View** | `stock_movements` (append-only) + `stock_levels` (aggregate) | Event-sourcing-lite: history is never lost; current state is cheap to read; `inventory:reconcile` is the audit/repair tool. |
| **Optimistic-out, Pessimistic-in** | `lockForUpdate()` on `stock_levels` in `out`/`transfer` handlers | Correctness under concurrency: the sufficiency check and the decrement are atomic against other transactions. |

### Two middleware, one job

`ResolveTenant` (used on the API routes) and `SetTenant` (registered as the `'tenant'` alias, used by the `/test-tenant` debug route) both populate `TenantContext`. They differ:

| | `ResolveTenant` | `SetTenant` |
|---|---|---|
| Resolution order | `X-Tenant-Id` header → `X-Tenant-Subdomain` header → host subdomain | `X-Tenant-ID` header only |
| Missing/invalid tenant | 401 (or 404 from `findOrFail`) | 400 for non-numeric, 404 for unknown |
| Notes | The `X-Tenant-Subdomain` value is treated as a tenant **id** and cast to int — it does not look up by subdomain string (known-issue #6) | Stricter header validation |

## Key classes

- **`TenantContext`** (`app/Support/TenantContext.php`) — holds the current `Tenant`. `current()` throws `RuntimeException` if called before middleware set it; `getTenantId()` returns null instead. Registered as `scoped` in `AppServiceProvider::register()`, meaning one instance per request/job lifecycle.
- **`TenantScope`** (`app/Scopes/TenantScope.php`) — in `apply()`, uses the context; null context → `whereRaw('1 = 0')` (fail closed: you get an empty result set rather than cross-tenant data).
- **`InventoryReconcileCommand`** — see [03-flow-guides.md](03-flow-guides.md#reconciliation-flow) for the full flow.

## Design decisions worth noting

- **Quantities are integers.** Migrations use `integer`; validation requires `integer|min:1`. (Models cast to `decimal:3` for display, and the reconcile command casts to `(int)` — harmless, but the model cast is vestigial.)
- **Ownership validation before locking.** `in`/`out` verify the product and warehouse belong to the tenant *outside* the transaction (fail fast); note `InHandler::validateOwnership()` has an operator-precedence bug (known-issue #3).
- **Deterministic lock ordering** (`orderBy('warehouse_id')` … `lockForUpdate()`) prevents AB-BA deadlocks between concurrent transfers of the same product between the same two warehouses.
- **Movement retrieval after insert**: `RecordStockMovementService` doesn't return the movement the handler created; it re-queries the latest row for the tenant (`latest('id')->first()`), which is race-prone under concurrency (known-issue #4).
