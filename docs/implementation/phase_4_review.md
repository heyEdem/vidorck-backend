# Phase 4 — Claude Opus 5.5 advice and review

Read-only advisor review by Claude Opus 5.5 over Sonnet 5.5 subagent implementation.
No required defect remains; the items below need a product decision, an external
confirmation or later-phase work.

## Open Questions / Follow-ups

b) **Paystack refund webhook fields are unverified.** `transaction_reference` and
   `refund_reference` are assumed from documentation and have not been checked against
   a real refund event; `refund.needs-attention` is not handled. Add to the Phase 7
   launch checklist (`docs/tasks/phase_7_deployment_launch.md`, 7.7): perform a real
   low-value refund and confirm the payload fields.

c) **No retry for FAILED refunds.** A FAILED request cannot be retried; the customer
   must submit a new request. `refundReconciliationCount` (provider-confirmed refunds
   whose order could not move to REFUNDED) has no resolution path yet.

g) **Dashboard totals.** Paid orders today and sales today exclude CANCELLED and
   REFUNDED orders; the spec is silent.

h) **Dispatch guard.** Open cancellation requests (REQUESTED, UNDER_REVIEW) do not block
   dispatch; APPROVED and PROCESSING refunds do.

i) **Refund transaction statuses.** `PENDING`, `PROCESSED` and `FAILED` were chosen
   because the spec gives no values.

j) **502/503 refund outcomes** are returned through `HttpResponseException` because
   `ApiException` supports 4xx only.

k) **Write-guard limits.** `OrderStatusWriteGuardTest` is a heuristic source scan, and the
   model guard does not cover raw query-builder writes. The state machine remains the
   only intended writer.

## Resolved follow-ups

- **Manual order with a Paystack reference (was a).** A manual order links the
  orderless attempt with the same reference (case-insensitive) inside its
  transaction and marks it SUCCEEDED with an audit row. An amount mismatch
  between the Paystack amount (verification snapshot, else the attempt amount) and
  the manual amount does not link: the attempt becomes MISMATCH and stays on the
  reconciliation list. Late webhooks, polls and `CreateOrderFromAttempt` return the
  existing order cleanly.
- **Delivery area and timeline (was f).** Areas are free text, canonicalised to a
  matching `delivery_areas.name`. The customer timeline shows the effective path
  only; admin history stays full.

- **Failed notification count (was d).** `failedNotificationCount` now counts
  notifications whose latest attempt is FAILED or EXHAUSTED
  (`CountFailedNotifications`, Phase 5).
- **Phase 5 listeners (was e).** Cancellation requests send the customer
  acknowledgement email and a staff SMS (no reason text). Manual paid orders alert
  staff like online orders (plan default 1; the staff SMS is not skipped).

## Review notes

- Money-moving provider calls follow the Phase 3 rule: no HTTP inside a write
  transaction and no automatic retry of a monetary POST.
- Refund history, notes and transactions follow the audit rule: insert-only, with
  database triggers; no edit or delete path exists.

## Refund approval has no undo

An approved refund on a manual order leaves APPROVED only through manual completion, and it blocks dispatch until then. A mistaken approval cannot be reversed. Online refunds adopt or re-send through the retry endpoint, which looks up existing Paystack refunds first. The refund list filter name (`transaction` vs `reference`) and the response schema are unconfirmed and need one live call.
