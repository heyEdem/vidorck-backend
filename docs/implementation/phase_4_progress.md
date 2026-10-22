# Phase 4 — Orders, Tracking, Refunds & CRM progress

Plan: `docs/tasks/phase_4_orders_refunds_crm.md`. Authority: `docs/product-spec.md`
§§5.3, 5.4, 6.5, 6.8 and the frontend `website-spec.md` §§14–17.
Branch: `feat/phase-4-orders-refunds-crm`.
Implementation: Claude Sonnet 5.5 subagents. Advice and review: Claude Opus 5.5 (advisor).
Workflow: operation commits, each verified with Pint, Larastan and tests.

## Operation ledger

| Commit | Plan task | Summary |
|---|---|---|
| `bced768` | 4.1 | `OrderStatus` extended; `OrderStateMachine`, `OrderStatusChanged` |
| `2a5221c` | 4.1 | Test: a stale-instance transition leaves exactly one event row |
| `17ab6b6` | 4.3 | Refund/note schema, `RequestCancellation`, `CancellationRequested` |
| `eab6e9d` | 4.2 | Public tracking and confirmation, customer resources, rate limiters |
| `55ff5ec` | 4.4 | Admin order list/detail/transition/notes/delivery |
| `fd4c8e7` | 4.5 | Manual paid orders; shared `InsertOrderItems` |
| `77d6e2b` | 4.7 | Customer CRM and dashboard |
| `19cf860` | 4.6 | Refund decisions, Paystack refund, webhook handling, dispatch guard, manual completion |
| `5812af0` | 4.6 | Refund webhooks dedupe by refund reference; admin refund retry; refund reconciliation count |

Task 4.8 (lifecycle, tracking, refund, CRM, dashboard and role tests) was written
alongside each operation; the files are listed under Tests.

## Endpoints

Public (`/api/v1`):

| Route | Notes |
|---|---|
| `POST orders/track` | `orderNumber` + `phone`; generic 404 for every miss; limiter `order-tracking` |
| `GET orders/confirmation/{trackingToken}` | Compact success payload; 30/min per IP |
| `POST orders/cancellation-requests` | Only before `DISPATCHED`; one open request per order; limiter `cancellation-requests` |

Tracking and cancellation limits: 5/min and 20/hour per IP plus 10/hour per order number.

Admin (`/api/v1/admin`), gate in brackets:

| Route | Role |
|---|---|
| `GET orders`, `GET orders/{number}` (`view-orders`) | Both |
| `POST orders/{number}/transitions` (`update-order-status`) | Both; one-step-back corrections Admin-only with reason |
| `POST orders/{number}/notes` (`add-order-notes`) | Both |
| `PATCH orders/{number}/delivery` (`edit-order-delivery`) | Admin, reason required |
| `POST orders/manual` (`create-manual-orders`) | Both |
| `GET refunds`, `GET refunds/{id}` (`view-refunds`), `POST refunds/{id}/notes` (`add-refund-notes`) | Both |
| `POST refunds/{id}/decision` (`decide-refunds`) | Admin |
| `POST refunds/{id}/retry` (`decide-refunds`) | Admin |
| `POST refunds/{id}/manual-completion` (`process-refunds`) | Admin |
| `GET customers`, `GET customers/{publicId}`, `POST customers/{publicId}/notes` | Both |
| `GET dashboard` (`view-dashboard`) | Both |

All 19 routes are present in `docs/openapi.yaml` (verified against `php artisan route:list --path=api`).

## Schema

Migration `2026_10_10_180000_create_refund_and_note_tables`:

- `refund_requests` (mutable workflow row): `order_id`, `channel` (WEB/WHATSAPP/STAFF),
  `reason`, `status` (REQUESTED, UNDER_REVIEW, APPROVED, REJECTED, PROCESSING,
  COMPLETED, FAILED), requester name/phone, decision staff/time/reason. A partial
  unique index permits one open request per order. Triggers validate status/channel,
  freeze origin columns and reject DELETE.
- `refund_transactions`: `method` (PAYSTACK/MANUAL), `provider_reference`,
  `amount_minor`, `status` (PENDING/PROCESSED/FAILED), redacted response JSON.
