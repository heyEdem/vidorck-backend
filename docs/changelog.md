# Changelog

## 2026-10-19 — Dashboard date filters

- Added today, calendar week (Monday–Sunday), month, year, and inclusive custom date
  ranges to the admin dashboard. Date validation uses `DashboardRequest`;
  `BuildDashboard` returns range metadata, paid-order counts, and sales in pesewas.
- Preserved legacy today totals and current operational counts. The website now
  includes preset selection and custom date inputs, loading, and retry states.
- Updated the OpenAPI contract and dashboard date-boundary/validation tests.

## 2026-10-16 — Admin refunds and order item edits

- Added the Admin refund queue and detail workflow for approval, rejection, Paystack
  retry, manual refund recording, and staff-only notes.
- Added Admin order item edits with current-price checks for changed lines, required
  offline settlement details, and conflict detection for concurrent edits.
- Recorded each item change in an append-only revision history protected by database
  triggers and an insert-only model; its migration is irreversible and production
  blocks destructive database commands. Edits preserve the original amount paid.
- Updated order notification planning for content changes and documented the Orders and
  Customers entry points in `docs/implementation.md`.

## 2026-10-13 — SMSOnlineGH SMS driver

- Added `SMS_DRIVER=smsonlinegh` beside `log` and `arkesel`, using the vendored Zenoph SDK
  (`packages/zenoph-notify`, patched with 3s connect and 10s total cURL timeouts; see `PATCHES.md`).
  Config: `SMSONLINEGH_API_KEY`, `SMSONLINEGH_HOST` (default `api.smsonlinegh.com`), `SMSONLINEGH_SENDER_ID`.
- A fresh `SMSRequest` is built per send behind the `SmsOnlineGhClient` seam; tests never touch the network.
- Errors map by code only: HTTP 400/401/403/422, handshakes 1203-1205, 1401, 1402, 1405, 1408, 1409 and
  destination statuses 2119, 2121, 2126-2128, 2140 are permanent. Everything else, including
  connect or timeout, 5xx, 1403, 1407 and unknown or missing status, retries. A rejected destination
  returned with handshake OK is still treated as a failure.
- `phpunit.xml` pins `SMS_DRIVER=log` and empty SMSOnlineGH and Resend keys.

## 2026-10-13 — Phase 6 catalogue import and scheduled jobs

- Added `php artisan catalog:import {file} --dry-run|--apply [--images=dir] [--report=csv]`
  with a documented workbook contract (`catalogue-import.md`). Header mismatches stop before
  any write; bad rows are reported and skipped; re-runs report everything unchanged.
- Apply takes a `VACUUM INTO` snapshot, writes each product in its own transaction, skips a
  whole bundle if any component is invalid, never deletes or unpublishes, and records one
  audit row and one storefront revalidation atomically.
- Added scheduled jobs: hourly attempt expiry, 15-minute one-off Paystack re-verify, daily
  retired-media, session, failed-attempt and failed-job pruning, `PRAGMA optimize`, and a
  Litestream check placeholder.
- Scheduled re-verification can no longer downgrade an attempt a webhook has verified.
- Claude Sonnet 5.5 subagents implemented the feature; Claude Opus 5.5 advised; Codex drafted
  the plan and reviewed three times. Verified 499 tests / 3388 assertions. Evidence is in
  `implementation/phase_6_progress.md` and `phase_6_review.md`.

## 2026-10-12 — Phase 5 notifications

- Added append-only `notification_logs` and `notification_attempts` (triggers
  reject UPDATE, DELETE and TRUNCATE) and `NotificationDispatcher::once()`, which
  writes the log row and an encrypted queued job atomically inside the caller's transaction.
- Added `SendNotification` (4 tries, backoff 60/300/900s, permanent errors fail at once),
  Arkesel and log SMS providers, a Resend email sender with HTML and text templates,
  and a staging-only recipient allowlist.
- Wired order paid (online and manual), forward status milestones, cancellation
  requests and refund outcomes through failure-proof planners.
- Added `POST /api/v1/contact` (limiter 3 per 10 minutes per IP, then honeypot,
  validation, one relay email, 503 when `CONTACT_EMAIL` is unset); nothing is stored.
- `failedNotificationCount` on the dashboard is now real.
- Claude Sonnet 5.5 subagents implemented the feature; Claude Opus 5.5 advised; the
  plan was drafted by Codex. Verified 456 tests / 3152 assertions. Evidence and open
  items are in `implementation/phase_5_progress.md` and `phase_5_review.md`.

## 2026-10-11 — Phase 4 follow-ups

- A manual order whose payment reference matches an orderless Paystack attempt
  now links that attempt (`orders.checkout_attempt_id`, attempt SUCCEEDED,
  `checkout.attempt.linked` audit row) in the same transaction. If the Paystack
  amount differs from the manual amount the attempt is flagged MISMATCH
  (`checkout.mismatch.detected`) and stays on the reconciliation list.
- `CreateOrderFromAttempt`, `CheckCheckoutStatus`, `HandleChargeSuccess` and
  `ProcessPaystackEvent` treat an attempt whose reference is already on an order
  as fulfilled: no unique violation, no 500, no job retries, no provider call.
