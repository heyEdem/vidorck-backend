# Phase 4 — Orders, Tracking, Refunds & CRM

**Goal:** Enforce the order lifecycle, let customers track orders and request cancellation, and give staff the order, manual-order, refund, customer, and dashboard endpoints from the permission matrix.
**Estimate:** ~1 week · **Depends on:** Phases 1 and 3.
**Source:** [§5.3 Orders](../product-spec.md#53-orders) · [§5.4 Customers](../product-spec.md#54-customers-crm) · [§6.5 Tracking](../product-spec.md#65-orders-tracking-and-customer-requests) · [§6.8 Admin capabilities](../product-spec.md#68-admin-capabilities) · `website-spec.md` §14–16, §17.2

## Current Code Audit

- [x] Order and customer tables existed after Phase 3; Phase 4 added the lifecycle, tracking, refund, admin order, CRM and dashboard endpoints.

## Tasks

### 4.1 Implement order state machine
- `OrderStatus` enum and `OrderStateMachine::transition(order, to, actor, reason)` allowing only `PAID → PROCESSING → READY_FOR_DISPATCH → DISPATCHED → DELIVERED`, plus `CANCELLED` / `REFUNDED` via refund flow.
- Narrow Admin correction (one step back) requires a reason. Every change writes `order_status_events` and an audit row, then fires `OrderStatusChanged`.
- Files: `app/Modules/Orders/OrderStateMachine.php`, `app/Modules/Orders/Enums/OrderStatus.php`
- Done when: no other code path writes `orders.status` (enforced by test/architecture check).

### 4.2 Implement public tracking and confirmation
- `POST orders/track` (`orderNumber` + `phone`, normalised) with generic 404 and rate limits (5/min, 20/hour per IP; 10/hour per order number).
- `GET orders/confirmation/{trackingToken}` returns the compact success payload.
- Customer resource excludes notes, staff, payment references, and courier details.
- Files: `app/Modules/Orders/Http/Controllers/Public/TrackingController.php`, `app/Modules/Orders/Http/Resources/CustomerOrderResource.php`
- Done when: wrong phone and unknown order return byte-identical responses.

### 4.3 Implement cancellation requests and refund schema
- Migrations for `refund_requests`, `refund_transactions`, `refund_notes`, `order_notes`, `customer_notes`.
- `POST orders/cancellation-requests` (order number + phone + reason): allowed only before `DISPATCHED`; one open request per order; fires `CancellationRequested`.
- Files: `database/migrations/`, `app/Modules/Orders/Models/RefundRequest.php`, `CancellationRequestController.php`
- Done when: a dispatched order returns 409 and creates nothing.

### 4.4 Implement admin order endpoints
- `GET admin/orders` (status, source, date range, search on number/name/phone, paginated), `GET admin/orders/{number}`.
- `POST admin/orders/{number}/transitions`, `POST admin/orders/{number}/notes`.
- `PATCH admin/orders/{number}/delivery` (ADMIN, reason required, audited before/after).
- Files: `app/Modules/Orders/Http/Controllers/Admin/OrderController.php`, `AdminOrderResource.php`
- Done when: invalid transitions return 409 with the allowed next statuses.

### 4.5 Implement manual paid orders
- `POST admin/orders/manual` per `website-spec.md` §15: customer + delivery, items priced from current catalogue as snapshots, payment method, reference, amount, note, `confirm: true`.
- Enters at `PAID`, `source=MANUAL`, `created_by_staff_id` set; duplicate `payment_reference` returns 409; upserts customer.
- Files: `app/Modules/Orders/Actions/CreateManualOrder.php`, `ManualOrderController.php`
- Done when: manual orders cannot be created in any status other than `PAID`.

### 4.6 Implement refund decisions
- `GET admin/refunds`, `GET admin/refunds/{id}`, `POST admin/refunds/{id}/notes` (both roles).
- `POST admin/refunds/{id}/decision` (ADMIN): re-checks order is pre-dispatch; `APPROVE` on online orders calls Paystack refund and moves to `PROCESSING`; `REJECT` requires reason.
- Handle `refund.processed` / `refund.failed` webhooks → `COMPLETED` (order `REFUNDED`) / `FAILED`.
- `POST admin/refunds/{id}/manual-completion` (ADMIN) for manual orders with method, reference, amount.
- Files: `app/Modules/Orders/Actions/DecideRefund.php`, `HandleRefundWebhook.php`, `RefundController.php`
- Done when: an order is marked `REFUNDED` only after provider or manual confirmation.

### 4.7 Implement customers and dashboard
- `GET admin/customers` (search), `GET admin/customers/{publicId}` (with orders), `POST admin/customers/{publicId}/notes`.
- `GET admin/dashboard`: today's paid orders, sales today (Africa/Accra day), Processing / Ready / Dispatched counts, open refunds, reconciliation and failed-notification counts.
- Files: `app/Modules/Customers/Http/Controllers/Admin/`, `app/Modules/Orders/Http/Controllers/Admin/DashboardController.php`
- Done when: "today" uses the Accra day boundary, not UTC midnight (identical offset, but tested explicitly).

### 4.8 Add lifecycle and permission tests
- Cover transitions, tracking enumeration resistance, cancellation windows, manual orders, refunds, CRM, dashboard, and role matrix.
- Files: `tests/Feature/Orders/**`, `tests/Feature/Customers/**`
- Done when: the acceptance tests below pass.

## Acceptance Tests

- [x] **invalid_transition_is_rejected:** Given a `PAID` order, when staff transition it to `DELIVERED`, then the response is 409 and status is unchanged.
- [x] **tracking_does_not_enumerate:** Given a real order number with the wrong phone, when tracked, then the response matches an unknown order number exactly.
- [x] **cancellation_blocked_after_dispatch:** Given a `DISPATCHED` order, when a cancellation request is posted, then the response is 409.
- [x] **sales_cannot_decide_refunds:** Given a `SALES_ORDERS` user, when they approve a refund, then the response is 403.
- [x] **refund_marks_refunded_only_on_confirmation:** Given an approved Paystack refund, when no `refund.processed` has arrived, then the order is not `REFUNDED`.
- [x] **manual_order_duplicate_reference_rejected:** Given an existing manual order with reference `MOMO-123`, when another is created with `MOMO-123`, then the response is 409.
- [x] **customer_upserted_by_phone:** Given two orders from `0244000000` and `+233244000000`, when both are created, then one customer exists with `ordersCount = 2`.

## Verification

- `./vendor/bin/pest --filter='Order|Refund|Tracking|Customer|Dashboard'`
- `php artisan test`
