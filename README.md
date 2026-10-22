# Vidorck Hair Backend

Laravel 12 / PHP 8.4 JSON API for the Vidorck Hair storefront and staff admin.
SQLite stores application data, sessions, queued jobs, and cache entries. The
frontend lives in the separate `VHI_Website` project.

For this repository's Render demo, see [the Docker deployment guide](docs/render-demo.md).
The Dockerfile and application files are directly at the repository root.

To run the demo locally with Docker:

```sh
docker compose up --build
```

The API is available at `http://localhost:10000`; its health check is `/up`.
Compose creates a local application key and preserves it with the SQLite database
and uploaded media in a named volume. No manual environment setup is needed.

## Local setup

Requirements: PHP 8.4 with PDO SQLite, mbstring, XML, ctype, fileinfo, GD, EXIF, OpenSSL,
and Composer 2. No Node.js, Redis, or database server is needed.

```sh
composer setup
php artisan storage:link
php artisan db:seed
php artisan serve
```

`composer setup` installs the locked dependencies, creates `.env`, generates
`APP_KEY`, creates `database/database.sqlite`, and runs migrations. Edit `.env`
for the storefront and admin origins. `storage:link` makes uploaded images
available at `/media/*` during local development. For an existing installation, use
`composer install` and `php artisan migrate` without regenerating the key.

Run the queue worker in a second terminal:

```sh
php artisan queue:work --tries=3
```

Create the first staff Admin interactively after migrations:

```sh
php artisan staff:create-admin
```

The command prompts for name, email, and a hidden password. Passwords need
12–128 characters, upper/lower case, a digit, and a symbol. Production never
seeds staff credentials. Local/testing seeders include development accounts
with passwords overridable in `.env.example`.
`--force` creates an additional Admin; it never overwrites one.

If PHP 8.4 is installed through Homebrew and absent from your PATH:

```sh
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
```

## Checks

```sh
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
# Or run all three:
composer check
php artisan route:list
```

Most tests use isolated in-memory SQLite connections. Temporary SQLite files
test WAL/pragmas and real concurrent PHP processes for payment finalization,
poll cooldowns, and retries. OpenAPI path and method coverage is checked against
Laravel's registered routes by the suite.
GitHub Actions runs the same style, analysis, and test checks on PHP 8.4.

## API foundation

- Public module routes load under `/api/v1`; admin module routes under
  `/api/v1/admin`. Every module's admin routes share authentication, active-account,
  session-expiry, and forced-password-change checks.
- `GET /up` is the health check. `GET /sanctum/csrf-cookie` initializes SPA CSRF
  protection. Call it from `ADMIN_URL` with credentials, then post email/password
  to `/api/v1/admin/auth/login` with the decoded `XSRF-TOKEN` cookie in
  `X-XSRF-TOKEN`. Include credentials on every admin request.
- Errors use `{ message, code, errors }`, with `errors` an object of field arrays.
  `ApiException` carries specific 4xx domain codes; unexpected server errors use
  generic messages.
- Responses include `X-Request-Id`; a valid supplied ID is echoed, otherwise a
  UUID is generated. The ID is included in log context.
- Shared API resources emit camelCase keys. Pagination uses
  `{ data, meta: { page, perPage, total } }` and requires a length-aware paginator.
  `Money` holds integer pesewas and
  serializes as `{ "amountMinor": 14300, "currency": "GHS" }`.
- CORS accepts only `STOREFRONT_URL` and `ADMIN_URL`, supports cookies, and exposes
  `X-Request-Id`. Production responses include HSTS.
- SQLite applies WAL, NORMAL synchronization, a 5000 ms busy timeout, foreign
  keys, and memory temporary storage on each connection. Transactions default
  to IMMEDIATE for read-then-write financial operations.

See [.env.example](.env.example) for local settings and production overrides,
[the OpenAPI contract](docs/openapi.yaml), [the product spec](docs/product-spec.md),
and [the task index](docs/TASKS_INDEX.md).

## Code layout

`app/Modules/{Catalog,Checkout,Orders,Customers,Staff,Notifications}` contains
module-specific controllers, form requests, resources, models, actions, and
public/admin route files. `app/Support` contains shared money and HTTP conventions.
Controllers delegate domain work to module actions.

