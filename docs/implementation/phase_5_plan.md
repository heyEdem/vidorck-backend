# Phase 5 — Notifications: Implementation Plan

**Status:** Draft for Product Owner approval · **Date:** 2026-10-11
**Source:** Codex plan, trimmed with Opus 5.5 advisor review; Codex raised no correctness objections to the cuts.
**Tasks:** [phase_5_notifications.md](../tasks/phase_5_notifications.md) · product-spec §6.6, §6.9 · website-spec §14.3, §14.5, §18.2

## Verified facts that shape the design

- The database queue uses the default SQLite connection (`DB_QUEUE_CONNECTION` unset), so a job row inserted inside a business transaction commits atomically with it. No outbox is needed.
- SMS goes to staff only (product-spec §6.9), so a duplicate SMS after an ambiguous timeout is harmless. Retry automatically.
- `resend/resend-laravel` is not yet in `composer.json`.

## Design

- **Atomic dispatch:** `NotificationDispatcher::once(key, channel, recipient, payload)` is called **inside** the existing business transactions (order creation, manual order, state machine, cancellation request, refund decision/webhook/manual completion). It inserts the `notification_logs` row and an encrypted queued job together; a duplicate `idempotency_key` is a no-op. Provider calls always happen in the job, never in a request transaction.
- **Append-only records** (user rule): `notification_logs` (immutable intent: unique `idempotency_key`, channel, template, `recipient_masked`, `recipient_hash` = sha256 of normalised recipient, source refs) and `notification_attempts` (immutable facts: STARTED / SENT / FAILED / EXHAUSTED / SKIPPED, provider id, sanitised error). UPDATE/DELETE triggers on both. Unique `(log_id, attempt_number, event_type)` makes the STARTED row the claim; current status = latest attempt. No raw recipients, bodies or provider responses stored.
- **Keys** per occurrence, channel and recipient, e.g. `order-status:{number}:{targetStatus}:EMAIL:{hash}`. One email per status per order; Admin corrections send nothing and revisits don't resend. Cancellation and refund keys use the refund-request id.
- **Jobs:** encrypted, one per recipient, 4 tries, backoff 60/300/900s, 30s timeout. Permanent provider errors (invalid recipient/credentials) fail immediately. `failed()` is idempotent.
- **Content:** frozen snapshot DTOs (order items, customer and delivery snapshots), never live models. Customer emails exclude reasons, provider references and courier details. Manual orders without email are SKIPPED, not failed.
- **Staging allowlist** enforced before any provider call; a suppressed send is recorded SKIPPED.

## Tasks (same order as Phase 4)

| # | Work | Notes |
|---|---|---|
| 5.1 | Schema, append-only models/repositories, `NotificationDispatcher`, jobs, staging allowlist | Runs alone first |
| 5.2 | `SmsProvider`, `ArkeselSmsProvider` (`/api/v2/sms/send`, `api-key` header, 3s connect / 10s timeout, business-error on HTTP 200), `LogSmsProvider`, `SmsMessage` (GSM-7 basic charset, ≤306 chars, "+N more") | After 5.1 |
| 5.3 | Add `resend/resend-laravel`; Mailables + HTML/text Blade templates (confirmation with all §14.3 fields, ready/dispatched/delivered, cancellation received, refund approved/rejected/completed/failed, staff new order, contact relay) | After 5.1; stock Resend transport |
| 5.4 | Wire notifications into Orders actions inside their transactions; add refund notification points | Touches the Orders refund actions; after 5.2/5.3 |
| 5.5 | `POST /api/v1/contact`: honeypot (200, nothing queued), `contact-submissions` limiter 3 per 10 min per IP, validation, one relay email, no stored contact record | After 5.1 |
| 5.6 | Tests per task, then integration: idempotency, retry/backoff, fan-out (3 staff numbers, one fails), privacy, append-only triggers; dashboard `failedNotificationCount` counts notifications whose latest attempt is FAILED/EXHAUSTED | Throughout |

### Recipient wiring

| Event | Customer | Staff |
|---|---|---|
| Order paid (online and manual) | Confirmation email + SMS | — |
| → READY_FOR_DISPATCH / DISPATCHED / DELIVERED (forward only) | Milestone email + SMS | — |
| PROCESSING, corrections | — | — |
| Cancellation requested | Acknowledgement email + SMS ("this does not cancel your order") | — |
| Refund approved / rejected | Email | — |
| Refund failed | Email only when the conditional update actually set FAILED; none on unknown outcome (503) | — |
| Refund completed | Email only after confirmed amount and a successful REFUNDED transition; none on reconciliation conflict | — |
| Refund retry | No second approval email | — |

## Accepted risks

- Duplicate customer email if Resend times out ambiguously and the job retries.
- Duplicate staff SMS after an ambiguous Arkesel timeout.
- Temporary encrypted contact content in the jobs/failed-jobs tables until Phase 6.4 pruning.

## Deferred

- Multi-process notification race test and failed-job retention → Phase 6.4.
- Arkesel account response fixtures, transactional `VIDORCK` sender, Resend domain SPF/DKIM/DMARC → Phase 7.7.

## Defaults (override any in one line)

1. Manual paid orders alert staff like online orders (§6.9).
2. Refund reconciliation problems show on the dashboard only, no staff SMS.
3. Contact relay Reply-To is the submitter, so staff can reply directly.
4. Inboxes and recipients come from env vars (`CONTACT_EMAIL`, `MAIL_REPLY_TO_ADDRESS`), set before launch.
5. CANCELLED is never reached in the current flow, so there is no cancellation-confirmed email.
