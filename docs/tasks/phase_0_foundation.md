# Phase 0 — Laravel Foundation & API Conventions

**Goal:** Stand up a Laravel 12 / PHP 8.4 project on SQLite with the module layout, API conventions, and CI that every later phase builds on.
**Estimate:** ~2-3 days · **Depends on:** Nothing.
**Source:** [§3 Architecture](../product-spec.md#3-architecture) · [§4 Technology Baseline](../product-spec.md#4-technology-baseline) · [§7 API Contract](../product-spec.md#7-api-contract-conventions)

## Initial Code Audit (2026-10-08)

- [x] Before implementation, the repository contained only `README.md` and `docs/`. No Laravel application, `composer.json`, migrations, or tests exist yet.

## Tasks

### 0.1 Scaffold Laravel project
- Create a Laravel 12 app in the repo root targeting PHP 8.4; run `php artisan install:api` for Sanctum and `routes/api.php`.
- Add dev dependencies: Pest, Larastan, Pint.
- Files: `composer.json`, `composer.lock`, `bootstrap/app.php`, `routes/api.php`
- Done when: `php artisan test` runs the default suite green and `composer.lock` is committed.

### 0.2 Configure SQLite
- Point `DB_CONNECTION=sqlite` at `database/database.sqlite`.
- Apply `journal_mode=WAL`, `synchronous=NORMAL`, `busy_timeout=5000`, `foreign_keys=ON`, `temp_store=MEMORY` on every connection.
- Set `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`; publish their migrations.
- Files: `config/database.php`, `.env.example`, `database/migrations/`
- Done when: a test asserts `PRAGMA journal_mode` returns `wal` and `PRAGMA foreign_keys` returns `1`.

### 0.3 Create module layout
- Add `app/Modules/{Catalog,Checkout,Orders,Customers,Staff,Notifications}` and `app/Support`.
- Each module gets `Http/Controllers`, `Http/Requests`, `Http/Resources`, `Models`, `Actions`, and a route file loaded from `routes/api.php`.
- Files: `app/Modules/**`, `routes/api.php`
- Done when: module route files load under `/api/v1` and `/api/v1/admin` prefixes.

### 0.4 Add API conventions
- Error envelope `{ message, code, errors }` via exception handler for 401/403/404/409/422/429.
- `RequestId` middleware generating/echoing `X-Request-Id` and adding it to log context.
- Base `JsonResource` emitting camelCase keys; `Money` value object (integer pesewas, `GHS`).
- Files: `bootstrap/app.php`, `app/Support/Http/`, `app/Support/Money.php`
- Done when: every API error matches the envelope and every response carries `X-Request-Id`.

### 0.5 Configure CORS, headers, and env
- CORS `allowed_origins` = storefront + admin origins from env, `supports_credentials=true`, `paths` = `api/*`, `sanctum/csrf-cookie`.
- Security headers middleware (`nosniff`, `Referrer-Policy`, HSTS in production).
- Document every env key from the spec in `.env.example`.
- Files: `config/cors.php`, `app/Support/Http/SecurityHeaders.php`, `.env.example`
- Done when: a disallowed origin receives no CORS headers.

### 0.6 Add CI and OpenAPI scaffold
- GitHub Actions workflow: `composer install`, Pint `--test`, Larastan, Pest on SQLite.
- Create `docs/openapi.yaml` (or wire Scramble) with servers, error schema, and money schema.
- Files: `.github/workflows/ci.yml`, `phpstan.neon`, `pint.json`, `docs/openapi.yaml`
- Done when: CI is green on a pull request.

## Acceptance Tests

- [x] **sqlite_pragmas_are_applied:** Given a fresh connection, when pragmas are queried, then WAL, foreign keys, and busy timeout match the spec.
- [x] **errors_use_standard_envelope:** Given an unknown API route, when it is requested, then the response is 404 with `message` and `code`.
- [x] **request_id_is_echoed:** Given a request with `X-Request-Id: abc`, when it completes, then the response header is `abc`; without one, a new ID is returned.
- [x] **cors_rejects_unknown_origin:** Given an origin not in the allowlist, when it preflights, then no `Access-Control-Allow-Origin` is returned.

## Verification

- `php artisan test`
- `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`

## Implementation Result — 2026-10-08

All six foundation tasks are implemented. Local verification: **50 Pest tests,
200 assertions**, Pint, Larastan level 6, Composer manifest validation, and
configuration/route caching passed. Opus 5.5 provided a source review; its
findings and their disposition are recorded in
[phase_0_review.md](../implementation/phase_0_review.md).

`composer.lock` is included in the Phase 0 delivery. The GitHub Actions workflow
repeats the checks on pushes and pull requests. Local verification is complete;
remote workflow results are tracked in GitHub Actions. Client-IP handling behind Cloudflare/Nginx is
explicitly tracked in task 7.2.
