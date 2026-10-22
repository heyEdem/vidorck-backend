# Architecture

Laravel 12 / PHP 8.4 modular monolith, backed by one SQLite database. Public API
prefix: `/api/v1`; staff API prefix: `/api/v1/admin`. Next.js is maintained in a
separate repository. No Node build, Redis, or external database is required.

| Directory | Purpose |
|---|---|
| `app/Modules/` | Catalog, Checkout, Orders, Customers, Staff, Notifications |
| `app/Support/` | Shared money and HTTP conventions |
| `bootstrap/app.php` | Routing, global middleware, exception rendering |
| `config/` | Framework, CORS, and service configuration |
| `database/migrations/` | Staff/audit, catalogue/media, checkout/order, refund/note, notification, and framework tables |
| `routes/api.php` | Discovers each module's public and admin route files |
| `tests/` | Pest feature and unit tests, including OpenAPI route coverage |
| `docs/openapi.yaml` | Current HTTP contract |
| `.github/workflows/ci.yml` | Dependency install, style, analysis, tests |
| `Dockerfile`, `bin/render-start` | Render demo image and startup; SQLite and storage under `/var/data` |

The demo repository contains the application directly at its root. Render runs
the existing web, queue, and scheduler processes together so they can share the
SQLite disk. See `docs/render-demo.md` for deployment settings.

Requests pass through request-ID and security-header middleware before CORS,
then through Laravel routing and module controllers. The exception renderer
returns the shared API error envelope. Sanctum supplies cookie-based staff
authentication and CSRF. The web provider uses `StaffUser`; all module admin
routes share active-account, session-version, absolute-expiry, and forced-change
middleware. `AuthServiceProvider` registers the capability gates and staff policy.

Staff mutations and their redacted audit events commit in one SQLite IMMEDIATE
transaction. Session deletion plus a monotonically increasing staff session
version invalidates stale or concurrently restored sessions. Audit model events
and SQLite triggers enforce append-only storage; future audit-table migrations
must preserve those triggers.

SQLite holds application data, sessions, database jobs, cache, and locks. Every
new connection receives the required pragmas, including reconnects. Default
transactions use IMMEDIATE semantics. Catalogue visibility uses SQL scopes;
public resources explicitly allowlist fields. Category seeders preserve Admin
edits, and SQLite triggers enforce nonnegative catalogue money. Media uses
ULID paths on local disk, GD metadata removal, and WebP renditions.
Replacements retain old files for Phase 6 pruning; a partial index permits one
active image per owner. Image decoding and file writes precede short audited
transactions. Storefront tag refresh jobs dispatch after commit.
Checkout validates and snapshots the server-priced cart before Paystack
initialization. Signed webhook receipts and database jobs commit atomically;
verification HTTP runs outside write transactions. Polling claims a durable
verification timestamp. Both paths use the same IMMEDIATE order transaction,
with database uniqueness guarding payment references and attempt identity.
Customer summaries, yearly numbers, item snapshots, paid history, and attempt
success commit together; OrderPaid dispatches after the outer commit.
Order status history is append-only. Webhook facts cannot change; processing
completion can be marked once.
`OrderStateMachine` alone writes `orders.status` after creation; each change
commits with its status event and audit row, then fires `OrderStatusChanged`.
Refund requests are the mutable workflow row; refund transactions and all notes
are append-only. Orders reach REFUNDED only through provider or manual
confirmation, and APPROVED/PROCESSING refunds block dispatch. Paystack refund
POSTs run outside write transactions and are never retried automatically; refund
webhooks reuse the signed payment-event job.
Notifications: planners call `NotificationDispatcher::once()` inside business
transactions; it inserts an append-only `notification_logs` row and an encrypted
database-queue job together (same SQLite connection, so both commit or roll back
with the caller). `SendNotification` claims work with an append-only
`notification_attempts` row and calls Arkesel or Resend only from the queue.
Backups and live provider setup remain Phase 7.

Image dependency: Intervention Image 3 with the GD driver.
Development dependencies: Pest 3, Larastan 3, Pint, and Symfony YAML for the
OpenAPI route coverage test. Resolved dependencies are in `composer.lock`.
