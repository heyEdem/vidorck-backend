# Phase 3 — Claude Opus 5.5 advice and review

Read-only preflight via Claude CLI using `claude-opus-5-5`; model confirmed in response metadata.

## Accepted rulings

- Add optional `expectedUnitPriceMinor` only for stale-price comparison. Unknown client money fields never affect totals. Report structured per-line issues with the standard error envelope.
- Bundle price is stored and authoritative; extras use current variant prices. Validate unique line identities and bundle-only parent links.
- Commit the attempt before initialization; keep all provider HTTP outside write transactions. Only verification GETs may automatically retry; avoid repeating monetary POSTs.
- Signed webhook uses the raw body and skips Sanctum stateful middleware. Persist the event and database queue job atomically before returning 200.
- Duplicate events with no processed timestamp may safely enqueue idempotent processing again. Store only allowlisted verification/event metadata.
- Claim the poll verification timestamp atomically under a short transaction; never hold SQLite write lock during HTTP.
- Late verified success still creates an order from the immutable original snapshot. Expiry does not erase a received payment.
- Re-read attempts inside the IMMEDIATE order transaction; uniqueness supplies a second guard. OrderPaid dispatches only after commit.
- Retry revalidates canonical area and catalogue prices, uses a fresh reference, and links the immediate parent. Mismatch cases require reconciliation rather than automatic repayment.
- A real concurrency regression must use isolated processes and a file-backed SQLite database. In-memory sequential calls alone cannot prove race handling.

## Scope decisions

- Phase 3 supplies durable webhook queueing and provider verification retries. Scheduled pending-attempt verification and expiry belong to Phase 6; notifications and their recovery belong to Phase 5.
- Phase 5 supplies the idempotent notification/outbox workflow and recovery for orders without confirmation notifications.
- Keep operation commits across layers, as requested by Edem; do not follow the advisory layer-by-layer commit suggestion.
- Keep additive payload fields backward compatible; no frontend files are modified in this repository.

## Implementation reviews

- Schema: independent Luna spec and quality review passed; all required unique keys exercised by failed raw inserts. Four initial schema tests / 26 assertions, Pint and Larastan passed. Final money-flow tests also exercise raw INSERT/UPDATE enum, GHS currency, nonnegative money, and positive quantity guards.

- Checkout POST: independent Luna spec/quality review passed. Added follow-up coverage for extras without their own line ID, unavailable products/variants/categories/bundle components, invalid parent relationships, quantity boundaries, direct phone validation, GET verification retries, and monetary POST retry prevention. Focused schema/checkout/OpenAPI suite: 17 tests / 122 assertions.

## Audit-instruction clarification

A second read-only `claude-opus-5-5` consultation addressed Edem's 2026-10-10 audit requirements. Order status history is an immutable audit table: application guards and SQLite triggers reject updates/deletes; Laravel SQLite truncate also performs a guarded DELETE. Existing staff audit history keeps its original guards. Payment events serve as an operational webhook inbox; received identity/payload facts must be immutable, deletion prohibited, and the processing timestamp may be recorded only once. This distinction preserves durable job bookkeeping without an audit correction or deletion path. Implemented and covered in the webhook operation.

- Paid-order creation: independent spec/quality review passed after fixing out-of-order CRM identity/timestamp handling. Verified outer-transaction event commit/rollback timing, rollback on injected item failure, immutable history, yearly sequences, snapshot totals, and extra-before-parent linking. Focused suite: 24 tests / 173 assertions; Pint and Larastan passed. The final money-flow operation also verifies the file-backed simultaneous-process webhook/poll race.

- Signed webhook: independent Luna spec/quality review passed. Raw HMAC, middleware exemption, durable receipt/job insertion, queue-failure rollback, duplicates, strict verification mismatches, late-success protection, unknown events, retryable provider errors, receipt guards, and allowlisting are covered. Focused suite: 34 tests / 257 assertions; Pint and Larastan passed. The review clarified that authoritative provider status mismatches are terminal MISMATCH cases, while transport/envelope errors remain retryable. Verification evidence is persisted before creation for orderless reconciliation; SUCCEEDED and all order writes remain atomic.

## Webhook review adjudication

A third read-only Opus 5.5 consultation confirmed the verification-evidence/paid-order transaction boundary and terminal status mismatch handling. It additionally recommended keeping provider in-progress states pending and recording mismatch resolution explicitly. These changes are included in the polling operation: no provider HTTP runs under a SQLite write lock, and immutable system audit events retain the initial mismatch and any later resolution.

- Status polling: independent Luna spec/quality review passed. Verified durable cooldown, late payment fallback, orderless evidence recovery, safe responses, independent IP limits, pending webhook retry, strict mismatch detection, and compensating audit references. Focused suite: 44 tests / 378 assertions; Pint and Larastan passed.

## Retry clarification

A fourth read-only `claude-opus-5-5` consultation recommends retrying only the final FAILED, ABANDONED, or expired PENDING attempt in a chain. Verify the parent outside a transaction before another initialization; provider uncertainty blocks a new attempt. Revalidate stored prices through `expectedUnitPriceMinor` rather than silently charging a changed total. A unique nullable parent key permits one child per attempt and prevents simultaneous retries from initializing two payments. Already-paid, mismatch, active pending, and already-retried attempts return specific 409 errors.

