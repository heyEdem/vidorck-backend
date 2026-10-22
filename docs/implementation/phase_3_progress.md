# Phase 3 — Checkout & Paystack progress

Plan: `docs/tasks/phase_3_checkout_paystack.md`. Authority: `docs/product-spec.md`
and the frontend `website-spec.md` §§10–14.
Branch: `feat/phase-3-checkout-paystack`.
Implementation: Luna subagents. Clarification and reviews: Claude Opus 5.5.
Workflow: autonomous execution with verified incremental operation commits.

## Operation ledger

| Operation | Plan task | Status |
|---|---|---|
| Payment/order schema and structural delivery seeding | 3.1 | Complete: 4 tests / 26 assertions, Pint, Larastan |
| List public and Admin delivery areas | 3.1 | Complete: 4 focused tests / 30 assertions, Pint, Larastan |
| Create an Admin delivery area | 3.1 | Complete: 12 focused tests / 106 assertions, Pint, Larastan |
| Update an Admin delivery area | 3.1 | Complete: 30 focused tests / 266 assertions, Pint, Larastan |
| Validate, reprice, and initialize guest checkout | 3.2–3.3 | Complete: 17 focused tests / 122 assertions, Pint, Larastan |
| Create one paid order from a verified attempt | 3.5 | Complete: 24 focused tests / 173 assertions, Pint, Larastan |
| Receive and process signed Paystack webhooks | 3.4 | Complete: 34 focused tests / 257 assertions, Pint, Larastan |
| Poll checkout status and recover late webhooks | 3.6 | Complete: 44 focused tests / 378 assertions, Pint, Larastan |
| Retry checkout with a fresh validated attempt | 3.6 | Complete: 68 focused tests / 606 assertions, Pint, Larastan |
| List Admin payment reconciliation cases | 3.6 | Complete: 74 focused tests / 667 assertions, Pint, Larastan |
| Money-flow acceptance tests and audit hardening | 3.7 | Complete: 79 Checkout/OpenAPI tests / 741 assertions, Pint, Larastan; 3 real process races |
| Reject non-list cart payloads before initialization | 3.2, 3.7 | Complete: 80 Checkout/OpenAPI tests / 750 assertions, Pint, Larastan |

## Baseline

`composer check` passed before implementation: Pint, Larastan level 6,
174 tests / 1097 assertions. Working tree was clean at `ee83c1a`.

## Implementation decisions

- Preserve modular Laravel action/request/resource conventions and camelCase
  API fields. Financial values remain integer pesewas in GHS.
- Keep Paystack HTTP calls outside SQLite write transactions. Order creation
  uses the configured IMMEDIATE transaction mode and database uniqueness.
- New financial relationships restrict deletion. Notifications remain Phase 5;
  Phase 3 emits an after-commit `OrderPaid` event.
- Check Paystack's official transaction, webhook, and refund documentation;
  record external advice and any contract decisions in the review document.

## Verification

Final `composer check` passed: Pint, Larastan level 6, **253 tests / 1846 assertions**
(69.77 seconds). Checkout and OpenAPI: **80 tests / 750 assertions**.
Composer strict validation and `git diff --check` passed.

`CheckoutConcurrencyTest.php` proves three real process races (**3 tests /
22 assertions**): webhook/poll finalization writes one order/customer summary/
number/paid history/audit/event; simultaneous polls claim one verification;
simultaneous retries create one child and initialize once. Provider fakes check
that HTTP runs outside a transaction. Both finalization/retry verifications
rendezvous before release; temporary databases, processes, and files are cleaned.

Completed setup checks: isolated production migration and structural seeding
twice, preserved Admin area edits, no development staff/catalogue data seeded,
config/route caching, all 240 local OpenAPI references resolved, and local HTTP
health/areas/validation/signature/auth/cache checks. Applied additive migrations
and the delivery-area seeder locally without changing existing staff,
catalogue, or audit record counts. No live Paystack transaction was initiated.

All Phase 3 tasks and acceptance tests are complete. Implementation retained
the existing action/request/resource/enum conventions. Independent Luna reviews
and final read-only Claude Opus 5.5 review passed; the sparse-cart finding was
fixed in its own operation. Work is recorded in 13 incremental commits on the
feature branch, including the final setup/evidence documentation.
