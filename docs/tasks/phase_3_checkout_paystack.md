# Phase 3 — Checkout & Paystack

**Goal:** Run guest checkout end to end: validate and reprice the cart on the server, initialise Paystack, verify payment server-to-server, and create exactly one paid order per verified payment.
**Estimate:** ~1 week · **Depends on:** Phase 2.
**Source:** [§5.2 Checkout and payment](../product-spec.md#52-checkout-and-payment) · [§6.3 Checkout](../product-spec.md#63-checkout) · [§6.4 Paystack webhook](../product-spec.md#64-paystack-webhook) · `website-spec.md` §11–13, §14.1

## Current Code Audit

- [x] Implemented and verified on 2026-10-10. Payload validation and the exact 48 delivery areas follow `VHI_Website/src/lib/checkout/validation.ts` and `src/lib/delivery/areas.ts`. Order tracking, lifecycle/refunds, notifications, and scheduled recovery remain Phases 4–6.

## Tasks

### 3.1 Add checkout and order schema
- Migrations for `delivery_areas`, `checkout_attempts`, `payment_events`, `order_number_sequences`, `customers`, `orders`, `order_items`, `order_status_events`.
- Unique constraints: `checkout_attempts.paystack_reference`, `orders.checkout_attempt_id`, `orders.payment_reference`, `orders.order_number`, `payment_events(provider, provider_event_id)`, `customers.phone_e164`.
- Seed the 48 delivery areas from `website-spec.md` §12.2; add `GET delivery-areas` and admin `GET/POST/PATCH admin/delivery-areas`.
- Files: `database/migrations/`, `app/Modules/Checkout/Models/`, `app/Modules/Orders/Models/`, `app/Modules/Customers/Models/`
- Done when: all unique constraints are covered by a schema test.

### 3.2 Implement checkout validation and repricing
- `CheckoutRequest` mirrors the existing `checkoutSchema` (Ghana phone regex, GhanaPost GPS regex, `policyAccepted`, items 1–20 each).
- `PhoneNumber` support class normalises to E.164 and keeps the display value.
- `PriceCart` action re-reads every product, variant, bundle, and extra; returns per-line `ITEM_UNAVAILABLE` / `PRICE_CHANGED` issues.
- Files: `app/Modules/Checkout/Http/Requests/CheckoutRequest.php`, `app/Modules/Checkout/Actions/PriceCart.php`, `app/Support/PhoneNumber.php`
- Done when: the server total never depends on any client-supplied price.

### 3.3 Implement Paystack client and `POST /checkout`
- Typed `PaystackClient` (`initialize`, `verify`, `refund`) using the HTTP client with timeouts and retries; keys from config only.
- `POST checkout` creates a 60-minute `checkout_attempts` row, initialises Paystack in pesewas, `GHS`, channels `mobile_money` + `card`, and returns `{ authorizationUrl, reference }`.
- Files: `app/Modules/Checkout/Paystack/PaystackClient.php`, `config/services.php`, `CheckoutController.php`
- Done when: a faked Paystack response produces a stored attempt and a returned authorization URL.

### 3.4 Implement signed webhook
- `POST webhooks/paystack` reads the raw body and verifies HMAC-SHA512 against `x-paystack-signature` with `hash_equals`; returns 401 on failure.
- Insert `payment_events` first; a duplicate returns 200 immediately.
- For `charge.success`, verify via API and compare reference, status, currency, and amount; mismatches mark the attempt `MISMATCH`.
- Exclude the route from CSRF and session middleware.
- Files: `app/Modules/Checkout/Http/Controllers/PaystackWebhookController.php`, `app/Modules/Checkout/Actions/HandleChargeSuccess.php`
- Done when: the webhook responds in under 5 seconds with all slow work queued.

### 3.5 Implement order creation
- `CreateOrderFromAttempt` runs in one `BEGIN IMMEDIATE` transaction: mark attempt `SUCCEEDED`, allocate `VHI-{year}-{000000}` from `order_number_sequences`, upsert customer by `phone_e164`, write order + item snapshots + `PAID` status event, generate `tracking_token`.
- Fire an `OrderPaid` domain event after commit (notifications attach in Phase 5).
- Files: `app/Modules/Orders/Actions/CreateOrderFromAttempt.php`, `app/Modules/Orders/Actions/NextOrderNumber.php`, `app/Modules/Customers/Actions/UpsertCustomer.php`
- Done when: two concurrent calls for the same attempt produce one order.

### 3.6 Implement status polling, retry, and reconciliation
- `GET checkout/{reference}` returns `PENDING` / `SUCCEEDED` (with `orderNumber`, `trackingToken`) / `FAILED`; re-verifies with Paystack at most once per 10 seconds after the first 10 seconds.
- `POST checkout/{reference}/retry` re-validates and creates a child attempt with a new reference.
- `GET admin/payments/reconciliation` (ADMIN) lists `MISMATCH` and verified-but-orderless attempts.
- Files: `CheckoutStatusController.php`, `app/Modules/Checkout/Actions/RetryCheckout.php`, `ReconciliationController.php`
- Done when: polling can create the order when the webhook is late, using the same action as the webhook.

### 3.7 Add money-flow tests
- Use `Http::fake` for Paystack; cover signatures, duplicates, mismatches, races, repricing, and retries.
- Files: `tests/Feature/Checkout/**`, `tests/Fixtures/paystack/`
- Done when: the acceptance tests below pass.

## Acceptance Tests

- [x] **client_price_is_ignored:** Given a cart payload with a tampered price field, when checkout is posted, then the attempt amount equals the server-calculated total.
- [x] **unaccepted_area_blocks_checkout:** Given area `Tema`, when checkout is posted, then the response is 422 on `area` and no Paystack call is made.
- [x] **invalid_signature_is_rejected:** Given a webhook with a bad `x-paystack-signature`, when posted, then the response is 401 and no state changes.
- [x] **duplicate_webhook_creates_one_order:** Given a processed `charge.success`, when the same event arrives 3 more times, then exactly one order exists.
- [x] **webhook_and_poll_race_creates_one_order:** Given a webhook and a status poll verifying the same reference at the same time, when both finish, then exactly one order exists.
- [x] **amount_mismatch_creates_no_order:** Given Paystack verify returns a lower amount, when the webhook is processed, then the attempt is `MISMATCH` and no order exists.
- [x] **order_numbers_are_sequential_per_year:** Given two paid attempts, when orders are created, then numbers are `VHI-2026-000001` and `VHI-2026-000002`.

## Verification

- `composer check`: Pint, Larastan level 6, **253 tests / 1846 assertions**.
- `./vendor/bin/pest tests/Feature/Checkout tests/Feature/OpenApiTest.php`: **80 tests / 750 assertions**.
- `CheckoutConcurrencyTest.php`: independent PHP processes sharing an isolated SQLite file; webhook/poll race, simultaneous poll cooldown, and simultaneous retry initialization (**3 tests / 22 assertions**).
- Composer validation, OpenAPI route parity/local references, isolated production migration/seeding and config/route caching, local HTTP smoke checks, and additive local setup passed. Paystack is faked; no live payment was initiated.
- Implementation decisions and reviews: [progress](../implementation/phase_3_progress.md), [review](../implementation/phase_3_review.md).