## Staff access

Staff authentication uses Sanctum cookies with 60-minute idle and 12-hour
absolute expiry. Bearer tokens and remember-me login are not enabled.
`GET /api/v1/admin/auth/me` returns the current staff resource.
`POST /api/v1/admin/auth/logout` invalidates the session.

Admin manages accounts through `/api/v1/admin/staff`. Creating staff or posting
to `staff/{staff}/reset-password` returns a `temporaryPassword` once, alongside
the staff `data` resource. Share it securely with that staff member. A reset
account can only call `auth/me`, `auth/logout`, and `auth/password` until it changes
the password. `auth/password` takes `currentPassword`, `newPassword`, and
`newPasswordConfirmation`.

Role changes, active-state changes, resets, and password changes revoke other
sessions immediately. An Admin cannot disable/demote/reset their own account;
own password changes use `auth/password`. At least one active Admin must remain.
Sales/Orders staff cannot manage staff or read audit logs.

`GET /api/v1/admin/audit-logs` supports actor/action/target/date filters and
pagination. Audit records are redacted and immutable at both the model and
SQLite layers. See the OpenAPI contract for request fields and response codes.

Production requires `SESSION_DOMAIN=.vidorckhair.com`,
`SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=admin.vidorckhair.com`,
and the exact HTTPS admin/storefront origins. Local defaults are documented
in `.env.example`.

## Catalogue and media

`GET /api/v1/products` accepts `q`, `category`, `hair_type`, and `page`.
Pages contain 15 products; `meta.facets` counts categories and hair types
across all visible products. Search ignores case and accents. Products require
publication, an active category, and at least one active variant. Published
bundles require nonempty items whose products and selected variants remain
available. Public responses omit internal IDs and source prices.

Product and bundle details use slugs. Previous slugs return `301` with
`Location` and `{ "redirectTo": "current-slug" }` while the target is visible.
Historical slugs remain reserved. Category and hair type endpoints provide
structural data; seeders create no products or bundles.

Both staff roles can read admin products, bundles, and categories. Admin can
edit entries, variants, prices, publication, and images. Variant prices use
integer pesewas: `sourcePriceMinor` alone derives `priceMinor` by adding 200;
an explicit `priceMinor` takes precedence. Bundle prices remain the saved Admin
value; `suggestedPriceMinor` sums item prices and quantities, then subtracts 300.
Bundle item updates replace the full list. See OpenAPI for all request fields.

Upload multipart `image` and optional `alt` to
`/api/v1/admin/products/{product}/image` or `/api/v1/admin/bundles/{bundle}/image`.
JPEG, PNG, and WebP uploads are limited to 8 MB and 6000 pixels on either edge.
The pipeline strips metadata and creates 400/800/1200 WebP renditions without
upscaling smaller sources. `image.urls` maps widths to immutable
`/media/{ulid}/...` URLs. Replaced media is marked retired and retained;
automated pruning after 30 days belongs to the Phase 6 scheduler.

For the documented image limits, configure PHP `memory_limit=512M`,
`upload_max_filesize=8M`, and `post_max_size=10M`, and Nginx
`client_max_body_size 10m`. Set these limits in the PHP configuration used by
the HTTP server; `php --ini` identifies the CLI configuration for local serving.
Processing rejects images with a validation error when the available PHP
memory budget is insufficient. Rendition keys identify size presets; a small
source's files retain its original width.

Catalogue mutations and audit records commit together. One queued storefront
refresh follows each changed action. Configure `STOREFRONT_REVALIDATE_URL` and
`REVALIDATE_SECRET`; the job posts `{ "tags": [...] }` with `x-revalidate-secret`,
retries failed calls, and logs terminal failure. An unset URL skips refreshes
during local development. The storefront endpoint and fetch migration remain
in the separate `VHI_Website` project.

## Checkout and payments

Set `PAYSTACK_SECRET_KEY` to the environment-specific Paystack key and
`PAYSTACK_CALLBACK_URL` to the storefront `/checkout/return` URL. Configure
Paystack's webhook URL as `/api/v1/webhooks/paystack`. Keep the database queue
worker running: signed receipts and their jobs are persisted together, and the
webhook acknowledges before contacting Paystack. Monetary POSTs are never
automatically repeated; verification GETs have bounded retries.

