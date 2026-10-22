# Phase 5 — Notifications (Resend & Arkesel)

**Goal:** Send every customer email and staff SMS through queued, idempotent jobs, so a notification is never duplicated and never blocks an order or status change.
**Estimate:** ~3-4 days · **Depends on:** Phases 3 and 4 (domain events `OrderPaid`, `OrderStatusChanged`, `CancellationRequested`, refund events).
**Source:** [§6.6 Contact form](../product-spec.md#66-contact-form) · [§6.9 Notifications](../product-spec.md#69-notifications) · `website-spec.md` §14.3, §14.5, §18.2, §18.3

## Current Code Audit

- [x] Domain events were fired by Phases 3–4 with no listeners and no mailer, SMS provider or notification log; Phase 5 added all three.

## Tasks

### 5.1 Add notification log and dispatcher
- Migration for `notification_logs` (`idempotency_key` unique, channel, template, masked recipient, provider message ID, status, attempts, last error).
- `NotificationDispatcher::once(key, channel, recipient, job)` inserts the log row first and skips on unique conflict.
- Files: `database/migrations/`, `app/Modules/Notifications/Models/NotificationLog.php`, `app/Modules/Notifications/NotificationDispatcher.php`
- Done when: dispatching the same key twice queues one job.

### 5.2 Implement SMS providers
- `SmsProvider` interface (`send(string $toE164, string $message): SmsResult`).
- `ArkeselSmsProvider` (API key + sender `VIDORCK` from config, HTTP client with timeout), `LogSmsProvider` for local/staging; bound by `SMS_DRIVER`.
- `SmsMessage` helper keeps messages within 306 GSM-7 characters, truncating item lists with "+N more".
- Files: `app/Modules/Notifications/Sms/`, `config/services.php`
- Done when: switching `SMS_DRIVER=log` sends nothing externally.

### 5.3 Configure Resend and templates
- Install `resend/resend-laravel`; `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS=noreply@<domain>`, `MAIL_REPLY_TO` = support inbox.
- Mailables with HTML + plain text: order confirmation, ready for dispatch, dispatched, delivered, cancellation received, refund approved, rejected, completed, failed, contact relay, staff new-order alert.
- Every customer email includes the courier-fee note where `website-spec.md` requires it.
- Files: `app/Modules/Notifications/Mail/`, `resources/views/mail/`
- Done when: each template renders in a snapshot test with no missing variables.

### 5.4 Wire events to notifications
- `OrderPaid` → customer confirmation email + customer SMS. Staff get no alerts.
- `OrderStatusChanged` → customer email + SMS for Ready for Dispatch, Dispatched, Delivered.
- Cancellation and refund events → matching customer emails and SMS.
- Jobs retry 3 times with 1, 5, 15 minute backoff, then mark the log `FAILED`.
- Files: `app/Modules/Notifications/Listeners/`, `app/Modules/Notifications/Jobs/`
- Done when: a failing Arkesel call for one staff number still delivers to the others.

### 5.5 Implement contact form
- `POST contact` (name, email, optional phone, message, honeypot) rate-limited 3 per 10 minutes per IP; queues the contact relay email; stores nothing but the log.
- Files: `app/Modules/Notifications/Http/Controllers/ContactController.php`
- Done when: a filled honeypot returns 200 but sends nothing.

### 5.6 Add notification tests
- Use `Mail::fake`, `Queue::fake`, and `Http::fake` for Arkesel.
- Files: `tests/Feature/Notifications/**`
- Done when: the acceptance tests below pass.

## Acceptance Tests

- [x] **order_paid_notifies_once:** Given `OrderPaid` fired twice for the same order, when listeners run, then one confirmation email and one SMS per staff number are sent.
- [x] **sms_failure_isolated_per_recipient:** Given 3 staff numbers and Arkesel failing for one, when the alert runs, then 2 SMS succeed and 1 log is retried.
- [x] **notification_failure_does_not_roll_back_status:** Given Resend returning 500, when an order moves to `DISPATCHED`, then the status change persists.
- [x] **sms_fits_two_segments:** Given an order with 12 items, when the staff SMS is built, then it is ≤ 306 characters and ends with "+N more".
- [x] **contact_form_is_rate_limited:** Given 3 submissions in 10 minutes from one IP, when a 4th is sent, then the response is 429.

## Verification

- `./vendor/bin/pest --filter='Notification|Sms|Mail|Contact'`
- `php artisan test`
