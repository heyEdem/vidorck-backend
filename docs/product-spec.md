# Vidorck Hair Backend — Product Specification

**Version:** 1.0 (v1 launch)  
**Date:** October 8, 2026  
**Author:** Edem (Product Owner)  
**Status:** Draft for development handoff

> A Laravel + SQLite API, self-hosted on a small Contabo VPS, that owns every piece of data and business logic for the Vidorck Hair storefront and staff admin. The Next.js application in `VHI_Website` becomes a presentation layer only.

---

## 1. Product Overview

Vidorck Hair sells its own hair-care products and two fixed hair-care sets online to customers in Greater Accra. `VHI_Website/website-spec.md` defines the complete customer and staff experience. This document moves the **server side** of that experience out of Next.js and into a standalone Laravel backend in this repository.

Expected load is roughly 200 customers per month and 3–4 staff users. The architecture is deliberately sized for that: one VPS, one SQLite database file, no Redis, no managed database, no container orchestration.

### 1.1 Relationship to `website-spec.md`

- `website-spec.md` remains the **source of truth for business behaviour**: catalogue rules, bundle pricing, delivery areas, checkout, Paystack flow, order lifecycle, refunds, roles, notifications, audit, and import rules.
- This document **replaces** its technology, hosting, data-storage, and authentication sections (§2, §3.2, §18.1, §20, and the Auth.js parts of §17.3) and records every deliberate deviation in §12.
- Where this document is silent, `website-spec.md` applies. Where they conflict, this document wins.

### 1.2 Product Principles

- **Laravel decides; Next.js displays.** All validation, pricing, authentication, payment, order, and notification logic lives in Laravel. Next.js may render pages and submit user input; it never computes money, holds secrets, or makes authorization decisions.
- **Small server, honest limits.** Choose components that run comfortably on a single small VPS and can be restored from backup by one person.
- **Money is exact.** All amounts are integer pesewas in `GHS`. No floats anywhere in the money path.
- **Payment truth comes from Paystack, verified server-to-server.** The browser never tells the backend that a payment succeeded.
- **Nothing financial is ever deleted.** Orders, payments, refunds, and audit events are append-only or soft-state.

### 1.3 v1 Goals

- Serve the public catalogue, bundles, and delivery areas to the storefront through a versioned JSON API.
- Run guest checkout end to end with Paystack and create exactly one order per verified payment.
- Let customers track orders and request pre-dispatch cancellation.
- Give staff a role-protected admin API for orders, refunds, catalogue (including **image upload**), customers, staff accounts, and audit history.
- Send transactional email (Resend) and SMS (Arkesel) reliably through a queue.
- Back up the database continuously off the server and prove a restore works before launch.

### 1.4 Non-Goals for v1

Everything in `website-spec.md` §1.2 remains out of scope, except product image upload in admin, which this document brings into scope. Also out of scope for the backend:

- Customer accounts or customer login.
- PostgreSQL, MySQL, Redis, or any second data store.
- Docker/Kubernetes in production (allowed for local development only if useful).
- Horizontal scaling, multiple app servers, or read replicas.
- Serving or rendering any HTML pages other than Laravel's default health/maintenance responses.
- Inventory or stock quantities.

---

## 2. Users and Roles

| Actor | How they reach the backend | Auth |
|---|---|---|
| Customer (guest) | Storefront on `vidorckhair.com` → public API | None; rate-limited; order lookups require order number + phone |
| Sales/Orders staff (`SALES_ORDERS`) | Admin on `admin.vidorckhair.com` → admin API | Sanctum session cookie |
| Administrator (`ADMIN`) | Same | Sanctum session cookie |
| Paystack | Webhook | HMAC SHA-512 signature |
| Vercel/Next.js server | Public API (server-side rendering) | None (public data only) |
| Developer | SSH, Artisan commands | Server access |

The permission matrix is `website-spec.md` §17.1 with one change: **Admin may upload and replace product and bundle images.** Sales/Orders staff stay read-only on the catalogue.

---

## 3. Architecture

### 3.1 Topology

```
Customer browser ──► vidorckhair.com        (Vercel · Next.js renders public pages, fetching the public API)
Staff browser   ──► admin.vidorckhair.com   (Vercel · Next.js admin pages run in the browser only)
                         │
                         │  public JSON  /  session cookie + XSRF token (admin)
                         ▼
          api.vidorckhair.com  (Cloudflare proxy → Contabo VPS)
          ├── Nginx → PHP-FPM 8.4 → Laravel 12
          ├── database/database.sqlite  (WAL) — app data, sessions, queue, cache, rate limits
          ├── storage/app/public/media  — uploaded product images (served by Nginx)
          ├── queue worker (supervisor)  → Resend · Arkesel · Vercel revalidation
          ├── scheduler (cron, every minute)
          └── Litestream ──► Backblaze B2 (continuous DB replication)
Paystack ──► api.vidorckhair.com/api/v1/webhooks/paystack
```