`POST /api/v1/checkout` validates customer/delivery details and reprices every
product, bundle, and extra from the catalogue. Client money fields are ignored;
optional `expectedUnitPriceMinor` detects stale prices. All amounts are integer
pesewas in GHS, and delivery fees are arranged separately. The response contains
`authorizationUrl` and an opaque `reference`; attempts expire after 60 minutes.

`GET /api/v1/checkout/{reference}` returns `PENDING`, `FAILED`, or `SUCCEEDED`
with `orderNumber` and `trackingToken`. Pending attempts are verified after the
first ten seconds, at most once every ten seconds per reference. Polling and
webhook processing share the same idempotent order-creation action. A late
verified payment uses the original item and delivery snapshots. Status and
retry responses use `Cache-Control: no-store, private`.

`POST /api/v1/checkout/{reference}/retry` verifies the previous attempt and
revalidates its saved cart before creating a new reference. Each attempt can
have one child; retry the latest failed or expired attempt. Changed prices or
unavailable items return 422. Already paid, still active, already retried, or
mismatched attempts return 409; provider uncertainty returns 503.

`GET /api/v1/delivery-areas` lists active normal-delivery areas. Both staff
roles can read `/api/v1/admin/delivery-areas`; Admin can create or edit them.
These changes are audited and queue a storefront refresh. Admin can read
`/api/v1/admin/payments/reconciliation` for mismatches and verified payments
without orders; this endpoint cannot mark a payment as successful.

Order history and staff audit records are append-only at both application and
SQLite layers. The order content revision migration cannot be rolled back, and
production blocks destructive database commands (`db:wipe` and migration reset,
refresh, fresh, or rollback). Received webhook facts are immutable, with one terminal
processing timestamp. Payment/card authorization secrets are never retained.
OrderPaid dispatches after commit; notifications attach in Phase 5 (see below). Scheduled
recovery belongs to Phase 6. Tests fake Paystack and include separate PHP processes sharing a
temporary SQLite file to exercise the webhook/poll race.

## Orders, refunds and customers

Customers track orders with `POST /api/v1/orders/track` (order number plus phone)
and request cancellation with `POST /api/v1/orders/cancellation-requests`, allowed
only before dispatch. Unknown orders and wrong phones return the same 404; both
endpoints are rate limited. `GET /api/v1/orders/confirmation/{trackingToken}` returns the
compact post-payment payload.

Staff manage orders under `/api/v1/admin/orders`: list, detail, status transitions,
notes, and Admin-only delivery edits and corrections. Only `OrderStateMachine`
changes order status. Both roles can create manual paid orders
(`POST /api/v1/admin/orders/manual`). Refund requests are listed under
`/api/v1/admin/refunds`; only Admin decides, retries, or manually completes them.
Approved Paystack refunds are confirmed by the `refund.processed` webhook before an
order becomes `REFUNDED`; the refund call is never retried automatically, and an
approved or processing refund blocks dispatch. `/api/v1/admin/customers` and
`/api/v1/admin/dashboard` serve the CRM and the Africa/Accra daily summary.
Open questions are in `docs/implementation/phase_4_review.md`.

## Notifications

Customer emails (Resend) and customer SMS (Arkesel or SMSOnlineGH) are sent only from queued jobs, so a
provider outage never blocks an order or status change. Planners queue one job per
recipient inside the business transaction; an append-only `notification_logs` row with
a unique key prevents duplicates, and `notification_attempts` records each try.
Local defaults are `MAIL_MAILER=log` and `SMS_DRIVER=log`; outside production,
`MAIL_ALLOWED_RECIPIENTS` and `SMS_ALLOWED_RECIPIENTS` restrict delivery. Run a worker
with `php artisan queue:work`.

`POST /api/v1/complaints` stores support messages in the complaint queue and returns a
reference. It is limited to 3 per 10 minutes per IP and keeps the hidden `website`
honeypot. Customer acknowledgements use the selected contact method.
Open items are in `docs/implementation/phase_5_review.md`.
