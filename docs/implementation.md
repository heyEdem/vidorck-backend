# Implementation index

| Entry point | Role |
|---|---|
| `public/index.php` | Laravel HTTP entry point |
| `artisan` | Laravel console entry point |
| `bootstrap/app.php` | API routes, middleware ordering, JSON exceptions |
| `app/Providers/AppServiceProvider.php` | Shared application service registration |
| `routes/api.php` | Module public/admin route discovery |

Each module has `Http/Controllers`, `Http/Requests`, `Http/Resources`, `Models`,
`Actions`, and `routes/{api,admin}.php`. Staff, Catalog, Checkout, Orders, Customers, and Notifications are implemented;
remaining domain routes are populated in Phase 6.

Admin dashboard: `Orders/Http/Requests/DashboardRequest.php` validates `period`
(`today`, `week`, `month`, `year`, `custom`) and custom `from`/`to` dates.
`Orders/Actions/BuildDashboard.php` calculates inclusive Africa/Accra calendar
ranges (weeks start Monday), selected-period paid orders/sales, legacy today
totals, and current fulfilment/attention counts. The website dashboard in
`VHI_Website/src/app/admin/(shell)/page.tsx` owns the matching controls.
The business overview adds elapsed-period `range` metrics, refund-date cash
accounting, returning-customer rankings, catalogue/channel sales, checkout
outcomes, status-history delivery durations, and a bounded live attention queue.
Nested money, customer, refund, and reconciliation fields retain their own
permission gates. See [dashboard-analytics.md](dashboard-analytics.md) and the
`AdminDashboard` OpenAPI schema for the response contract. Custom dates cannot
extend beyond today's Accra date. No audit or status history is mutated.

| Staff entry point | Role |
|---|---|
| `Staff/Console/CreateAdminCommand.php` | Interactive Admin bootstrap |
| `Staff/Http/Controllers/AuthController.php` | Login, logout, me, own password |
| `Staff/Http/Controllers/StaffController.php` | Admin staff CRUD/reset |
| `Staff/Actions/ManageStaff.php` | Transactional staff mutations |
| `Staff/Actions/RevokeStaffSessions.php` | Session deletion and version bump |
| `Staff/Actions/AuditLogger.php` | Recursive redaction and audit insertion |
| `Staff/Http/Controllers/AuditLogController.php` | Filtered Admin audit access |
| `app/Providers/AuthServiceProvider.php` | Full role matrix and StaffPolicy |

Staff paths above are relative to `app/Modules/`. Admin module routes inherit
shared middleware in `routes/api.php`; login explicitly excludes authentication.
The web guard authenticates `staff_users`, and staff have no API-token trait.

| Catalog entry point | Role |
|---|---|
| `Catalog/Models/Product.php`, `Bundle.php` | SQL publication/component visibility |
| `Catalog/Http/Controllers/Public/` | Safe catalogue lists, filters, facets, slug details |
| `Catalog/Http/Controllers/Admin/` | Role-gated products, variants, categories, bundles, images |
| `Catalog/Actions/ManageCatalog.php` | Audited edits, price derivation, slug history |
| `Catalog/Actions/ManageBundles.php`, `BundlePricing.php` | Ordered items, stored and suggested prices |
| `Catalog/Actions/CatalogActorGuard.php` | Fresh Admin authorization inside write transactions |
| `Catalog/Actions/StoreImage.php` | Validation, metadata removal, renditions, retirement |
| `Catalog/Actions/CatalogInvalidation.php` | One refresh job per change, including dependent bundles |
| `Catalog/Jobs/RevalidateStorefront.php` | Shared-secret HTTP refresh with retry/backoff |
| `Catalog/Support/CatalogNormalizer.php` | Case/accent-insensitive name and size normalization |
| `database/seeders/CatalogSeeder.php` | Structural categories and hair types |

Catalog paths above are relative to `app/Modules/`, except the seeder.
Image URLs use the `media` disk and `/media` symlink; public resources use
`activeMedia` while old rows/files remain available. Catalogue tests cover
visibility, data redaction, pricing, slugs, roles, audits, uploads, and refreshes.

Shared behavior lives in `app/Support`: `Money` and
HTTP request-ID, security-header, error-response, and API-resource classes.

Tests exercise real HTTP exceptions (including requests without Accept headers),
CORS preflights, request-ID generation/echoing, HSTS, module route prefixes,
camelCase resources, money serialization, database migrations, SQLite reconnects,
and registered-route/OpenAPI parity.

Staff tests also exercise real encrypted session/XSRF cookies, independent
browser revocation, idle/absolute expiry, password rotation, forced changes,
Admin policies, audit filtering/immutability/redaction, and injected database
failures to prove mutation rollback and failed-login cleanup.

Local setup and verification commands are in `README.md`; environment settings
and production overrides are in `.env.example`. The task index tracks phase
completion; `docs/implementation/phase_0_progress.md` records implementation
choices and verification evidence.