### 3.2 Responsibility split

| Concern | Laravel (this repo) | Next.js (`VHI_Website`) |
|---|---|---|
| Catalogue data, prices, bundles | Owns, validates, serves | Fetches and renders |
| Images | Stores, resizes, serves URLs | Renders `<img>` from API URLs |
| Cart | Validates and reprices at checkout | Holds session cart in `sessionStorage` (unchanged) |
| Checkout validation | Authoritative | Optional client hints only |
| Paystack | Initialises, verifies, handles webhook | Redirects to the URL Laravel returns |
| Orders, tracking, refunds | Owns | Forms + display |
| Staff auth and roles | Owns (Sanctum SPA session) | Login form; calls API with `credentials: 'include'` |
| Email/SMS | Owns | None |
| Next.js server | — | Server-side rendering of public pages **only**; no API route handlers, no secrets beyond the revalidation secret |

`src/app/api/**`, `src/data/catalog.ts`, `src/data/bundles.ts`, `src/lib/checkout/validation.ts`, and `src/lib/delivery/areas.ts` are removed from the frontend once their API equivalents ship (frontend migration is tracked separately; see §11).

### 3.3 Why SQLite

At this traffic (well under 1 request/second on average, single-digit concurrent writers at peak), SQLite in WAL mode is faster than a networked database and removes an entire service to run, secure, and back up. Its one real limit is **a single writer at a time**; every write here (checkout attempt, webhook, admin save) is a short transaction, so contention is negligible. The decision is revisited only if sustained write concurrency or multi-server deployment becomes necessary (§13).

Required connection settings (`config/database.php`, applied on every connection):

- `journal_mode = WAL`
- `synchronous = NORMAL`
- `busy_timeout = 5000` (ms)
- `foreign_keys = ON`
- `temp_store = MEMORY`

Write transactions that read-then-write (order numbering, webhook processing) use `BEGIN IMMEDIATE` semantics so they take the write lock up front and never deadlock on upgrade.

### 3.4 Code organisation

One Laravel application, organised by domain module, following the modular-monolith approach in `crowdtrace-backend/docs/decisions/ADR-001-modular-monolith.md`:

```
app/
  Modules/
    Catalog/        products, variants, categories, hair types, bundles, images, import command
    Checkout/       delivery areas, checkout validation, Paystack client + webhook
    Orders/         order creation, numbering, status machine, tracking, refunds, manual orders
    Customers/      CRM records and notes
    Staff/          staff users, auth, roles/policies, audit log
    Notifications/  mail templates, SmsProvider + ArkeselSmsProvider, notification log
  Support/          money, phone normalisation, shared HTTP/error handling
```

Each module exposes its behaviour through action/service classes and form requests; controllers stay thin. Modules talk to each other through those classes or domain events, not by reaching into each other's models.

---

## 4. Technology Baseline

| Layer | Decision |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Database | SQLite 3 (WAL), single file |
| Migrations | Laravel migrations, checked in; no schema changes outside migrations |
| Auth | Laravel Sanctum **SPA (stateful cookie) authentication**; `SESSION_DRIVER=database` |
| Password hashing | Argon2id (`HASH_DRIVER=argon2id`) |
| Queue | `QUEUE_CONNECTION=database`, one supervisor-managed worker |
| Cache / rate limits | `CACHE_STORE=database` |
| Images | Stored on local disk (`public` disk); resized to WebP with Intervention Image 3 (GD driver) |
| Payments | Paystack REST API via Laravel HTTP client |
| Email | Resend (`resend/resend-laravel`), from `noreply@<domain>` |
| SMS | Arkesel behind an `SmsProvider` interface |
| Excel import | `phpoffice/phpspreadsheet` (or `maatwebsite/excel`), Artisan command |
| Testing | Pest, running on SQLite |
| API docs | OpenAPI 3 document checked in at `docs/openapi.yaml`, generated with Scramble or maintained by hand |
| Web server | Nginx + PHP-FPM on Ubuntu 24.04 LTS |
| Edge | Cloudflare (DNS, TLS, proxy, caching for `/media/*`) |
| Backups | Litestream → Backblaze B2; nightly `rclone` sync of `storage/app/public/media` |
| CI/CD | GitHub Actions: lint (Pint), static analysis (Larastan), tests, then SSH deploy on `main` |

Pin resolved versions in `composer.lock`. Do not upgrade major versions mid-project without a written decision.

---

## 5. Data Model

