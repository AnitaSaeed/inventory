# 07 — Operations

## Requirements

- PHP ≥ 8.2 with Laravel's required extensions (openssl, pdo, mbstring, etc.)
- Composer
- Node.js + npm (only for Vite asset builds — not needed for API-only use)
- SQLite by default; switch via `.env` (`DB_CONNECTION`, `DB_HOST`, …)

## First-time setup

```bash
cd inventory

composer install
# or the one-shot equivalent:
composer run setup   # install + .env copy + key:generate + migrate + npm install + build

cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # if using sqlite and it doesn't exist
php artisan migrate
php artisan db:seed              # optional demo data
```

## Running

```bash
php artisan serve                # dev server on :8000
composer run dev                 # server + queue worker + log tail + vite, concurrently
```

`composer run dev` starts four processes via `npx concurrently`:

| Name | Process |
| ---- | ------- |
| server | `php artisan serve` |
| queue | `php artisan queue:listen --tries=1 --timeout=0` |
| logs | `php artisan pail` (pretty log tail) |
| vite | `npm run dev` |

## Environment variables that matter

| Variable | Default | Notes |
| -------- | ------- | ----- |
| `APP_URL` | `http://localhost` | Affects pagination URLs in API responses |
| `DB_CONNECTION` | `sqlite` | `:memory:` under phpunit |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | Requires the cache/jobs tables (migrated by default) |
| `APP_DEBUG` | true in `.env.example` | **Set false anywhere exposed** — fatal errors currently leak stack traces (see known-issues #5) |

## Reconciliation

```bash
# Verify only; exit code 0 = in sync, 1 = mismatches found
php artisan inventory:reconcile 1

# Also repair drifted/missing stock level rows (row-locked during the fix)
php artisan inventory:reconcile 1 --fix
```

Suggested cron (exit code makes it alert-friendly):

```
* * * * * php /path/to/inventory/artisan inventory:reconcile 1 || notify-on-drift
```

The command is auto-discovered via `app/Console/Kernel.php` → `load(app/Console/Commands)`. Full flow detail: [03-flow-guides.md](03-flow-guides.md#reconciliation-flow).

## Testing

```bash
composer test        # php artisan config:clear, then php artisan test
```

phpunit.xml configures an in-memory SQLite database, array cache/session/mail, sync queue — no external services needed.

⚠️ **Current state: only the default Laravel `ExampleTest`s exist** (one web smoke test, one unit assertion). None of the domain logic (handlers, scopes, reconcile) has tests. Highest-value tests to write first:

1. `in` → creates level + movement, quantity adds up
2. `out` beyond available → clean 422 (blocked on known-issue #5)
3. concurrent `out`s never oversell (use `DB::transaction` interleaving or process-level test)
4. `transfer` missing meta → 422; transfer moves quantity between warehouses
5. global scope: request without tenant header → 401; wrong tenant id → no data
6. reconcile detects a hand-corrupted level; `--fix` repairs it

## Code style

```bash
vendor/bin/pint            # Laravel Pint (PSR-12 + Laravel preset)
```

## Deployment notes

- Standard Laravel 12 deploy: `composer install --no-dev --optimize-autoloader`, `php artisan config:cache route:cache view:cache`, point the web server at `public/`.
- `post-update-cmd` republishes framework assets automatically.
- Queue worker required if you later move mail/notifications to queues (currently nothing queues).
- **Do not deploy as-is on a public network**: no authentication on API routes, and fatal-error paths leak details with `APP_DEBUG=true` ([08-known-issues.md](08-known-issues.md) #5, #10).

## Debugging aids

- `GET /up` — health check.
- `php artisan pail` — live log tail.
- `GET /api/test-tenant` with `X-Tenant-ID: <id>` — returns all products for the tenant; useful for verifying tenant resolution quickly. Remove before production.
- `storage/logs/laravel.log` — default single-channel log.
