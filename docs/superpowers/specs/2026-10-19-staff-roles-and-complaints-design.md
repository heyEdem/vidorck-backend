# Staff roles, permission-based admin, and the complaint box

Date: 2026-10-19 · Repos: `VHI_Backend` (API) and `VHI_Website` (storefront + `/admin`)

## Why

Vidorck is adding staff accounts beyond Admin. Each person should see only the admin pages
and actions their job needs: Sales moves orders, Customer Service handles customers who
reach out. Separately, the website contact form currently emails an inbox and stores
nothing, so customer issues aren't visible to the team as a queue. It becomes a complaint
box Customer Service works from.

## Goals

1. Three roles: **Admin**, **Sales**, **Customer Service**.
2. Pages, nav items, and action buttons in `/admin` appear only when the logged-in staff
   member has the permission for them. The API refuses the same actions independently.
3. Role → permission mapping lives in exactly one place (the backend). The frontend never
   checks role names.
4. Website contact submissions are stored as complaints with a reference number, and
   Customer Service works them from an admin queue (claim, notes, resolve).
5. The customer says how they want to be reached (call, WhatsApp, or email) and leaves the
   matching phone number or email.

## Non-goals

- Hiding individual fields within a page. Restrictions are per page and per action only.
- Editing roles or permissions from the UI. Roles are code.
- A marketing role, complaint SLAs/escalation rules, a customer-facing complaint status
  page, or ingesting emails into the box.

## Anti-goals (failure even if it "works")

- Any restriction enforced only by hiding UI.
- Frontend code branching on `role === "ADMIN"` etc.
- Complaint history that can be edited or deleted.
- Staff email alerts for new complaints (the team removed staff alerts on purpose; the
  box is the queue).

## Constraints

- Audit/history tables are strictly append-only, enforced by a DB trigger rejecting
  UPDATE, DELETE and TRUNCATE; corrections are compensating events. Follow the existing
  pattern in `2026_10_10_120000_make_order_status_events_append_only.php`.
- `staff_users.role` has `CHECK (role IN ('ADMIN','SALES_ORDERS'))`; it needs a migration
  to allow `CUSTOMER_SERVICE` (SQLite in dev, so table-rebuild style where needed).
- Existing gate names (`view-orders`, `decide-refunds`, …) are used by routes as
  `can:` middleware and must keep working unchanged.
- Money values are integer pesewas (`*_minor`); timezone for dates is `Africa/Accra`.

## Decisions

### Roles

| Enum case | Stored value | Label |
|---|---|---|
| `ADMIN` | `ADMIN` | Admin |
| `SALES_ORDERS` | `SALES_ORDERS` (unchanged, no data migration) | Sales |
| `CUSTOMER_SERVICE` | `CUSTOMER_SERVICE` (new) | Customer service |

### Permissions model (backend)

- New `App\Modules\Staff\Enums\Permission` string enum. Its values are the existing gate
  names plus three new ones: `view-sales-figures`, `view-complaints`, `handle-complaints`.
  `delete-orders` stays a hard `false` gate outside the enum.
- `StaffRole::permissions(): list<Permission>` is the single mapping;
  `StaffRole::allows(Permission): bool` reads it.
- `AuthServiceProvider` replaces its two hard-coded lists with one loop:
  `Gate::define($p->value, fn (StaffUser $s) => $s->is_active && $s->role->allows($p))`.
  The existing `Gate::before` inactive-user guard stays.
- `GET admin/auth/me` (and the login response, if it returns the staff user) adds
  `permissions: string[]`, the current user's permission values.

### Role → permission matrix

| Permission | Admin | Sales | Customer service |
|---|---|---|---|
| view-dashboard | ✓ | ✓ | ✓ |
| view-sales-figures (new) | ✓ | | |
| view-orders | ✓ | ✓ | ✓ |
| update-order-status | ✓ | ✓ | |
| add-order-notes | ✓ | ✓ | ✓ |
| create-manual-orders | ✓ | ✓ | |
| edit-order-delivery, edit-order-contents | ✓ | | |
| view-refunds, add-refund-notes | ✓ | ✓ | ✓ |
| decide-refunds, process-refunds | ✓ | | |
| view-customers, add-customer-notes | ✓ | ✓ | ✓ |
| view-catalogue | ✓ | ✓ | ✓ |
| manage-catalogue, publish-catalogue, manage-images | ✓ | | |
| view-complaints, handle-complaints (new) | ✓ | | ✓ |
| view-reconciliation, manage-staff, view-audit | ✓ | | |
| change-own-password | ✓ | ✓ | ✓ |

Note: delivery areas are currently gated by `view-catalogue`; under this matrix they become
visible to Sales and CS read-only. That's acceptable (it's the price list for delivery).

### Role-aware dashboard

`BuildDashboard` returns only the sections the caller may see; keys for sections they
lack are **omitted** (not null):

- Sales figures (`salesMinor`, `salesTodayMinor`, anything else in GHS): `view-sales-figures`.
- Order counts (paid orders count, processing/ready/dispatched counts): `view-orders`.
- `openRefundRequests`: `view-refunds`.
- `reconciliationCount`, `refundReconciliationCount`, `failedNotificationCount`:
  `view-reconciliation` (Admin).
- New `newComplaints` (status NEW) and `complaintsWaiting` (NEW and older than 24h):
  `view-complaints`.

The frontend renders a dashboard section only if its key is present.

### Frontend (`VHI_Website`)

- `src/lib/admin/roles.ts` stops mirroring the backend lists. `can(permissions, ability)`
  checks the `permissions` array from the session (`auth/me`). `Ability` stays a string
  union for type safety; it must list every backend permission value.
