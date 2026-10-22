# Phase 5 — Notifications progress

Plan: `docs/tasks/phase_5_notifications.md` and `docs/implementation/phase_5_plan.md`.
Authority: `docs/product-spec.md` §§6.6, 6.9 and the frontend `website-spec.md` §§14.3, 14.5, 18.2, 18.3.
Branch: `feat/phase-5-notifications`.
Implementation: Claude Sonnet 5.5 subagents. Advice and review: Claude Opus 5.5 (advisor). Plan drafted by Codex.
Workflow: operation commits, each verified with Pint, Larastan and tests.

## Operation ledger

| Commit | Plan task | Summary |
|---|---|---|
| `438b942` | plan | Phase 5 implementation plan |
| `803ae13` | 5.1 | Notification log/attempt schema, append-only models, `NotificationDispatcher`, `SendNotification`, staging allowlist |
| `f020a15` | 5.1 | Test: `QueryException` import in the trigger test |
| `720b857` | 5.2 | `SmsProvider`, Arkesel and log drivers, `SmsMessage`, staff alert messages |
| `4b6c576` | 5.3 | Resend sender, mailables and HTML/text templates; `config/resend.php` with `routes=false` |
| `29ee8b8` | 5.4 | Order, status and refund notification planners wired into Orders actions |
| `888907f` | 5.4 | Fix: atomic dispatch, priced child lines, in-flight claim release, staging-only allowlist |
| `d640fe5` (amended) | 5.5 | `POST /api/v1/contact`, `contact-submissions` limiter |

Task 5.6 (tests) was written alongside each operation; files are listed under Tests.

## Endpoint

| Route | Notes |
|---|---|
| `POST /api/v1/contact` | name, email, optional phone, message, hidden `website` honeypot; limiter `contact-submissions` (3 per 10 minutes per IP) |

Order of checks: rate limiter, honeypot (normal 200, nothing logged or queued, payload not
validated), validation, `CONTACT_EMAIL` check (503 `SERVICE_UNAVAILABLE` when unset or invalid),
then `once()` outside any business transaction keyed `contact-relay:{uuid}:EMAIL:{hash}`.
A dispatch failure returns 503. No contact record is stored. The route is in `docs/openapi.yaml`.

## Schema

Migration `2026_10_12_090000_create_notification_tables` (additive):

- `notification_logs`: immutable intent. Unique `idempotency_key`, channel, template,
  `recipient_masked`, `recipient_hash` (sha256 of the normalised recipient), source type/id.
- `notification_attempts`: immutable facts, event STARTED / SENT / FAILED / EXHAUSTED / SKIPPED,
  provider message id, sanitised error summary. Unique `(log_id, attempt_number, event_type)`:
  the STARTED row is the claim. Current status is the latest attempt.
- Both tables reject UPDATE, DELETE and TRUNCATE by trigger; models use insert-only repositories.

## Implementation decisions

- `once()` runs its own (nested, savepoint) transaction, so it is safe inside a caller's
  transaction and a failed queue push also removes the log row and burns no key.
- Planners (`OrderNotificationPlanner`, `RefundNotificationPlanner`) log by class only and
  swallow planning errors, so a notification problem never fails an order, status change or refund.
- Jobs are encrypted, one per recipient, 4 tries, backoff 60/300/900s, 30s timeout. Permanent
  errors (invalid recipient or credentials, Resend 422) fail immediately; `failed()` is idempotent.
- Email goes through the stock Resend transport; the package webhook route is disabled
  (`config/resend.php` `routes=false`). Mail variables are whitelisted in `NotificationMailable::vars()`.
- SMS goes to staff only; messages use the GSM-7 basic charset and stay within 306 characters,
  ending in "+N more" when the item list is cut.
- The staging allowlist applies only outside production and records a SKIPPED attempt.
- Customer emails never carry refund reasons, provider references or courier details.
- `failedNotificationCount` counts notifications whose latest attempt is FAILED or EXHAUSTED.

## Tests

`tests/Feature/Notifications/`: `NotificationDispatchTest`, `SmsTest`, `MailTest`,
`OrderNotificationTest`, `RefundNotificationTest`, `ContactTest`; helpers in
`NotificationTestHelpers.php`. `DashboardTest` covers the failed count. OpenAPI route parity
runs in the regular suite.

## Verification

Phase 5 branch state: Pint and Larastan clean, **456 tests / 3152 assertions** (Phase 4
closed at 323 / 2514). No live Resend or Arkesel call was made; providers are faked.
All five acceptance tests are covered.
Open items are in `docs/implementation/phase_5_review.md`.
