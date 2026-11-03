# 03 — Flow Guides

Step-by-step walkthroughs of every flow in the service. File references use paths relative to `inventory/`.

## Contents

- [Stock-in flow](#stock-in-flow-receiving-goods)
- [Stock-out flow](#stock-out-flow-consuming-goods)
- [Transfer flow](#transfer-flow-moving-stock-between-warehouses)
- [Querying stock levels flow](#querying-stock-levels-flow)
- [Product history flow](#product-history-flow)
- [Reconciliation flow](#reconciliation-flow)
- [Seeding flow](#seeding-flow)
- [Failure paths summary](#failure-paths-summary)

---

## Stock-in flow (receiving goods)

**Trigger**: `POST /api/stock-movements` with `type: "in"`.

**Files**: `routes/api.php` → `app/Http/Middleware/ResolveTenant.php` → `app/Http/Middleware/GlobalTenantScope.php` → `app/Http/Requests/StockMovementRequest.php` → `app/Http/Controllers/StockMovementController.php` → `app/Services/Stock/RecordStockMovementService.php` → `app/Services/Stock/MovementHandlerFactory.php` → `app/Services/Stock/Handlers/InHandler.php`

### Steps

1. **Tenant resolution** (`ResolveTenant::handle`):
   - `$tenantId = $request->header('X-Tenant-Id') ?? $request->header('X-Tenant-Subdomain') ?? $this->resolveFromSubdomain($request)`.
   - If null → `abort(401, 'Tenant identifier missing')`.
   - `Tenant::findOrFail((int)$tenantId)` — 404 if the id doesn't exist.
   - `app(TenantContext::class)->setTenant($tenant)`, then `$next($request)` wrapped in `try/finally` so the context is always cleared afterwards.
2. **Global scopes registered** (`GlobalTenantScope::handle`): adds a closure-based `tenant` global scope to `Product`, `StockMovement`, `StockLevel`, `Warehouse`. Each closure reads `TenantContext::current()` at query time and appends `where tenant_id = <id>`.
3. **Validation** (`StockMovementRequest::rules`):
   ```php
   'type'        => ['required', 'in:in,out,transfer'],
   'product_id'  => ['required', 'integer'],
   'warehouse_id'=> ['required', 'integer'],
   'quantity'    => ['required', 'integer', 'min:1'],
   'reference'   => ['nullable', 'string', 'max:255'],
   'meta'        => ['nullable', 'array'],
   ```
   Failure → Laravel's automatic 422 with a `message` and `errors` bag.
4. **Controller** (`StockMovementController::__invoke`): calls
   ```php
   $service->execute(
       tenantId:    app(TenantContext::class)->current(),
       type:        $data['type'],
       productId:   $data['product_id'],
       warehouseId: $data['warehouse_id'],
       quantity:    $data['quantity'],
       reference:   $data['reference'] ?? null,
       meta:        $data['meta'] ?? null,
   );
   ```
   wrapped in `try/catch (InvalidArgumentException)` → `422 { "error": message }`.
5. **Service** (`RecordStockMovementService::execute`): `MovementHandlerFactory::make('in')` → `new InHandler()`.
6. **Handler** (`InHandler::handle`):
   - `validateOwnership(tenantId, productId, warehouseId)` — checks `Product` and `Warehouse` with matching `tenant_id` exist. Because this runs *before* any transaction, it's a fail-fast check only; the scope closures also apply `tenant_id` filtering to these queries (note the precedence bug in this specific method — [known-issue #3](08-known-issues.md)).
   - `DB::transaction(...)`:
     - `StockLevel::firstOrCreate([tenant, product, warehouse], ['quantity' => 0])` — lazily creates the level row with quantity 0 if the product has never been stocked at this warehouse. Note: `firstOrCreate` is *not* race-safe by itself; two concurrent first-time stock-ins can both attempt the insert (known-issue #2).
     - `$level->forceFill(['quantity' => $level->quantity + $quantity])->save()` — increments.
     - `StockMovement::create([... 'type' => 'in', ...])` — appends the ledger entry.
7. **Response**: the service re-fetches `StockMovement::where('tenant_id', …)->latest('id')->first()` (known-issue #4) and the controller returns:
   ```json
   {
     "id": 42,
     "type": "in",
     "quantity": 50,
     "created_at": "2026-11-03T10:00:00.000000Z"
   }
   ```
   with status `201`.

### Invariant maintained

`stock_levels.quantity = SUM(ledger effects)` for the (tenant, product, warehouse) triple — this is exactly what `inventory:reconcile` checks.

---

## Stock-out flow (consuming goods)

**Trigger**: `POST /api/stock-movements` with `type: "out"`.

**Files**: same as stock-in, but `app/Services/Stock/Handlers/OutHandler.php`.

### Steps

Steps 1–5 identical to [stock-in](#stock-in-flow-receiving-goods) (tenant → scopes → validation → controller → factory → `OutHandler`).

6. **Handler** (`OutHandler::handle`):
   - `validateOwnership(...)` — same fail-fast tenant ownership check (correct precedence here).
   - `DB::transaction(...)`:
     ```php
     $level = StockLevel::where('tenant_id', …)
         ->where('product_id', …)
         ->where('warehouse_id', …)
         ->orderBy('warehouse_id')   // deterministic lock ordering
         ->orderBy('product_id')
         ->lockForUpdate()           // SELECT … FOR UPDATE
         ->first();
     ```
     The row lock is held for the whole transaction, so a concurrent `out`/`transfer` on the same level blocks here until this transaction commits.
   - **Sufficiency check**: `if (! $level || $level->quantity < $quantity) throw new InsufficientStockException(...)`.
     ⚠️ `App\Exceptions\InsufficientStockException` does not exist in the codebase — this throw currently fatals with `Class not found` instead of returning a clean error ([known-issue #5](08-known-issues.md)).
   - `$level->forceFill(['quantity' => $level->quantity - $quantity])->save()` — decrements.
   - `StockMovement::create([... 'type' => 'out', ...])` — appends the ledger entry.

### Concurrency walkthrough (why the lock matters)

Two concurrent `out` requests for the same (product, warehouse) with quantity 30 when only 50 is in stock:

| Time | Tx A | Tx B |
| ---- | ---- | ---- |
| t1 | `SELECT … FOR UPDATE` acquires row lock, reads quantity=50 | — |
| t2 | — | `SELECT … FOR UPDATE` **blocks** waiting for A's lock |
| t3 | 50−30=20, saves, inserts movement, **commits** | — |
| t4 | lock released | acquires lock, reads quantity=**20** |
| t5 | — | 20 < 30 → insufficient-stock failure |

Without the lock, B would read the stale 50, both would succeed, and stock would go to −10 (oversell).

---

## Transfer flow (moving stock between warehouses)

**Trigger**: `POST /api/stock-movements` with `type: "transfer"` and `meta: { from_warehouse_id, to_warehouse_id }`.

**Files**: same as stock-in, but `app/Services/Stock/Handlers/TransferHandler.php`.

### Steps

Steps 1–5 identical to [stock-in](#stock-in-flow-receiving-goods).

6. **Handler** (`TransferHandler::handle`):
   - Extract `from_warehouse_id` / `to_warehouse_id` from `$meta`. Missing either → `InvalidArgumentException('Transfer requires from_warehouse_id and to_warehouse_id in meta')` → 422.
   - `from === to` → `InvalidArgumentException('Cannot transfer to the same warehouse')` → 422.
   - **Lock both rows at once, in warehouse-id order**:
     ```php
     $locks = StockLevel::whereIn('warehouse_id', [$from, $to])
         ->where('tenant_id', …)->where('product_id', …)
         ->orderBy('warehouse_id')      // deterministic order = deadlock avoidance
         ->lockForUpdate()
         ->get();
     ```
   - `if ($locks->count() < 2)` → `InvalidArgumentException('Missing stock levels for transfer')` → 422. This is why a target warehouse with no prior stock can never receive a transfer — the level row must already exist (first stock it with an `in` movement). Note: the `whereIn` here does **not** apply the model's `TenantScope` guard if `Warehouse`'s scope is active; the explicit `where('tenant_id', …)` is present, so it is safe, but the `warehouse_id` values from `meta` are not validated to belong to the tenant's warehouses (known-issue #7).
   - `$fromLevel->quantity < $quantity` → `InsufficientStockException` (same missing-class problem as stock-out).
   - `DB::transaction(...)`:
     - `$fromLevel->decrement('quantity', $quantity)`
     - `$toLevel->increment('quantity', $quantity)`
     - `StockMovement::create([... 'warehouse_id' => $fromWarehouseId, 'type' => 'transfer', 'meta' => ['from_warehouse_id' => …, 'to_warehouse_id' => …]])`

### Why ordering by warehouse_id prevents deadlocks

Two concurrent transfers of the same product, A: W1→W2, B: W2→W1:

- With per-row locking acquired one at a time in arbitrary order, A could hold W1 and wait for W2 while B holds W2 and waits for W1 → deadlock.
- Because both queries `orderBy('warehouse_id')`, both transactions always request W1's lock before W2's. One of them wins W1 first; the other blocks on W1 before holding anything the winner needs — no cycle, no deadlock.

### Ledger representation

A transfer produces **one** movement row: `warehouse_id` = source, `meta.from_warehouse_id` = source, `meta.to_warehouse_id` = target. The reconcile command and any ledger consumer must know this convention to replay transfers correctly (source −qty, target +qty).

---

## Querying stock levels flow

**Trigger**: `GET /api/stock-levels?product_id=…&warehouse_id=…` (both filters optional).

**Files**: `routes/api.php` → `ResolveTenant` → `GlobalTenantScope` → `app/Http/Controllers/StockLevelController.php`.

### Steps

1. Tenant resolution + global scopes as usual.
2. Controller `index()`:
   - Reads `TenantContext::current()` (redundant with the global scope, but explicit).
   - Builds `StockLevel::where('tenant_id', …)` then conditionally applies `?product_id=` and `?warehouse_id=` filters if present in the query string.
   - `->paginate(25)` — Laravel paginator: page 1 by default, `?page=N` to navigate.
3. Response: standard Laravel pagination JSON:
   ```json
   {
     "current_page": 1,
     "data": [
       { "id": 1, "tenant_id": 1, "product_id": 1, "warehouse_id": 1, "quantity": "50.000", "created_at": "…", "updated_at": "…" }
     ],
     "first_page_url": "…", "from": 1, "last_page": 1, "per_page": 25, "total": 1, "…": "…"
   }
   ```

Because of the global scope, even a future code path that forgets the explicit `tenant_id` filter still cannot return other tenants' rows.

---

## Product history flow

**Trigger**: `GET /api/products/{sku}/history`.

**Files**: `routes/api.php` → `ResolveTenant` → `GlobalTenantScope` → `app/Http/Controllers/ProductHistoryController.php`.

### Steps

1. Tenant resolution + global scopes as usual.
2. Controller `__invoke(string $sku)`:
   - `StockMovement::where('tenant_id', …)->whereHas('product', fn ($q) => $q->where('sku', $sku))` — resolves SKU → product *within the tenant*, then filters movements by that product.
   - `->with('product')` eager-loads the product onto each movement.
   - `->orderByDesc('id')->paginate(25)` — newest-first by ledger insertion order (`id` is a proxy for chronology; there is no `happened_at` column distinct from `created_at`).
3. Response: paginated JSON, each item:
   ```json
   {
     "id": 2,
     "tenant_id": 1,
     "product_id": 1,
     "warehouse_id": 2,
     "type": "transfer",
     "quantity": 10,
     "reference": null,
     "meta": { "from_warehouse_id": 1, "to_warehouse_id": 2 },
     "created_at": "…",
     "product": { "id": 1, "tenant_id": 1, "sku": "TEST-SKU-001", "name": "Test Product", "unit_price": "10.00", "…" : "…" }
   }
   ```
4. **Unknown SKU** → empty page 1 with `"data": []` (not a 404).

---

## Reconciliation flow

**Trigger**: `php artisan inventory:reconcile {tenant} [--fix]`.

**File**: `app/Console/Commands/InventoryReconcileCommand.php` (auto-registered via `app/Console/Kernel.php` loading `app/Console/Commands`).

### Steps

1. Read the `tenant` argument and the `--fix` option.
2. `Product::where('tenant_id', $tenantId)->with(['stockLevels', 'stockMovements'])->get()` — all products of the tenant with their levels and movements eagerly loaded (note the eager loads are immediately re-queried inside the loop — see [08-known-issues.md](08-known-issues.md) #8).
3. For each product, replay the ledger in **insertion order** (`stockMovements()->where('tenant_id', …)->orderBy('id')`):
   - `in` → `expected[warehouse] += qty`
   - `out` → `expected[warehouse] -= qty`
   - `transfer` → `expected[meta.from] -= qty`, `expected[meta.to] += qty`
   building an in-memory map `warehouse_id → expected quantity`.
4. For every `(product, warehouse)` with an expected value:
   - Look up the stored `StockLevel` (with `lockForUpdate()` when `--fix`, so the fix races safely against live movements).
   - `$actual = $level?->quantity ?? 0`.
   - If `$actual !== $qty` → report `MISMATCH: tenant=… product=… warehouse=… expected=… actual=…` and:
     - with `--fix`: update the row (`forceFill(['quantity' => $qty])->save()`) or create it if missing;
     - without `--fix`: report only.
5. If no mismatches: `All stock levels are in sync.`
6. **Exit code**: `Command::SUCCESS` (0) when in sync, `Command::FAILURE` (1) otherwise — so it can gate a CI/cron alert.

### Known blind spots

- Only (product, warehouse) pairs **present in the ledger** are checked. A `stock_levels` row whose quantity drifted but whose product has no movements is silently skipped (e.g. a level row left at 0 after all movements were... still movements exist, so covered; a level created by a bug with no ledger entries is **not** covered).
- The reconcile runs without `TenantContext` set, so the model `TenantScope` fails-closed to `1 = 0` — which is why every query in the command uses raw `where('tenant_id', …)` on the query builder relationships instead of relying on scoping. The `with(['stockLevels', 'stockMovements'])` eager loads are subject to the fail-closed scope, hence the re-query inside the loop.

---

## Seeding flow

**Trigger**: `php artisan db:seed`.

**File**: `database/seeders/DatabaseSeeder.php` (uses `WithoutModelEvents`).

1. `Tenant::firstOrCreate(['subdomain' => 'test'], ['name' => 'Test Tenant'])`
2. `Product::firstOrCreate(['tenant_id' => …, 'sku' => 'TEST-SKU-001'], ['name' => 'Test Product', 'unit_price' => 10.00])`
3. `Warehouse::firstOrCreate(['tenant_id' => …, 'name' => 'Main Warehouse'], ['location' => 'Main St'])`
4. `StockMovement::firstOrCreate(['tenant_id' => …, 'reference' => 'seed-concurrency-test'], [ … 'type' => 'in', 'quantity' => 50 …])`

All idempotent via `firstOrCreate`. Note the seeded movement is a raw ledger insert — it does **not** create or update a `stock_levels` row, so after seeding, `GET /api/stock-levels` is empty until an `in` movement is recorded through the service (or reconcile `--fix` runs). See [08-known-issues.md](08-known-issues.md) #9.

---

## Failure paths summary

| Condition | Status | Body |
| --------- | ------ | ---- |
| No tenant resolvable | 401 | `Tenant identifier missing` |
| Tenant id not found | 404 | Laravel's not-found page/JSON |
| Validation failure | 422 | `{ message, errors: { field: [ … ] } }` |
| Unknown movement type (bypassing validation, e.g. service called directly) | 422 | `{ error: "Unknown movement type: …" }` |
| Product/warehouse not owned by tenant (`in`/`out`) | 422 | `{ error: "Product does not belong to this tenant" … }` |
| Transfer missing meta warehouses | 422 | `{ error: "Transfer requires from_warehouse_id and to_warehouse_id in meta" }` |
| Transfer same warehouse | 422 | `{ error: "Cannot transfer to the same warehouse" }` |
| Transfer target/source has no stock level row | 422 | `{ error: "Missing stock levels for transfer" }` |
| Insufficient stock (`out`/`transfer`) | **500 (fatal)** | `Class "App\Exceptions\InsufficientStockException" not found` — [known-issue #5](08-known-issues.md) |