All tables use `id` as an auto-increment integer primary key internally. Records exposed publicly use a separate stable identifier (slug, order number, or `public_id` ULID); internal IDs never appear in public URLs. Timestamps are stored in UTC; API responses use ISO-8601 with offset, and customer/staff-facing text renders `Africa/Accra`.

Money columns are integers suffixed `_minor` (pesewas). SQLite has no enum type: status columns are `TEXT` with a `CHECK` constraint plus a matching PHP backed enum.

### 5.1 Catalogue

| Table | Key columns |
|---|---|
| `categories` | `name`, `slug` (unique), `display_order`, `is_active` |
| `hair_types` | `name`, `slug` (unique) |
| `products` | `name`, `slug` (unique), `normalized_name`, `category_id`, `display_order`, `is_published`, `is_new_look`, `image_alt`, `image_position` |
| `product_hair_type` | `product_id`, `hair_type_id` (composite PK) |
| `product_variants` | `product_id`, `size_label`, `normalized_size`, `product_code` (unique), `source_price_minor`, `price_minor`, `is_active`, `display_order`; unique (`product_id`, `normalized_size`) |
| `media` | `public_id` (ULID), `owner_type`, `owner_id`, `original_path`, `variants_json` (paths per width), `width`, `height`, `mime`, `bytes`, `uploaded_by` |
| `bundles` | `name`, `slug` (unique), `product_code` (unique), `price_minor`, `is_published`, `display_order`, `image_alt` |
| `bundle_items` | `bundle_id`, `product_variant_id`, `quantity`, `display_order` |
| `slug_redirects` | `entity_type`, `old_slug`, `new_slug` (for SEO redirects when a slug changes) |

`price_minor` is set explicitly by Admin or by import. The +GH₵2.00 markup (`website-spec.md` §8.1) is applied when deriving `price_minor` from `source_price_minor` during import and in the admin price form; it is never exposed in any public response.

### 5.2 Checkout and payment

| Table | Key columns |
|---|---|
| `delivery_areas` | `name` (unique), `aliases_json`, `is_active`, `display_order` |
| `checkout_attempts` | `public_id` (ULID), `paystack_reference` (unique), `customer_snapshot_json`, `delivery_snapshot_json`, `items_snapshot_json`, `amount_minor`, `currency`, `status` (`PENDING`, `SUCCEEDED`, `FAILED`, `ABANDONED`, `MISMATCH`), `expires_at`, `parent_attempt_id` (retries) |
| `payment_events` | `provider`, `provider_event_id` (unique per provider; derived from reference + event type when Paystack sends none), `event_type`, `reference`, `signature_valid`, `payload_redacted_json`, `processed_at` |

### 5.3 Orders

| Table | Key columns |
|---|---|
| `order_number_sequences` | `year` (PK), `last_value` |
| `orders` | `order_number` (unique, e.g. `VHI-2026-001247`), `tracking_token` (random, for the success page), `source` (`ONLINE`, `MANUAL`), `customer_id`, `checkout_attempt_id` (unique, nullable for manual), `status`, `subtotal_minor`, `total_paid_minor`, `currency`, `payment_method`, `payment_reference` (unique), `customer_snapshot_json`, `delivery_snapshot_json`, `delivery_fee_note`, `created_by_staff_id` (manual only), `paid_at` |
| `order_items` | `order_id`, `line_type` (`PRODUCT`, `BUNDLE`, `BUNDLE_EXTRA`), `parent_item_id`, `product_variant_id` / `bundle_id` (nullable, traceability only), `name_snapshot`, `size_snapshot`, `product_code_snapshot`, `image_ref_snapshot`, `bundle_contents_json`, `quantity`, `unit_price_minor`, `line_total_minor` |
| `order_status_events` | `order_id`, `from_status`, `to_status`, `actor_type` (`SYSTEM`, `STAFF`), `actor_id`, `reason`, `created_at` |
| `order_notes` | `order_id`, `staff_id`, `body`, `created_at` |
| `refund_requests` | `order_id`, `channel` (`WEB`, `WHATSAPP`, `STAFF`), `reason`, `status` (`REQUESTED`, `UNDER_REVIEW`, `APPROVED`, `REJECTED`, `PROCESSING`, `COMPLETED`, `FAILED`), `decided_by`, `decided_at`, `decision_reason` |
| `refund_transactions` | `refund_request_id`, `method` (`PAYSTACK`, `MANUAL`), `provider_reference`, `amount_minor`, `status`, `response_redacted_json` |
| `refund_notes` | `refund_request_id`, `staff_id`, `body` |

Order status values and allowed transitions are exactly `website-spec.md` §14.2. Transitions are enforced in a single `OrderStateMachine` class; any other code path that changes `orders.status` is a defect.

