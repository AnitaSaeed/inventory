# Inventory Service — Documentation

A multi-tenant stock management API built with Laravel 12. Every stock change is recorded as an immutable movement (append-only ledger) and current per-warehouse quantities are maintained as materialized stock levels, with a reconciliation command to verify the two stay in sync.

The application code lives in [`../inventory/`](../inventory/).

## Contents

| Document | Description |
| -------- | ----------- |
| [01-overview.md](01-overview.md) | What the service does, domain model, core concepts |
| [02-architecture.md](02-architecture.md) | Code layout, request lifecycle, design patterns |
| [03-flow-guides.md](03-flow-guides.md) | Detailed step-by-step walkthroughs of every flow |
| [04-api-reference.md](04-api-reference.md) | Endpoints, request/response formats, error handling |
| [05-data-model.md](05-data-model.md) | Database schema, constraints, indexes, relationships |
| [06-multitenancy.md](06-multitenancy.md) | Tenant resolution, query scoping, isolation guarantees and gaps |
| [07-operations.md](07-operations.md) | Setup, running, reconciliation, testing, development |
| [08-known-issues.md](08-known-issues.md) | Defects and caveats found in the current implementation |

## Quick start

```bash
cd ../inventory
composer install
cp .env.example .venv && php artisan key:generate   # NOTE: see 08-known-issues #1
php artisan migrate
php artisan db:seed
php artisan serve
```

```bash
curl http://localhost:8000/api/stock-levels -H "X-Tenant-Id: 1"
```

## Tech stack

- PHP ≥ 8.2, Laravel 12 (`laravel/framework`, `laravel/sanctum`, `laravel/tinker`)
- Dev tooling: PHPUnit 11, Laravel Pint, Laravel Sail, Faker, Mockery
- SQLite by default (MySQL/PostgreSQL configurable via `.env`)
- Vite for frontend assets (not used by the JSON API)