- `refund_notes`, `order_notes`, `customer_notes`: staff-authored bodies.
- `refund_transactions` and the three note tables are append-only: SQLite
  UPDATE/DELETE triggers plus the `App\Support\Models\AppendOnly` model trait.

Migration `2026_10_10_200000_add_provider_attempts_to_refund_requests` (additive):
`provider_attempts` and `provider_call_started_at`, a claim so only one process POSTs a
provider refund at a time (a claim older than 60 seconds is treated as a crashed attempt).

## Implementation decisions

- `OrderStateMachine` is the only writer of `orders.status` after creation.
  Path: PAID, PROCESSING, READY_FOR_DISPATCH, DISPATCHED, DELIVERED. `CANCELLED` and
  `REFUNDED` are reachable only through `transitionForRefund()`, from a pre-dispatch
  status. The update is a compare-and-set inside a transaction; each change writes an
  `order_status_events` row (via `RecordOrderStatusEvent`), an audit row and an
  `OrderStatusChanged` event. Invalid moves return 409 `INVALID_ORDER_TRANSITION` with
  `allowedStatuses`. `OrderStatusWriteGuardTest` scans `app/` for other writers.
- Orders become `REFUNDED` only on provider confirmation (`refund.processed`) or an
  Admin manual completion. `DecideRefund` never touches the order status.
- Paystack refund POSTs run outside write transactions and are never retried
  automatically. A definitive 4xx refusal appends a FAILED transaction and marks the
  request FAILED (502 `REFUND_REJECTED_BY_PROVIDER`). Timeout or 5xx leaves the request
  APPROVED, outcome unknown (503 `REFUND_PROVIDER_UNAVAILABLE`); `refunds/{id}/retry`
  re-attempts only APPROVED online requests with no transaction row.
- `HandleRefundWebhook` runs in the existing `ProcessPaystackEvent` job. It never throws
  for business conflicts; amount mismatch, no in-flight request or a non-refundable order
  becomes a `refund.reconciliation_required` system audit row. Refund events dedupe on
  event type plus `refund_reference`, so a failed refund and a later refund on the same
  charge are both processed.
- Dispatch is blocked (409 `REFUND_IN_PROGRESS`) while a refund is APPROVED or
  PROCESSING, checked inside the transition transaction. Open REQUESTED/UNDER_REVIEW
  requests do not block.
- Manual refunds are full-amount only, Admin-only, with a case-insensitively unique reference.
- Manual orders reuse the checkout `PriceCart` and `InsertOrderItems`, always enter at
  `PAID` with `source=MANUAL`, require `confirm: true` and `amountPaidMinor` equal to the
  items total, and reject a duplicate payment reference (409). They dispatch `OrderPaid`.
- Dashboard "today" is the `Africa/Accra` day, converted explicitly to the storage timezone.
- Tracking and cancellation share one 404 body for unknown order, wrong phone and
  malformed phone. Customer resources exclude notes, staff, payment references and courier data.
- Cancellation requests write a `WEB` `refund_requests` row and audit, and dispatch
  `CancellationRequested`; they never change order status.
- Phase 3 refactors: `PriceCart::applyParents` and `Orders/Actions/InsertOrderItems`
  extracted for reuse; `ListReconciliationAttempts::query()` exposed for the dashboard;
  the webhook controller maps refund payloads to the charge reference.
- Refund and order delivery edits write redacted audit rows; refund/order/customer notes
  are append-only.

## Tests

`tests/Feature/Orders/`: `OrderStateMachineTest`, `OrderStatusWriteGuardTest`,
`OrderTrackingTest`, `CancellationRequestTest`, `AdminOrderTest`, `ManualOrderTest`,
`RefundDecisionTest`, `DashboardTest`; `tests/Feature/Customers/CustomerAdminTest.php`.
OpenAPI route parity runs in the regular suite.

## Verification

Phase 4 branch state: `composer check` passed Pint and Larastan, with
**323 tests / 2514 assertions** (Phase 3 closed at 253 / 1846). No live Paystack call
was made; the provider is faked in tests. Additive migrations are in the branch.
All eight Phase 4 tasks and seven acceptance tests are complete. Open items are
recorded in `docs/implementation/phase_4_review.md`.