### 5.4 Customers (CRM)

| Table | Key columns |
|---|---|
| `customers` | `public_id`, `full_name`, `phone_e164` (unique), `phone_display`, `email`, `first_order_at`, `last_order_at`, `orders_count`, `lifetime_paid_minor` |
| `customer_notes` | `customer_id`, `staff_id`, `body` |

A customer record is created or updated **inside the order-creation transaction**, matched on normalised phone (`+233…`). Name and email update to the latest order's values; order snapshots keep the values as they were at purchase. Customers have no login.

### 5.5 Staff, audit, notifications

| Table | Key columns |
|---|---|
| `staff_users` | `name`, `email` (unique), `password`, `role` (`ADMIN`, `SALES_ORDERS`), `is_active`, `must_change_password`, `last_login_at`, `last_login_ip` |
| `sessions` | Laravel default (`user_id` indexes allow revoking all sessions for a staff user) |
| `audit_logs` | `actor_type`, `actor_id`, `action`, `target_type`, `target_id`, `before` / `after` (redacted JSON summaries), `request_id`, `ip_address`, `created_at`; append-only (no update/delete routes; model and SQLite triggers block updates) |
| `notification_logs` | `idempotency_key` (unique), `channel` (`EMAIL`, `SMS`), `template`, `recipient_masked`, `provider_message_id`, `status`, `attempts`, `last_error` |
| `jobs`, `failed_jobs`, `cache`, `cache_locks` | Laravel defaults |

### 5.6 Integrity rules

- Foreign keys are on; financial tables use `restrictOnDelete`, never cascade.
- No hard deletes of products, bundles, orders, payments, refunds, customers, or audit logs through any API.
- Indexes: `products(is_published, display_order)`, `products(normalized_name)`, `orders(status, created_at)`, `orders(order_number)`, `customers(phone_e164)`, `checkout_attempts(paystack_reference)`, `audit_logs(target_type, target_id)`, `audit_logs(created_at)`.
- Seeders create only structural data (categories, hair types, delivery areas from `website-spec.md` §12.2). Production business content arrives through the import command or the admin.

---

## 6. Functional Requirements

### 6.1 Catalogue API (public)

- Return only `is_published = true` products with at least one active variant, and only published bundles whose components are all published and active. The filter is applied in the query, never after.
- `GET /products` supports `q` (case- and diacritic-insensitive name search on `normalized_name`), `category`, `hair_type`, `page` (15 per page), returning `meta.total`, `meta.page`, `meta.per_page`, and facet counts for categories and hair types based on published products.
- `GET /products/{slug}` returns 404 for unpublished or unknown slugs; responds `301` with a `Location` header and body `{ "redirectTo": "<new-slug>" }` if the slug is in `slug_redirects`; the storefront fetches with `redirect: "manual"` and issues its own permanent redirect.
- Responses never include `source_price_minor`, internal IDs, or markup values.
- Response shapes are designed to map directly onto the existing frontend types (`StorefrontProduct`, `ProductVariant`, bundle types): `slug`, `name`, `category`, `image` (object with `url` per width plus `alt`), `imagePosition`, `isNewLook`, `hairTypes[]`, `variants[{ label, productCode, priceMinor }]`.

### 6.2 Images (admin upload)

- Admin uploads one image per product or bundle (`multipart/form-data`, JPEG/PNG/WebP, max 8 MB, max 6000 px on the long edge).
- Laravel validates the real MIME type, strips EXIF metadata, and writes the original plus WebP renditions at **400, 800, and 1200 px** wide to `storage/app/public/media/{ulid}/`. Processing runs synchronously for files under 8 MB (completes in about a second on the target VPS); if it fails, the upload is rejected and nothing is changed.
- Replacing an image keeps the old files for 30 days (scheduled cleanup) so in-flight cached pages don't 404.
- Nginx serves `/media/*` directly with `Cache-Control: public, max-age=31536000, immutable`; Cloudflare caches it. File paths include the ULID, so a replacement is a new URL and needs no cache purge.
- Upload, replace, and alt-text changes are audited and trigger storefront revalidation (§6.10).

### 6.3 Checkout

Implements `website-spec.md` §12–13 exactly. Endpoint behaviour:

