# Vidorck Hair Backend — Tasks Index

**Last Updated:** 2026-10-13
**Completed:** 46/53 (87%)
**Status:** Phases 0–6 implemented and verified locally; Phase 7 is next

Checkbox states: `[x]` done · `[~]` in progress · `[ ]` pending. Task IDs are `<phase>.<seq>`.

## Progress Summary

| Phase | Title | Progress | Status |
|---|---|---|---|
| 0 | Laravel foundation & API conventions | 6/6 | Complete (local checks passed) |
| 1 | Staff auth, roles & audit | 7/7 | Complete (local checks passed) |
| 2 | Catalogue & media | 7/7 | Complete (local checks passed) |
| 3 | Checkout & Paystack | 7/7 | Complete (local checks passed) |
| 4 | Orders, tracking, refunds & CRM | 8/8 | Complete (local checks passed) |
| 5 | Notifications (Resend & Arkesel) | 6/6 | Complete (local checks passed) |
| 6 | Catalogue import & scheduled jobs | 5/5 | Complete (local checks passed) |
| 7 | Deployment, backups & launch | 0/7 | Not Started |
| **Total** | | **46/53** | **In Progress** |

## Source Map

| Phase | Product spec sections | Business rules (`VHI_Website/website-spec.md`) |
|---|---|---|
| 0 | [§3 Architecture](./product-spec.md#3-architecture) · [§4 Technology Baseline](./product-spec.md#4-technology-baseline) · [§7 API Contract](./product-spec.md#7-api-contract-conventions) | §2, §22 |
| 1 | [§6.7 Admin authentication](./product-spec.md#67-admin-authentication-sanctum-spa) · [§6.12 Audit](./product-spec.md#612-audit) · [§5.5](./product-spec.md#55-staff-audit-notifications) | §17 |
| 2 | [§5.1 Catalogue](./product-spec.md#51-catalogue) · [§6.1 Catalogue API](./product-spec.md#61-catalogue-api-public) · [§6.2 Images](./product-spec.md#62-images-admin-upload) · [§6.10 Revalidation](./product-spec.md#610-storefront-revalidation) | §8, §9, §10 |
| 3 | [§5.2 Checkout and payment](./product-spec.md#52-checkout-and-payment) · [§6.3 Checkout](./product-spec.md#63-checkout) · [§6.4 Paystack webhook](./product-spec.md#64-paystack-webhook) | §11, §12, §13, §14.1 |
| 4 | [§5.3 Orders](./product-spec.md#53-orders) · [§5.4 Customers](./product-spec.md#54-customers-crm) · [§6.5 Tracking](./product-spec.md#65-orders-tracking-and-customer-requests) · [§6.8 Admin capabilities](./product-spec.md#68-admin-capabilities) | §14, §15, §16, §17.2 |
| 5 | [§6.6 Contact form](./product-spec.md#66-contact-form) · [§6.9 Notifications](./product-spec.md#69-notifications) | §14.5, §18.2, §18.3 |
| 6 | [§6.11 Excel import](./product-spec.md#611-excel-catalogue-import) · [§8 Reliability](./product-spec.md#reliability-and-performance) | §21 |
| 7 | [§8 Non-Functional](./product-spec.md#8-non-functional-requirements) · [§9 Environments](./product-spec.md#9-environments) · [§10 Success Criteria](./product-spec.md#10-success-criteria) | §24 |

## Verification Commands

| Purpose | Command |
|---|---|
| Full test suite | `php artisan test` |
| Focused tests | `./vendor/bin/pest --filter='Checkout'` |
| Code style | `./vendor/bin/pint --test` |
| Static analysis | `./vendor/bin/phpstan analyse --memory-limit=1G` |
| Run API locally | `php artisan serve` (with `php artisan queue:work` in a second terminal) |
| Inspect API routes | `php artisan route:list --path=api` |
| Apply additive migrations | `php artisan migrate --force` |
| Inspect tracked docs | `git status --short docs` |

Full task detail lives in the per-phase files under [tasks/](./tasks/). Summary below.

## Phase 0 — Laravel Foundation & API Conventions -> [tasks/phase_0_foundation.md](./tasks/phase_0_foundation.md)
- [x] 0.1 Scaffold Laravel 12 / PHP 8.4 project and pin dependencies
- [x] 0.2 Configure SQLite (WAL, busy timeout, foreign keys) for app, sessions, queue, and cache
- [x] 0.3 Create module layout under `app/Modules` and `app/Support`
- [x] 0.4 Add API conventions: `/api/v1`, error envelope, `X-Request-Id`, camelCase resources, money helper
- [x] 0.5 Configure CORS, security headers, and environment templates
- [x] 0.6 Add CI (Pint, Larastan, Pest) and OpenAPI document scaffold

## Phase 1 — Staff Auth, Roles & Audit -> [tasks/phase_1_staff_auth_audit.md](./tasks/phase_1_staff_auth_audit.md)
- [x] 1.1 Add `staff_users` schema, `StaffRole` enum, and Argon2id hashing
- [x] 1.2 Add `staff:create-admin` bootstrap command
- [x] 1.3 Implement Sanctum SPA login, logout, me, and session hardening
- [x] 1.4 Implement forced password change and Admin-initiated reset
- [x] 1.5 Implement staff management endpoints with immediate session revocation
- [x] 1.6 Add append-only `audit_logs` and `AuditLogger`
- [x] 1.7 Add auth, role-matrix, and audit tests

## Phase 2 — Catalogue & Media -> [tasks/phase_2_catalogue_media.md](./tasks/phase_2_catalogue_media.md)
- [x] 2.1 Add catalogue schema and structural seeders
- [x] 2.2 Implement public product and bundle APIs
- [x] 2.3 Implement admin catalogue CRUD, publish/unpublish, and slug redirects
- [x] 2.4 Implement bundle management and bundle price rules
- [x] 2.5 Implement image upload and WebP resize pipeline
- [x] 2.6 Implement storefront revalidation job
- [x] 2.7 Add catalogue visibility, pricing, and image tests

## Phase 3 — Checkout & Paystack -> [tasks/phase_3_checkout_paystack.md](./tasks/phase_3_checkout_paystack.md)
- [x] 3.1 Add delivery areas, checkout attempts, payment events, and order schema
- [x] 3.2 Implement checkout validation and server-side repricing
- [x] 3.3 Implement Paystack client (initialize, verify) and `POST /checkout`
- [x] 3.4 Implement signed Paystack webhook with idempotent event log
- [x] 3.5 Implement transactional order creation, numbering, and customer upsert
- [x] 3.6 Implement checkout status polling, retry, and reconciliation flags
- [x] 3.7 Add money-flow, webhook, and race-condition tests

## Phase 4 — Orders, Tracking, Refunds & CRM -> [tasks/phase_4_orders_refunds_crm.md](./tasks/phase_4_orders_refunds_crm.md)
- [x] 4.1 Implement `OrderStateMachine` and status events
- [x] 4.2 Implement public tracking and confirmation endpoints
- [x] 4.3 Implement cancellation-request endpoint and refund schema
- [x] 4.4 Implement admin order list, detail, transitions, notes, and delivery edit
- [x] 4.5 Implement manual paid orders
- [x] 4.6 Implement refund decisions, Paystack refunds, and manual completion
- [x] 4.7 Implement customers CRM and dashboard endpoints
- [x] 4.8 Add lifecycle, tracking, refund, and permission tests

## Phase 5 — Notifications (Resend & Arkesel) -> [tasks/phase_5_notifications.md](./tasks/phase_5_notifications.md)
- [x] 5.1 Add `notification_logs` and idempotent notification dispatcher
- [x] 5.2 Implement `SmsProvider`, `ArkeselSmsProvider`, and `LogSmsProvider`
- [x] 5.3 Configure Resend mailer and email templates
- [x] 5.4 Wire order, status, and refund events to notifications
- [x] 5.5 Implement contact form endpoint
- [x] 5.6 Add notification idempotency, retry, and fan-out tests

## Phase 6 — Catalogue Import & Scheduled Jobs -> [tasks/phase_6_import_scheduler.md](./tasks/phase_6_import_scheduler.md)
- [x] 6.1 Implement `catalog:import` dry-run report
- [x] 6.2 Implement import apply mode with snapshot and per-product transactions
- [x] 6.3 Implement `--images` matching through the resize pipeline
- [x] 6.4 Register scheduled jobs (expiry, re-verify, pruning, `PRAGMA optimize`)
- [x] 6.5 Add import and scheduler tests

## Phase 7 — Deployment, Backups & Launch -> [tasks/phase_7_deployment_launch.md](./tasks/phase_7_deployment_launch.md)
- [ ] 7.1 Provision Contabo VPS (Ubuntu 24.04, Nginx, PHP-FPM 8.4, UFW, fail2ban)
- [ ] 7.2 Configure Cloudflare DNS, origin certificate, and `/media` caching
- [ ] 7.3 Configure supervisor queue worker and scheduler cron
- [ ] 7.4 Configure Litestream and media backups to Backblaze B2
- [ ] 7.5 Implement zero-downtime GitHub Actions deploy and staging environment
- [ ] 7.6 Write runbooks and perform restore drill
- [ ] 7.7 Launch checklist: live keys, DNS email records, sender ID, uptime monitor

## Notes

| Date | Note |
|---|---|
| 2026-10-08 | Task index generated from `docs/product-spec.md` v1.0. Repository contains only `README.md` and docs; no Laravel code exists yet. Decisions locked: Laravel 12 + SQLite (WAL) on Contabo, Next.js on Vercel as render-only storefront and browser-only admin, Sanctum SPA cookie auth, admin image upload, Resend email, Arkesel SMS, Litestream to Backblaze B2. |
| 2026-10-08 | Phase 0 implemented: Laravel 12 / PHP 8.4, Sanctum, SQLite pragmas, module layout, API conventions, CORS/security headers, CI and OpenAPI. Verified 50 Pest tests (200 assertions), Pint, Larastan, Composer validation, and config/route caching. Opus 5.5 review addressed; Cloudflare client-IP handling tracked in 7.2. GitHub Actions repeats the validation on pushes and pull requests. |
| 2026-10-08 | Product Owner requested committing and pushing the verified Phase 0 implementation directly to `main`. |
| 2026-10-08 | Phase 1 implemented on `feat/phase-1-staff-auth-audit`: Sanctum staff auth, password/reset flows, role matrix, immediate session revocation, and append-only redacted audit. Verified 113 tests / 688 assertions, Pint, Larastan, Composer validation, and config/route caching. Opus 5.5 findings addressed; three deferred minors recorded in `docs/implementation/phase_1_review.md`. Additive migrations applied locally. |
| 2026-10-09 | Spec review follow-ups: added per-request JSON log line (method, route, status, `duration_ms`) via `App\Support\Http\RequestLog` (§8). Product Owner requested seeded dev accounts `admin@vidorckhair.com` (Admin) and `sales@vidorckhair.com` (Sales & Orders); `DatabaseSeeder` only runs in `local`/`testing`, so task 1.2's "no default credentials" still holds for staging/production. Passwords overridable via `SEED_ADMIN_PASSWORD` / `SEED_STAFF_PASSWORD`. |
| 2026-10-09 | Phase 2 implemented on `feat/phase-2-catalogue-media` with Luna subagents and Claude Opus 5.5 reviews: catalogue APIs, audited Admin CRUD, bundles, immutable media, and storefront refreshes. Verified 171 tests / 1089 assertions, Pint, Larastan, Composer, OpenAPI, migration/seeding, cacheability, and local HTTP. Additive migration and structural seed applied locally. See `docs/implementation/phase_2_progress.md` and `phase_2_review.md`. |
| 2026-10-10 | Phase 3 implemented on `feat/phase-3-checkout-paystack` using Luna subagents, read-only Claude Opus 5.5 advice, and 13 incremental commits. Verified 253 tests / 1846 assertions, Pint, Larastan, Composer, OpenAPI, isolated production seeding/cacheability, local HTTP, and three real two-process SQLite races. Additive migrations and delivery-only seeding applied locally. Paystack HTTP is faked in tests; no live payment initiated. See `docs/implementation/phase_3_progress.md` and `phase_3_review.md`. |
| 2026-10-10 | Phase 4 implemented on `feat/phase-4-orders-refunds-crm` using Claude Sonnet 5.5 subagents with Claude Opus 5.5 advisor review and incremental commits. Verified 323 tests / 2514 assertions, Pint and Larastan. Order state machine, public tracking, cancellation requests, admin orders, manual paid orders, refunds, CRM and dashboard added; additive migrations apply locally. Paystack HTTP is faked; no live refund made. Open questions (including manual-order/Paystack reference collision) are in `docs/implementation/phase_4_review.md`; see also `phase_4_progress.md`. |
| 2026-10-12 | Phase 5 implemented on `feat/phase-5-notifications` using Claude Sonnet 5.5 subagents with Claude Opus 5.5 advisor review, a Codex-drafted plan, and incremental commits. Verified 456 tests / 3152 assertions, Pint and Larastan. Append-only notification log/attempt tables, atomic dispatcher, queued jobs, Arkesel and Resend senders, order/status/refund notifications and the contact form added; additive migration applies locally. Provider HTTP is faked; no live email or SMS sent. All five acceptance tests are covered; Arkesel fixtures, the `VIDORCK` sender and Resend DNS remain Phase 7.7. See `docs/implementation/phase_5_progress.md` and `phase_5_review.md`. |
| 2026-10-13 | Phase 6 implemented on `feat/phase-6-import-scheduler` using Claude Sonnet 5.5 subagents, Claude Opus 5.5 advisor review, and Codex for the plan and three review passes (11 defects fixed). Verified 499 tests / 3388 assertions, Pint and Larastan. `catalog:import` (dry-run, apply with `VACUUM INTO` snapshot, image matching) and eight scheduled jobs added; additive migration applies locally. The Litestream check is a placeholder until 7.4. See `docs/implementation/phase_6_progress.md` and `phase_6_review.md`. |
