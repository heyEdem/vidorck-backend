# Phase 5 — Claude Opus 5.5 advice and review

Read-only advisor review by Claude Opus 5.5 over Sonnet 5.5 subagent implementation of a
Codex-drafted plan. No required defect remains; the items below record deviations,
accepted risks and later-phase work.

## Deviations and findings

a) **Two-table append-only design supersedes the task-file sketch.** Task 5.1 described a
   single `notification_logs` row with status, attempts and last error. That row would be
   mutable, which breaks the audit rule, so intent lives in `notification_logs` and every
   try in `notification_attempts`; status is derived from the latest attempt.

b) **Commit `720b857` does not pass `OpenApiTest` on its own.** Installing the Resend
   package registered its webhook route, which is not in `docs/openapi.yaml`. Commit
   `4b6c576` fixed it with `config/resend.php` (`routes=false`). Bisecting across `720b857`
   will show this failure.

c) **Resend 422 is treated as permanent.** A validation error from Resend will not succeed
   on retry, so the job fails at once instead of using its four tries.

d) **Planners swallow notification failures by design.** A planning error is logged by class
   and skipped so it cannot fail an order, status change or refund. `once()` is atomic via
   its own savepoint, so a failed push leaves no log row and no key burned. The cost is that
   a failed enqueue is only visible in the log, not retried.

e) **In-flight claim `release(65)` consumes a try.** When a job finds a fresh STARTED claim
   it releases itself for 65 seconds. Laravel counts that as an attempt, so a long-running
   worker can use up retries. `IN_FLIGHT_SECONDS` is kept below the queue `retry_after`.

f) **`migrate:rollback` of `order_items` is blocked by Phase 3 schema.** The self-referencing
   `parent_item_id` foreign key stops the table drop; the notification test that needs a
   clean teardown runs `PRAGMA foreign_keys = OFF`. This is a pre-existing Phase 3 issue,
   not caused by Phase 5, and is not fixed here.

g) **Contact config key.** `CONTACT_EMAIL` is read from `services.notifications.contact_email`.
   An unset or invalid value returns 503 and is never swallowed.

## Acceptance coverage

| Test | Status |
|---|---|
| `order_paid_notifies_once` | Covered (finalising twice sends once; staff fan-out) |
| `sms_fits_two_segments` | Covered (12 items, at most 306 characters, "+N more") |
| `contact_form_is_rate_limited` | Covered (4th request is 429 with `Retry-After`) |
| `sms_failure_isolated_per_recipient` | Covered (3 staff numbers, one failing: two SENT, one FAILED, independent) |
| `notification_failure_does_not_roll_back_status` | Covered (transient email failure after DISPATCHED: status and event persist, FAILED recorded, retry sends) |

## Accepted risks

- Duplicate customer email if Resend times out ambiguously and the job retries.
- Duplicate staff SMS after an ambiguous Arkesel timeout (staff only, harmless).
- Contact content sits encrypted in the jobs and failed-jobs tables until Phase 6.4 pruning.

## Deferred

- Multi-process notification race test and failed-job retention: Phase 6.4.
- Arkesel account response fixtures, transactional `VIDORCK` sender and Resend domain
  SPF/DKIM/DMARC: Phase 7.7 (added to the launch checklist).

## Review notes

- Provider calls happen only in queued jobs, never in a request or business transaction.
- Notification logs and attempts follow the audit rule: insert-only with triggers; no edit
  or delete path exists. Raw recipients, bodies and provider responses are never stored.
- Resolved Phase 4 items d) and e) are recorded in `phase_4_review.md`.

## Final verification notes (2026-10-12)

- Smoke-tested the real stack on a copy of the local database: all migrations applied over existing Phase 3–4 data, and one email (log mailer, Blade render, encrypted job) and one SMS (log driver) each recorded STARTED → SENT. No message text was found in the database.
- `composer validate --strict`, `config:cache` and `route:cache` pass; `route:list` shows `POST api/v1/contact` and no Resend route.
- `failedNotificationCount` counts notifications whose latest attempt is FAILED or EXHAUSTED, so it can rise and fall while a transient failure waits for its retry.
- Orders created before Phase 5 have no notification rows. There is no backfill; nothing is in production yet.