1. `POST /checkout` accepts the same payload shape as the current `checkoutSchema` (`fullName`, `phone`, `email`, `region`, `area`, `address`, `landmark`, `ghanaPostGps`, `policyAccepted`, `items[{ slug, productCode, quantity, parentLineId?, lineId? }]`).
2. Laravel validates fields, normalises the phone to E.164 (keeping the entered value for display), resolves `area` against `delivery_areas` (name or alias, case-insensitive), and re-reads every product, variant, bundle, and extra from the database.
3. If anything changed or is unavailable, respond `422` with per-line reasons (`ITEM_UNAVAILABLE`, `PRICE_CHANGED` with the new price) so the frontend can show the customer exactly which item changed.
4. If valid, create a `checkout_attempts` row (expires after 60 minutes), call Paystack `transaction/initialize` with `amount` in pesewas, `currency=GHS`, the generated reference, `channels` (`mobile_money`, `card`), and `callback_url` = the storefront's `/checkout/return`. Return `{ authorizationUrl, reference }`.
5. `GET /checkout/{reference}` returns `PENDING`, `SUCCEEDED` (with `orderNumber` and `trackingToken`), or `FAILED`. While still pending and the attempt is older than 10 seconds, the endpoint re-verifies with Paystack (throttled to once per 10 seconds per reference).
6. `POST /checkout/{reference}/retry` creates a new attempt with a new reference from the same snapshot, re-validating prices first.

### 6.4 Paystack webhook

- `POST /webhooks/paystack` reads the **raw body**, computes HMAC-SHA512 with the secret key, and compares it to `x-paystack-signature` in constant time. Invalid signature → `401`, logged without the payload.
- Optionally restrict to Paystack's published webhook IPs at the Nginx layer.
- Insert into `payment_events` first; a unique-constraint hit means a duplicate → return `200` immediately.
- For `charge.success`: call `transaction/verify/{reference}`, then confirm the reference exists, status is `success`, currency is `GHS`, and amount equals `amount_minor`. Mismatches mark the attempt `MISMATCH`, raise an admin reconciliation flag, and create no order.
- On success, in one `BEGIN IMMEDIATE` transaction: mark attempt `SUCCEEDED`, allocate the next order number from `order_number_sequences`, upsert the customer, create the order and item snapshots, and write the `PAID` status event. The unique `orders.checkout_attempt_id` guarantees at most one order even if the webhook and the status-poll verification race.
- After commit, dispatch queued jobs: customer confirmation email and customer SMS (order number, total paid, tracking link). Staff receive no order alerts.
- Respond `200` within 5 seconds. All slow work is queued.

### 6.5 Orders: tracking and customer requests

- `POST /orders/track` with `orderNumber` + `phone`. Both are normalised; a mismatch or unknown order returns the same generic `404` body. Rate limit: 5 per minute and 20 per hour per IP, plus 10 per hour per order number.
- The response contains only customer-safe data: order number, created/paid timestamps, item names/sizes/quantities, total paid, current status, and a timeline of customer-visible status timestamps. No internal notes, staff names, payment references, or courier details.
- `GET /orders/confirmation/{trackingToken}` powers the success page (no phone required; token is 32+ random chars, not guessable; returns the compact success payload only).
- `POST /orders/cancellation-requests` with `orderNumber`, `phone`, `reason`: allowed only before `DISPATCHED`; creates a `refund_requests` row with `channel=WEB`, and emails and texts the customer an acknowledgement. Staff are not alerted. Does not change order status.

### 6.6 Contact form

- `POST /contact` (name, email, phone optional, message; honeypot field; rate limit 3 per 10 minutes per IP) queues an email to the configured Vidorck inbox through Resend. Nothing is stored beyond the notification log.

### 6.7 Admin authentication (Sanctum SPA)

Flow:

1. Admin app calls `GET https://api.vidorckhair.com/sanctum/csrf-cookie` with credentials.
2. `POST /api/v1/admin/auth/login` with email and password, sending `X-XSRF-TOKEN`. On success Laravel regenerates the session ID and sets an `HttpOnly; Secure; SameSite=Lax` session cookie scoped to `.vidorckhair.com`.
3. All admin calls use `credentials: 'include'`; Sanctum's `EnsureFrontendRequestsAreStateful` treats `admin.vidorckhair.com` as stateful.

Requirements (carrying over `website-spec.md` §17.3):

- Generic `Invalid email or password.` for any failure; rate limit 5 attempts per minute per email+IP, with lockout logging.
- Idle timeout 60 minutes (`SESSION_LIFETIME=60`), absolute session lifetime 12 hours (checked in middleware against a login timestamp stored in the session).
- Disabling a staff user, resetting their password, or changing their role deletes all of their `sessions` rows immediately.
- `must_change_password` forces the user to `POST /admin/auth/password` before any other admin endpoint responds (others return `403` with `PASSWORD_CHANGE_REQUIRED`).
- Admin-initiated reset sets a one-time temporary password (shown once to the Admin) and `must_change_password = true`. No self-service reset by email in v1.
- First Admin is created only by `php artisan staff:create-admin` (interactive, prompts for credentials; refuses if an Admin already exists unless `--force`).
- Every admin route checks `is_active` and role through Laravel policies/gates. Hiding a route in the UI is never authorisation.

