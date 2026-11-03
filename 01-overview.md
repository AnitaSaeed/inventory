# 01 — Overview

## What the service does

The Inventory service manages **stock quantities for products across warehouses, per tenant**. It is an HTTP JSON API with no frontend of its own.

Its defining design choice: stock is tracked two ways at once.

1. **Movement ledger** (`stock_movements`): every change — receiving stock, consuming stock, transferring between warehouses — is appended as a permanent row. The ledger is the source of truth for *what happened and when*.
2. **Current levels** (`stock_levels`): one row per (tenant, product, warehouse) holding the current quantity. This is a read-optimized aggregate of the ledger.

A dedicated command (`php artisan inventory:reconcile`) replays the ledger and compares it against the stored levels, so drift between the two can be detected and repaired.

## Core concepts

| Concept | Meaning |
| ------- | ------- |
| **Tenant** | An isolated customer/company. All domain rows carry `tenant_id`. Identified by ID or subdomain. |
| **Product** | A SKU within a tenant. SKU is unique per tenant, not globally. |
| **Warehouse** | A physical location within a tenant. |
| **Stock level** | The current quantity of a product at a warehouse. |
| **Stock movement** | One ledger entry: `in` (receive), `out` (consume), or `transfer` (move between warehouses). |
| **Reference** | Optional free-form label on a movement (e.g. a PO number). |
| **Meta** | Optional JSON on a movement; for transfers it carries `from_warehouse_id` / `to_warehouse_id`. |

### Movement semantics

- `in` — increases the quantity at `(product, warehouse)` by `quantity`.
- `out` — decreases the quantity at `(product, warehouse)` by `quantity`. Fails if insufficient stock.
- `transfer` — moves `quantity` from `meta.from_warehouse_id` to `meta.to_warehouse_id` for the same product. Fails if the source has insufficient stock, if source equals target, or if either warehouse lacks a stock level row.

Quantities are positive integers (`quantity` ≥ 1); direction is implied by `type`.

## Domain relationships

```
Tenant 1─┬─* Product ──* StockLevel *── Warehouse
         │       └──* StockMovement *──┘
         ├─* Warehouse
         └─* StockMovement
```

- A `StockLevel` belongs to exactly one (tenant, product, warehouse) triple.
- A `StockMovement` always records the tenant, product, and a warehouse (for transfers, the source warehouse; the target lives in `meta`).
- Deleting a tenant cascades to everything it owns. Deleting a product or warehouse is **restricted** while movements reference it — the ledger must be preserved.
- `User` is a Laravel default model (with Sanctum personal access tokens migrated); it is not tenant-scoped and not used by the current API.

## Guarantees (as designed)

- **Isolation**: tenant scoping applied via global Eloquent scopes; requests without a tenant are rejected. Precise guarantees and gaps: [06-multitenancy.md](06-multitenancy.md).
- **No oversell**: `out` and `transfer` check sufficiency while holding a row lock, inside a transaction.
- **Deadlock avoidance**: rows are locked in deterministic order (for transfers, both rows are locked together ordered by `warehouse_id`).
- **Ledger immutability**: movements are only ever inserted, never updated or deleted.

## Out of scope

The service deliberately does not provide: authentication on API routes (Sanctum is installed and its token table is migrated, but no route requires auth), product/warehouse CRUD endpoints (they exist only as models — rows are seeded or created directly), reservations/holds, or fractional quantities.