Paystack's [official error contract](https://paystack.com/docs/api/errors/) distinguishes absent resources (404) from provider failures (5xx). Only an explicit transaction-not-found response is eligible for the never-initialized retry path; generic HTTP/envelope failures remain unavailable. No live provider call is made during tests. An original session paid after verification/expiry and a subsequently paid child remain distinct payments; each is recorded once, with refund workflows reserved for Phase 4.

- Delivery-area listing: independent Luna spec/quality review passed. Exact 48-name order, active-only public fields, complete staff list, Sales access, and fresh-session denial covered. Four focused tests / 30 assertions; Pint and Larastan passed.

- Delivery-area creation: independent Luna spec/quality review passed. Fresh Admin checks, trimmed case-insensitive name/alias collision checks, safe defaults, audited rollback, and one storefront refresh covered. Reviewer transaction concern withdrawn after inspecting the configured IMMEDIATE Laravel SQLite boundary on PHP 8.4. Focused suite: 12 tests / 106 assertions; Pint and Larastan passed.

## Final money-flow clarification

A fifth read-only `claude-opus-5-5` consultation confirmed three completion fixes: system PAID creation must also insert a redacted `order.paid` audit event under product §6.12; rolling back the additive status-history guard must retain immutable-table protections with idempotent reapplication; and checkout status responses carrying tracking capabilities require `Cache-Control: no-store, private`. The final money-flow operation implements all three with regressions, without adding later-phase order APIs.

- Delivery-area editing: independent Luna spec/quality review passed. Partial updates use the fresh row, merged aliases remain unique, no-op edits add no audit/refresh, configured aliases work in guest checkout, and audit failure rolls back. Focused area/checkout/OpenAPI suite: 30 tests / 266 assertions; Pint and Larastan passed.

## Retry review adjudication

Independent Luna review caught an unsafe earlier Opus recommendation to permit retries while Paystack still reported an in-progress payment. Paystack's [official transaction states](https://paystack.com/docs/payments/verify-payments/) identify pending, ongoing, processing, and queued as unresolved payments. A further Opus 5.5 consultation reversed its recommendation. These states now block retry with CHECKOUT_PENDING regardless of local expiry; polling remains PENDING even after expiry. Only explicit abandoned/failed/reversed or the narrowly recognized missing transaction permit a locally eligible retry. Tests cover all four blocked states across FAILED, ABANDONED, and expired PENDING parents, plus expired polling.

- Checkout retry: independent Luna spec/quality review passed after this correction. Fresh parent-linked references, unchanged snapshots, price/availability revalidation, explicit not-found classification, provider uncertainty, payment success, terminal conflicts, and child uniqueness are covered. Focused Checkout/OpenAPI suite: 68 tests / 606 assertions; Pint and Larastan passed.

## Insert-only history writer clarification

A further read-only Opus 5.5 consultation confirmed that AuditLogger is the existing insert-only audit repository in this action-based architecture. New order status history now uses one RecordOrderStatusEvent action exposing append-only record(), with the immutable model and database guards providing enforcement. No generic repository hierarchy or history correction API is introduced.

- Admin reconciliation: independent Luna spec/quality review passed. Mismatches and verified-but-orderless attempts are paginated without raw customer/provider snapshots. ADMIN role, fresh-session protection, inclusion/exclusion, stable pagination, and read-only behavior covered. Complete Checkout/OpenAPI suite: 74 tests / 667 assertions; Pint and Larastan passed.

## Money-flow operation review

Independent Luna spec/quality review passed. The file-backed process harness
releases both webhook/poll verification calls and both retry verification calls
only after readiness barriers. It checks one order/customer summary/yearly
number/history/audit/after-commit event, one simultaneous poll verification,
and one retry initialization with a competing 409. Processes have bounded
timeouts and finally cleanup. The provider fake rejects HTTP inside a
transaction. Audit insertion failure rolls back all paid writes; received
verification evidence remains available to reconciliation.

Status guards remain after migration down/reapplication, real CSRF protection
is enabled while testing the exempt webhook, and three completed-event replays
do not create work or orders. A reviewer-requested assertion also covers
no-store headers on successful and conflicting retry responses. Checkout and
OpenAPI passed 79 tests / 741 assertions before the two added header assertions;
the focused retry test then passed 6 tests / 49 assertions. Pint and Larastan
passed. The full project check passed 252 tests / 1837 assertions.

## Final Opus review

Read-only final review via `claude-opus-5-5`, confirmed in response metadata,
returned PASS with no required financial correctness defect. It reviewed
pricing, verification, durable receipt/job insertion, order atomicity, audit
immutability, permissions, cooldown, retry, and the process harness.

The review also found that a sparse/object-shaped `items` payload can reach
positional parent validation and return 500. Although classified as optional
by the adviser, it is a concrete invalid-request defect; a separate validation
operation now enforces the documented list contract and returns 422 before
initialization. Luna reproduced the sparse-key bundle/extra request returning
500 with an undefined `unitPriceMinor`, then added the `list` rule and verified
422 on `items`, no attempt, and no provider call. Checkout/OpenAPI then passed
80 tests / 750 assertions, Pint, and Larastan; independent Luna spec/quality
review passed. Optional handling of externally corrupted stored evidence and
unique-job suppression are not added: normal writes already validate evidence,
and idempotent requeueing preserves recovery of unfinished webhook receipts.

Final whole-project verification after the validation fix: `composer check`
passed Pint, Larastan level 6, and **253 tests / 1846 assertions**. Composer
strict validation, OpenAPI route/reference checks, isolated production
migration/seeding and config/route caching, local HTTP smoke checks, and
additive local setup also passed. No live Paystack transaction was initiated.