- `navFor(permissions)` filters `ADMIN_NAV`. Add `{ href: "/admin/complaints",
  label: "Complaints", ability: "view-complaints" }`.
- Every admin page declares its required ability. Visiting a page without it redirects to
  the first nav item the user has (or `/admin`). Action buttons render only when `can()`
  passes.
- Staff management: the create/edit staff form offers the three roles with the labels
  above.

### Complaint box

**Public form** (replaces the contact form on the existing contact page; the page keeps
its "Contact us" title, since customers don't think of their message as a "complaint").
Fields:

- name (required, existing rules)
- topic (required): `ORDER_PROBLEM` "A problem with my order", `PRODUCT_QUESTION`
  "A question about a product", `OTHER` "Something else"
- order number (optional, shown when topic is ORDER_PROBLEM)
- message (required, existing rules)
- "How should we reach you?" (required): `CALL`, `WHATSAPP`, `EMAIL`
- phone (required when CALL or WHATSAPP; Ghana number via `PhoneNumber::fromGhanaInput`)
- email (required when EMAIL; optional otherwise)

Keep the existing control-character rejection and the `contact-submissions` rate limiter.
New public endpoint `POST complaints` replaces `POST contact`. Remove `SubmitContact`,
`ContactRelay`, and the `CONTACT_EMAIL` config once the website no longer calls the old
endpoint. Success shows the reference: "We've got it. Your reference is C-000123."

**Storage**

`complaints` (a working record, so status/assignee columns are a projection updated in the
same transaction as the event, like orders and `order_status_events`):

- `id`, `reference` (unique, `C-` + zero-padded id, 6 digits)
- `name`, `topic`, `message`, `contact_method`, `phone_e164` nullable, `email` nullable
- `order_number_entered` nullable (as typed), `order_id` nullable FK, `customer_id` nullable FK
- `status`: `NEW` → `IN_PROGRESS` → `RESOLVED`
- `assigned_staff_id` nullable FK, `created_at`, `updated_at`, `resolved_at` nullable

`complaint_events` (append-only, DB trigger rejects UPDATE/DELETE/TRUNCATE):

- `id`, `complaint_id`, `staff_id` nullable (null for the customer's submission),
  `type` (`SUBMITTED`, `CLAIMED`, `NOTE`, `STATUS_CHANGED`), `from_status`, `to_status`,
  `body` nullable, `created_at`

**Linking on submit**

- Customer: match normalized phone to `customers.phone_e164`, else lowercased email to
  `customers.email`. Link the first match; no match is fine.
- Order: link `order_id` only when the order exists **and** the submitted phone or email
  matches either the contact details captured on the order at checkout
  (`customer_snapshot_json`) or its linked `customers` row. Otherwise keep only
  `order_number_entered`, so staff see it as unverified. *(Builder-check call: either
  source counts, since customers may have updated details since ordering.)*

**Customer acknowledgement**

Send once via `NotificationDispatcher` (idempotency key on the complaint id) on the
channel the customer chose: SMS for CALL or WHATSAPP, email for EMAIL. Template
`complaint-received` with the reference. A failed send never fails the submission; it
shows up in failed-notification counts.

**Working rules** *(builder-check calls)*

- Claiming is a "who's on it" signal, not a lock. Anyone with `handle-complaints` can add
  notes or change status on any complaint, including one claimed by someone else, so Admin
  can always step in. The timeline shows who did what.
- Claiming a NEW complaint also moves it to IN_PROGRESS (writes CLAIMED and
  STATUS_CHANGED events in one transaction), so `newComplaints` counts only untouched ones.
- NEW → RESOLVED directly is allowed (spam, duplicates, answered in one call); the
  resolver becomes the assignee.
- A RESOLVED complaint accepts notes but can't be claimed; reopen it first.

| From | Allowed to | Note required |
|---|---|---|
| NEW | IN_PROGRESS (via claim or status), RESOLVED | only for RESOLVED |
| IN_PROGRESS | RESOLVED | yes |
| RESOLVED | IN_PROGRESS (reopen) | yes |

**Admin side** (`view-complaints` to see, `handle-complaints` to act)

- `GET admin/complaints`: list with filters status, `mine`, `unassigned`; default sort is
  open (NEW, IN_PROGRESS) first, oldest first.
- `GET admin/complaints/{reference}`: complaint, linked customer/order summary, full
  event timeline.
- `POST admin/complaints/{reference}/claim`: assigns to the caller (taking over from
  someone else is allowed; the event records it).
- `POST admin/complaints/{reference}/notes`: body required.
- `POST admin/complaints/{reference}/status`: `IN_PROGRESS`, or `RESOLVED` (note
  required, which becomes the event body). RESOLVED → IN_PROGRESS (reopen, note required)
  is allowed for when a customer comes back.
- Admin pages `/admin/complaints` and `/admin/complaints/[reference]`. The detail page
  shows one-tap contact links (`tel:`, `https://wa.me/<digits>`, `mailto:`) for the
  method the customer chose.
- Customer and order detail pages show their linked complaints to users with
  `view-complaints`.

**Privacy page**: update the data-minimisation copy to say support messages are stored
so the team can follow up.

## Left to the builder

- File/class names and module placement (a `Complaints` module or inside `Customers`).
- Whether staff complaint actions are also written through `AuditLogger`; follow
  whatever order notes and refund notes do today.
- Exact list page size, filter UI, and copy beyond what's specified here.
- Test structure; cover the permission matrix with a data-provider test per role and
  ability on both sides.