- Delivery edits and manual orders accept free-text areas; a case-insensitive
  trimmed match on `delivery_areas.name` stores the canonical name.
- The customer tracking timeline shows only the effective path (corrections drop
  the statuses they reverse; one entry per status with its latest time). Admin
  history is unchanged.

## 2026-10-10 — Phase 4 orders, refunds and CRM

- Added `OrderStateMachine` as the only writer of order status, with status
  events, audit rows, Admin-only one-step corrections and a write-guard test.
- Added public tracking (enumeration-resistant, rate limited), order
  confirmation and cancellation requests.
- Added admin order list/detail, transitions, notes and audited delivery edits.
- Added manual paid orders priced from the catalogue snapshot path.
- Added refund requests, Admin decisions, Paystack refunds (no automatic
  retry), signed refund webhooks, manual completion, retry, and a dispatch
  guard; orders become REFUNDED only on confirmation.
- Added customer CRM with append-only notes and the Africa/Accra dashboard.
- Added append-only refund transactions and note tables, and OpenAPI contracts.
- Claude Sonnet 5.5 subagents implemented the feature; Claude Opus 5.5 advised.
  Verified 323 tests / 2514 assertions. Evidence and open questions are in
  `implementation/phase_4_progress.md` and `phase_4_review.md`.

## 2026-10-10 — Phase 3 checkout and Paystack

- Added delivery areas, checkout/payment/order schema, structural seeding,
  immutable paid history, and database constraints for financial integrity.
- Added guest validation, server repricing, immutable snapshots, and bounded
  Paystack initialization/verification calls.
- Added raw-body signed webhooks with atomic durable receipt/job insertion,
  idempotent order creation, yearly numbering, and paid customer summaries.
- Added status polling with a durable verification cooldown, parent-linked
  retries, audited delivery-area management, and Admin reconciliation.
- Added real two-process SQLite payment races, provider-faked money-flow tests,
  and OpenAPI contracts. OrderPaid dispatches after commit for Phase 5 listeners.
- Luna implemented the feature; Claude Opus 5.5 supplied read-only advice and
  review. Evidence is recorded in `implementation/phase_3_progress.md`.

## 2026-10-09 — Phase 2 catalogue and media

- Added catalogue schema, visibility scopes, normalized search, and structural
  seeders that preserve Admin category edits.
- Added public products, bundles, categories, hair types, pagination, facets,
  safe storefront resources, and permanent slug redirects.
- Added audited Admin CRUD, prices, publication, bundle management, and roles.
- Added Intervention Image 3/GD processing, metadata removal, WebP renditions,
  immutable media URLs, and replacement retention.
- Added queued storefront tag refreshes with shared-secret authentication,
  retry/backoff, and dependent bundle invalidation.
- Expanded OpenAPI, CI image extensions, and catalogue acceptance tests.
- Luna implemented the feature; Opus 5.5 reviewed preflight and implementation.
  Verification evidence is recorded in `implementation/phase_2_progress.md`.

## 2026-10-08 — Phase 1 staff authentication and audit

- Added staff accounts with Argon2id hashes and interactive Admin bootstrap.
- Implemented Sanctum SPA login/logout/me, real CSRF protection, login limits,
  idle/absolute expiry, forced password change, and Admin password reset.
- Added Admin staff management, capability gates/policies, last-Admin protection,
  and immediate session revocation through database deletion and version checks.
- Added transactional redacted audit logs, SQLite/model immutability, filtered
  Admin access, and complete OpenAPI staff contracts.
- Addressed Opus 5.5 security/correctness findings with regression tests;
  documented three deferred minors in the Phase 1 review record.
- Verified 113 tests / 688 assertions, Pint, Larastan, Composer validation,
  config/route caching, and local additive migrations.

## 2026-10-08 — Phase 0 foundation

- Scaffolded Laravel 12 targeting PHP 8.4 with Sanctum and a locked dependency tree.
- Added the six domain module layouts and shared API/SQLite conventions.
- Added Pest acceptance tests, Pint, Larastan, GitHub Actions, and OpenAPI route coverage.
- Documented local setup and environment variables for later integrations.

## 2026-10-21 — Business overview analytics

- Extended the existing dashboard endpoint with elapsed-period cash net of issued refunds, comparisons, returning customers, product/set rankings, channels, checkout outcomes and delivery durations.
- Added bounded operational queues based on append-only status history and separate confirmed-payment, mismatch, refund and notification attention counts.
- Preserved permission-based field omission and legacy calendar-period summary fields; capped custom dates at the current Accra date.
- Added dashboard regressions and documented the additive OpenAPI contract in `dashboard-analytics.md`.

## 2026-10-22 — Render Docker demo

- Moved the copied application contents to the demo repository root and preserved
  the outer repository history; the application is no longer an embedded Git repo.
- Added a PHP 8.4 Docker image, build exclusions, and startup migrations with the
  existing web, queue, and scheduler runner.
- Kept SQLite and uploaded media under one `/var/data` mount and documented Render
  environment variables, disk setup, and the disposable demo limitations.
