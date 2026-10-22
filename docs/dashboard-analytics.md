# Admin dashboard analytics

`GET /api/v1/admin/dashboard` accepts the existing `period` values (`today`, `week`, `month`, `year`, `custom`). A custom range requires inclusive `from` and `to` dates in `YYYY-MM-DD`, and both dates must be no later than the current date in `Africa/Accra`.

The existing top-level summary fields remain compatible and retain their calendar-period behavior. Additive analytics live alongside them:

- `range.current` reports the selected elapsed period, with `receivedMinor` from paid orders and `refundsMinor` from processed refund transactions. `netSalesMinor` is their difference. Refunds count on the immutable transaction's `created_at` date. `paidOrders` and average order value include paid orders that were later refunded. `range.previous` uses the same elapsed length, is clamped before the current range, and is `null` when there is no earlier paid-order sample.
- Running today, week, month, and year ranges end at the current Accra date. Custom ranges use their requested dates. The legacy top-level period boundaries are unchanged.
- `attentionQueue` contains at most 10 oldest rows per kind (order, payment, mismatch, refund), then sorts those rows together; `attentionSummary` counts the full queue. Order rows use the latest append-only status event time. An order is stale after 48 hours in a status other than `DISPATCHED`. Verified attempts without an order are treated as successful payments when their status is `SUCCEEDED` or their persisted verification snapshot says `success`; `MISMATCH` attempts remain separate, while verified failures are excluded. Refund request rows omit an amount because the request table does not record one and requests may be partial.
- `fulfillment` and `fulfillmentComparison` report active stage counts and paid-to-delivered / paid-to-dispatched average hours from status history for the current and prior elapsed periods.
- `catalogue` returns up to four best sellers and 20 unsold products or bundles, with total counts. Bundle lines are grouped by bundle and product variants by product; cancelled and refunded orders do not contribute to best sellers.

Permission rules apply to every nested group and sensitive field. `VIEW_ORDERS` permits operational counts and order queue rows. `VIEW_SALES_FIGURES` permits revenue totals, refund amounts, checkout amounts, catalogue amounts, and source channel sales. `VIEW_CUSTOMERS` permits customer names, public IDs, and returning-customer rows. `VIEW_REFUNDS` permits refund queue rows and refund counts. `VIEW_RECONCILIATION` permits payment/mismatch queue rows and reconciliation counts. Fields outside a staff member's permissions are omitted.

Dashboard analytics only read payment, refund, audit, and status history. Append-only history is never updated or deleted.