| Checkout/payment entry point | Role |
|---|---|
| `Checkout/Actions/StartCheckout.php`, `PriceCart.php` | Validation, server pricing, snapshot persistence, Paystack initialization |
| `Checkout/Paystack/PaystackClient.php` | Configured, bounded provider HTTP calls |
| `Checkout/Http/Controllers/PaystackWebhookController.php` | Raw-body HMAC and atomic durable receipt/job |
| `Checkout/Jobs/ProcessPaystackEvent.php`, `Actions/HandleChargeSuccess.php` | Server verification and shared payment handling |
| `Checkout/Actions/CheckCheckoutStatus.php`, `RetryCheckout.php` | Durable poll cooldown, evidence recovery, and validated retry chain |
| `Checkout/Http/Controllers/Admin/` | Delivery areas and read-only payment reconciliation |
| `Orders/Actions/CreateOrderFromAttempt.php`, `NextOrderNumber.php` | Atomic snapshot order, yearly numbering, and after-commit event |
| `Orders/Actions/RecordOrderStatusEvent.php` | Insert-only immutable status history writer |
| `Customers/Actions/UpsertCustomer.php` | Per-phone paid-order summaries with late-payment handling |
| `database/seeders/DeliveryAreaSeeder.php` | 48 normal-delivery areas, preserving edits |

Paths above are relative to `app/Modules/`, except the seeder. Phase 3 evidence
is in `docs/implementation/phase_3_progress.md` and `phase_3_review.md`.

| Orders/CRM entry point | Role |
|---|---|
| `Orders/OrderStateMachine.php` | Only writer of order status; transitions, corrections, refund-flow moves |
| `Orders/Http/Controllers/Public/TrackingController.php`, `CancellationRequestController.php` | Enumeration-resistant tracking, confirmation, cancellation requests |
| `Orders/Actions/RequestCancellation.php` | Pre-dispatch cancellation request, one open per order |
| `Orders/Http/Controllers/Admin/OrderController.php`, `ManualOrderController.php` | Admin orders, transitions, notes, delivery edit, manual orders |
| `Orders/Actions/CreateManualOrder.php`, `InsertOrderItems.php` | Manual paid order and shared item snapshots |
| `Orders/Actions/DecideRefund.php`, `CompleteManualRefund.php` | Refund decisions, Paystack call, retry, manual completion |
| `Orders/Actions/HandleRefundWebhook.php` | `refund.processed` / `refund.failed` handling and reconciliation audit |
| `Orders/Actions/UpdateOrderContents.php` | Admin-only item edits, current-price validation for changed lines, offline settlement, audit and customer notification planning |
| `Orders/Actions/RecordOrderContentRevision.php`, `Models/OrderContentRevision.php` | Insert-only snapshots of each item revision, with database and model immutability guards |
| `Orders/Actions/BuildDashboard.php`, `Admin/DashboardController.php` | Africa/Accra dashboard summary |
| `Customers/Http/Controllers/Admin/CustomerController.php`, `Actions/ListCustomers.php` | CRM search, detail and notes |
| `Customers/Actions/UpsertCustomer.php` | Customer order-count and lifetime-paid summaries; item edits leave paid totals unchanged |
| `Support/Models/AppendOnly.php` | Insert-only model behaviour |

Paths above are relative to `app/Modules/`, except `Support`. Phase 4 evidence is in
`docs/implementation/phase_4_progress.md` and `phase_4_review.md`.

Order content updates are restricted to paid or processing orders with no open refund
request. A revision records before/after item snapshots, settlement details and the
reason; database triggers and the model reject changes or deletion. The edit changes the
items subtotal while preserving `total_paid_minor`, so customer lifetime-paid summaries
and later refund amounts remain based on the original payment.

| Notifications entry point | Role |
|---|---|
| `Notifications/NotificationDispatcher.php` | `once()` / `key()`: atomic log row plus queued job |
| `Notifications/Jobs/SendNotification.php` | Claim, send, retry, exhaust; appends attempts |
| `Notifications/Models/NotificationLog.php`, `NotificationAttempt.php` | Append-only intent and attempt facts |
| `Notifications/Sms/ArkeselSmsProvider.php`, `LogSmsProvider.php`, `SmsMessage.php` | SMS providers, GSM-7 messages within 306 characters |
| `Notifications/Mail/EmailNotificationSender.php`, `NotificationMailable.php` | Resend sender and whitelisted mail variables |
| `Notifications/Planners/OrderNotificationPlanner.php`, `RefundNotificationPlanner.php` | Failure-proof recipient wiring called from Orders actions |
| `Complaints/Actions/SubmitComplaint.php`, `Http/Controllers/PublicComplaintController.php` | Persistent support intake and acknowledgement |
| `Notifications/Actions/CountFailedNotifications.php` | Dashboard failed count |

Paths above are relative to `app/Modules/`. Phase 5 evidence is in
`docs/implementation/phase_5_progress.md` and `phase_5_review.md`.

| Import and scheduler entry point | Role |
|---|---|
| `Catalog/Console/ImportCatalogCommand.php` | `catalog:import` dry-run / apply |
| `Catalog/Import/WorkbookReader.php`, `CatalogImporter.php`, `ImportReport.php` | Header checkpoint, row validation, per-product upserts, report |
| `Catalog/Import/CatalogSnapshot.php`, `ImageMatcher.php` | `VACUUM INTO` snapshot; filename matching with `config/catalog_image_overrides.php` |
| `Checkout/Jobs/ExpireStaleCheckoutAttempts.php`, `ReverifyPendingCheckoutAttempts.php`, `PruneFailedCheckoutAttempts.php` | Scheduled checkout upkeep |
| `Catalog/Jobs/PruneRetiredMedia.php`, `../Jobs/PruneExpiredSessions.php`, `../Jobs/OptimizeSqlite.php` | Daily cleanup; registered in `routes/console.php` |

Paths above are relative to `app/Modules/`. The workbook contract is `docs/catalogue-import.md`. Phase 6 evidence is in
`docs/implementation/phase_6_progress.md` and `phase_6_review.md`.
