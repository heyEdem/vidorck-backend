# Patterns

- Domain code belongs in `app/Modules/<Domain>`; controllers delegate work to
  actions and form requests. Only cross-domain primitives belong in Support.
- Module routes are discovered under `routes/api.php` and `routes/admin.php`
  inside each module. Public endpoints mount at `/api/v1`, staff endpoints at
  `/api/v1/admin`.
- API resources map snake_case storage fields to camelCase JSON keys, including nested Arrayable/JsonSerializable data. Metadata supplied through `with()` or `additional()` must already use camelCase. Paginated collections require `LengthAwarePaginator` to provide `meta.total`.
- Money uses integer pesewas and the fixed `GHS` currency; no float conversion.
- API errors always contain `message`, `code`, and an object-valued `errors`.
  Exception HTTP headers (such as Retry-After) are preserved.
- Global response middleware wraps CORS so preflights and error responses carry
  request IDs and security headers. Log context uses `request_id`.
- SQLite settings belong in connection configuration, not one-time provider
  statements, so reconnects preserve the same invariants.
- Pest feature tests use Laravel's application test case. Pure money tests run
  without booting Laravel. WAL tests use temporary SQLite files and clean them up.
- Adding a route also requires updating `docs/openapi.yaml`; route parity is
  checked as part of the regular test suite.
- All module admin routes inherit Sanctum auth, staff-session validation, and
  forced-password-change middleware. Add the appropriate capability gate or
  policy for each domain operation; middleware alone does not grant permissions.
- Call `AuditLogger::record()` inside the mutation transaction. Build summaries
  from known fields; the logger recursively removes credential/payment keys.
  Never bypass audit triggers through a table rebuild or bulk replacement.
- Credential/role/active-state changes call `RevokeStaffSessions` inside the same
  transaction. Own password changes then rotate and retain only the caller's
  session, preserving its original absolute expiry.
- Password inputs use camelCase and are explicitly excluded from TrimStrings
  and exception flash data. Staff API responses carry `Cache-Control: no-store`.
- Apply `Product::visible()` and `Bundle::visible()` before pagination and detail
  lookups. Public catalogue resources explicitly allowlist fields; admin
  resources can include IDs and source prices.
- Catalogue writes recheck the actor using `CatalogActorGuard`, audit atomically,
  and dispatch one `CatalogInvalidation` job after the transaction returns.
  Include dependent bundles and old/new slugs.
- Image decoding and file I/O happen outside SQLite write transactions; delete
  new directories on failure and retire previous media only on commit.
- SQLite ignores unsigned declarations. Catalogue money uses explicit triggers
  and bundle quantities have database guards, including non-HTTP writes.

- Commit checkout attempts before Paystack initialization. Keep all provider
  HTTP outside SQLite write transactions; do not automatically retry monetary
  POSTs. Allow bounded retries for safe verification GETs only.
- Treat payment references and tracking tokens as capabilities. Public status
  responses allowlist fields; verification snapshots omit provider secrets.
- Claim polling cooldowns atomically in the database. Finalize verified payments
  through CreateOrderFromAttempt, re-reading state in an IMMEDIATE transaction.
  Unique attempt/payment keys prevent duplicate orders across processes.
- Signed webhook receipts and database job insertion use the same transaction.
  Completed receipts are no-ops; unfinished duplicates can requeue idempotently.
- Audit and order status history are insert-only, with database UPDATE/DELETE
  guards (SQLite truncate compiles to DELETE). Write order history through
  `RecordOrderStatusEvent::record()`. Corrections append compensating
  events referencing the original. Webhook inbox facts are immutable; the
  terminal processing marker can be recorded once and never reset.
- Change `orders.status` only through `OrderStateMachine` (`transition()` or
  `transitionForRefund()`); `OrderStatusWriteGuardTest` scans for other writers.
- Public order lookups return one generic 404 for unknown order, wrong phone and
  malformed phone. Rate-limit by IP and by order number.
- Refund provider calls: commit the decision first, call Paystack outside any
  transaction, never retry automatically, and treat timeouts as outcome unknown.
  Webhook handlers record business conflicts as audit rows instead of throwing.
- Refund transactions and order/refund/customer notes are insert-only (triggers
  plus `AppendOnly`); correct them only by appending new rows.
- Notify only through `NotificationDispatcher::once(key, channel, recipient, payload)`
  with a per-occurrence, per-recipient key. Planners swallow planning errors by design;
  `once()` is atomic via its own savepoint, so a failed push leaves no log row.
  Payloads are frozen snapshots, never live models. Provider calls happen only in the job.
- Notification logs and attempts are insert-only; current status is the latest attempt.
  Never store raw recipients, bodies or provider responses.
- Public form endpoints: rate limiter first, then honeypot (normal 200, no side effects),
  then validation. Reject control characters in anything that can reach a header.
  Do not swallow an unconfigured-inbox failure; return 503 so no false acceptance is shown.