Required environment settings: `SESSION_DOMAIN=.vidorckhair.com`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=admin.vidorckhair.com`, CORS `allowed_origins` = storefront and admin origins only, `supports_credentials=true`.

### 6.8 Admin capabilities

| Area | Endpoints (under `/api/v1/admin`) | Roles |
|---|---|---|
| Session | `auth/login`, `auth/logout`, `auth/me`, `auth/password` | Both |
| Dashboard | `GET dashboard` → today's paid orders, sales today, Processing / Ready for Dispatch / Dispatched counts, open refund requests, payment reconciliation flags | Both |
| Orders | `GET orders` (filters: status, source, date range, search by order number/name/phone; paginated), `GET orders/{number}`, `POST orders/{number}/transitions` (`to_status`, optional `reason`), `POST orders/{number}/notes`, `PATCH orders/{number}/delivery` (reason required) | Both; delivery edit ADMIN only |
| Manual orders | `POST orders/manual` (`website-spec.md` §15; requires `confirm: true`; duplicate `payment_reference` rejected) | Both |
| Refunds | `GET refunds`, `GET refunds/{id}`, `POST refunds/{id}/notes`, `POST refunds/{id}/decision` (`APPROVE`/`REJECT` + reason), `POST refunds/{id}/manual-completion` | List/notes: both. Decide/complete: ADMIN |
| Catalogue | `GET/POST/PATCH products`, `POST products/{id}/publish`, `POST products/{id}/unpublish`, `GET/POST/PATCH products/{id}/variants`, `POST products/{id}/image`, same set for `bundles`, `GET/POST/PATCH categories`, `GET/POST/PATCH delivery-areas` | Read: both. Write: ADMIN |
| Customers | `GET customers` (search by name/phone/email), `GET customers/{publicId}` (with order history), `POST customers/{publicId}/notes` | Both |
| Staff | `GET/POST staff`, `PATCH staff/{id}` (role, active), `POST staff/{id}/reset-password` | ADMIN |
| Audit | `GET audit-logs` (filters: actor, action, target, date) | ADMIN |
| Payments | `GET payments/reconciliation` (attempts in `MISMATCH` or verified-but-orderless state) | ADMIN |

Staff can never mark an online payment as successful. Reconciliation is view-and-note only; resolution happens in the Paystack dashboard and, if money was genuinely received, through a manual order referencing the Paystack transaction.

Approved Paystack refunds call `POST /refund` and move the request to `PROCESSING`; the `refund.processed` / `refund.failed` webhooks move it to `COMPLETED` / `FAILED` and the order to `REFUNDED` only on confirmed success.

### 6.9 Notifications

- All email and SMS goes through queued jobs keyed by an idempotency key (`{template}:{order_number}:{status}` or similar) stored in `notification_logs`. A second dispatch with the same key is a no-op.
- **Email (Resend)** from `noreply@<domain>` with `Reply-To` set to the Vidorck support inbox. Templates: order confirmation, Ready for Dispatch, Dispatched, Delivered, cancellation request received, refund approved, refund rejected, refund completed, refund failed, contact-form relay. Each has HTML and plain-text versions. SPF, DKIM, and DMARC are configured for the domain before launch.
- **SMS (Arkesel)** via `SmsProvider` interface (`send(string $toE164, string $message): SmsResult`) with `ArkeselSmsProvider` as the only v1 implementation, `LogSmsProvider` for local/staging. Sender ID `VIDORCK`, registered as **transactional**. Customers are texted (alongside the email) when their order is paid, ready for dispatch, dispatched and delivered, when a cancellation request is received, and on refund approval, rejection, failure and completion; item-update texts list the new items. A missing or invalid phone means email only. Staff receive no SMS. Messages stay within 2 SMS segments (306 GSM-7 characters); item lists are truncated with "+N more".
- One failing recipient never blocks the others. Failures retry 3 times with backoff (1, 5, 15 minutes), then appear in `notification_logs` with status `FAILED`.
- A notification failure never rolls back an order or status change.

### 6.10 Storefront revalidation

When a product, variant, bundle, image, category, or delivery area changes, Laravel queues a `POST` to the storefront's revalidation endpoint (`STOREFRONT_REVALIDATE_URL`) with a shared secret header and the affected tags (e.g. `products`, `product:{slug}`, `bundles`). Failures retry and are logged; the storefront also uses a time-based fallback revalidation (e.g. 10 minutes) so a missed call self-heals.

This endpoint is the **only** route handler kept in the Next.js app.

### 6.11 Excel catalogue import

Implements `website-spec.md` §21 as an Artisan command run by a developer on the server or locally against a copied database:

```
php artisan catalog:import path/to/workbook.xlsx --dry-run
php artisan catalog:import path/to/workbook.xlsx --apply [--images=path/to/folder]
```

- Dry run produces a row-level report (created/updated/unchanged/skipped/error counts with sheet, row, field, reason).
- Apply upserts valid rows (each product+variants in its own transaction), skips invalid rows, never deletes or unpublishes products absent from the workbook, and creates a timestamped SQLite snapshot (`VACUUM INTO`) before writing.
- `--images` matches files to products through normalised names plus an explicit override map, and runs them through the same resize pipeline as admin uploads. Ambiguous matches are reported, never guessed.
- The import is not exposed through the admin API in v1 (Admin edits individual items and uploads images in the admin instead).

### 6.12 Audit

Every staff mutation, every security event (login success/failure, logout, password change, session revocation), every order transition (including system ones), manual order creation, refund action, catalogue change, image change, and import run writes an `audit_logs` row with actor, action, target, a redacted before/after diff, and the request correlation ID (`X-Request-Id`, generated if absent and echoed in responses). Passwords, tokens, and full payment payloads are never recorded.

---

## 7. API Contract Conventions

- Base path `/api/v1`. Breaking changes require `/api/v2`; additive changes don't.
- JSON keys are `camelCase` to match the existing TypeScript types; Laravel API Resources handle the mapping from `snake_case` columns.
- Money is always `{ "amountMinor": 14300, "currency": "GHS" }` or a `*Minor` integer field next to a single `currency` field on the parent. Never decimals.
- Collections: `{ "data": [...], "meta": { "page", "perPage", "total" } }`.
- Errors: `{ "message": "Human sentence.", "code": "MACHINE_CODE", "errors": { "field": ["..."] } }` with `422` for validation, `401` unauthenticated, `403` forbidden, `404` not found / generic lookup failure, `409` invalid state transition, `429` rate limited.
- Every response includes `X-Request-Id`.
- `docs/openapi.yaml` is the contract the frontend builds against; CI fails if routes and the spec drift (route-list check).

---

## 8. Non-Functional Requirements

### Security

- Cloudflare proxies `api.vidorckhair.com`; UFW allows 80/443 only from Cloudflare IP ranges and SSH (key-only, non-root user, fail2ban).
- Cloudflare origin certificate on Nginx; `Full (strict)` TLS mode.
- `APP_DEBUG=false` in production; secrets only in the server's `.env` (permissions `600`), never committed.
- Security headers on API responses: `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Strict-Transport-Security`.
- Uploaded files are stored outside any PHP-executable path; Nginx denies PHP execution under `/media`.
- `database.sqlite`, `.env`, and `storage/` (except `/media`) are not web-reachable.
- Personal data in logs is masked (phone last 3 digits, email first letter + domain).

### Reliability and performance

- p95 API latency under 300 ms for catalogue reads and under 800 ms for checkout (excluding Paystack round-trip) on the target VPS.
- The queue worker restarts automatically (supervisor) and on every deploy (`queue:restart`).
- Scheduler jobs: expire stale checkout attempts (hourly), re-verify `PENDING` attempts older than 15 minutes once with Paystack (every 15 minutes), prune old image files (daily), prune `sessions` (daily), `PRAGMA optimize` (daily), Litestream health check alert (hourly).
- Deploys are zero-downtime via release directories with a `current` symlink (Deployer or a simple script); migrations run before the symlink switch and must be backwards compatible with the previous release.

### Backups and recovery

- **Litestream** replicates `database.sqlite` continuously to a Backblaze B2 bucket (retention 30 days, snapshots daily).
- **Media**: nightly `rclone sync` of `storage/app/public/media` to the same bucket.
- **Restore drill** documented in `docs/runbooks/restore.md` and performed once on a fresh VPS before launch; target RPO ≤ 1 minute, RTO ≤ 1 hour.
- Alert (email to the Admin) if Litestream replication lag exceeds 10 minutes.

### Observability

- Laravel logs as JSON to `storage/logs` with daily rotation (14 days), including `request_id`, route, status, duration.
- `GET /up` health endpoint (Laravel default) used by an external uptime monitor (e.g. UptimeRobot free tier) to alert on downtime.
- Failed jobs and notification failures are visible to Admin via the dashboard counts.

### Data protection

- Follows Ghana's Data Protection Act, 2012 (Act 843) and `website-spec.md` §19.3.
- Retention: order and payment records kept 7 years (financial records); `checkout_attempts` that never succeeded are deleted after 90 days; contact-form content is not stored; notification log recipient fields are masked.

---

## 9. Environments

| Env | Where | Data | Paystack | Notifications |
|---|---|---|---|---|
| Local | Developer machine (`php artisan serve` or Herd) | Local SQLite file, seeders | Test keys | `LogSmsProvider`, Resend test/`log` mailer |
| Staging | Same VPS, separate Linux user, `staging-api.vidorckhair.com`, separate DB file and B2 prefix | Copy of structural data only | Test keys | SMS to allowlisted test numbers only; email to an allowlist |
| Production | `api.vidorckhair.com` | Live | Live keys | Live |

Production deploys only from `main` after staging has been checked by the Product Owner.

---

## 10. Success Criteria

- A customer can browse, check out, pay with MoMo or card in Paystack test mode, and see a confirmation, with exactly one order created even when the webhook is delivered 3 times and the status poll runs at the same time.
- An amount or currency mismatch never creates an order and shows up in admin reconciliation.
- Every endpoint in §6.8 rejects a `SALES_ORDERS` user where the matrix says no, and rejects a disabled user immediately after disablement.
- Admin can upload a product image and see it on the storefront within 1 minute without a redeploy.
- A full restore from Backblaze B2 to a fresh VPS completes in under 1 hour with no data older than 1 minute lost.
- Monthly running cost (VPS + B2 + SMS at ~600 messages) stays within the Product Owner's budget; no paid service beyond Contabo, Backblaze, Resend (free tier where possible), Arkesel, and Paystack fees.

---

## 11. Frontend Migration Dependencies (tracked in `VHI_Website`)

Not built in this repository, but this API is designed for them and v1 is not complete without them:

1. Replace `src/data/catalog.ts` and `bundles.ts` with fetches from `/api/v1/products` and `/bundles` using tagged caching; add `/api/revalidate` route (§6.10).
2. Replace `src/app/api/checkout/validate` with a call to `POST /api/v1/checkout`; redirect to `authorizationUrl`; add `/checkout/return` that polls `GET /checkout/{reference}`.
3. Add tracking, cancellation-request, and contact forms posting to the API.
4. Build the admin UI on `admin.vidorckhair.com` as browser-only pages that call the admin API with `credentials: 'include'`.
5. Point image rendering at API-provided `/media` URLs and remove Cloudinary configuration.
6. Delete the remaining Next.js route handlers so no business logic stays on Vercel.

---

## 12. Deviations from `website-spec.md`

| `website-spec.md` | This spec | Reason |
|---|---|---|
| §2: PostgreSQL on Neon, Prisma | SQLite (WAL) on the VPS, Laravel migrations | Small VPS, low traffic, one less service to run and pay for |
| §2: Hosting on Vercel for everything | Next.js on Vercel; Laravel API on Contabo | Backend separated from frontend |
| §2/§17.3: Auth.js credentials in Next.js | Laravel Sanctum SPA session auth | Laravel owns all auth |
| §2/§18.1: Cloudinary | Local disk + Nginx + Cloudflare cache | Cost; images now admin-managed |
| §1.2/§8.5/§17.1: no image upload in Admin | Admin can upload/replace product and bundle images | Product Owner decision, 2026-10-08 |
| §18.3: Existing MTN SMS provider | Arkesel via `SmsProvider` (other drivers can be added) | Low cost, Laravel support; account being created |
| §3.2: Vercel preview DBs / Neon branches | Staging API on same VPS with its own SQLite file | Single-server setup |
| §24 logging: Vercel logs | Laravel JSON logs on the VPS + uptime monitor | Backend no longer on Vercel |

---

## 13. Revisit Triggers

Re-open the SQLite and single-VPS decision if any of these happens:

- Sustained `database is locked` errors after `busy_timeout`, or write p95 above 200 ms.
- More than one application server is needed.
- Monthly orders exceed roughly 20,000 or traffic exceeds roughly 50 requests/second sustained.
- A feature needs concurrent long-running write transactions (e.g. bulk imports during trading hours).

---

## 14. Open Items Before Build

| Item | Owner | Needed for |
|---|---|---|
| Final email domain for `noreply@` and the support/reply-to inbox | Product Owner | Resend setup, DNS records |
| Arkesel API key and approved `VIDORCK` sender ID (2–4 weeks) | Product Owner | Customer SMS |
| Paystack test and live keys; whether the account supports the refund API | Product Owner | Checkout, refunds |
| Contabo VPS plan and Backblaze B2 account | Product Owner | Deployment, backups |
| Corrected catalogue workbook (blank/malformed prices per `website-spec.md` §8.1) | Product Owner | Import |
| Legal review of refund/cancellation policy wording | Product Owner | Launch |
