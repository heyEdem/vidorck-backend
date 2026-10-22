# Phase 1 — Staff Auth, Roles & Audit

**Goal:** Give staff secure cookie-based login through Laravel Sanctum SPA auth, enforce the Admin / Sales-Orders permission matrix on the server, and record every security and staff action in an append-only audit log.
**Estimate:** ~3-4 days · **Depends on:** Phase 0.
**Source:** [§6.7 Admin authentication](../product-spec.md#67-admin-authentication-sanctum-spa) · [§6.12 Audit](../product-spec.md#612-audit) · [§5.5](../product-spec.md#55-staff-audit-notifications) · `website-spec.md` §17

## Current Code Audit

- [x] Staff models, schema, auth and management routes, policies/gates, session hardening, and append-only audit storage implemented.

## Tasks

### 1.1 Add staff schema and hashing
- Migration for `staff_users` (`name`, `email` unique, `password`, `role`, `is_active`, `must_change_password`, `last_login_at`, `last_login_ip`) with a `CHECK` on `role`.
- `StaffRole` backed enum (`ADMIN`, `SALES_ORDERS`); set `HASH_DRIVER=argon2id`; point the `web` guard provider at `StaffUser`.
- Files: `database/migrations/`, `app/Modules/Staff/Models/StaffUser.php`, `app/Modules/Staff/Enums/StaffRole.php`, `config/auth.php`, `config/hashing.php`
- Done when: stored hashes start with `$argon2id$`.

### 1.2 Add admin bootstrap command
- `php artisan staff:create-admin` prompts for name, email, password (hidden, strength-checked); refuses if an Admin exists unless `--force`.
- Files: `app/Modules/Staff/Console/CreateAdminCommand.php`
- Done when: no default credentials exist anywhere in code or seeders.

### 1.3 Implement Sanctum SPA login
- Configure `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN=.vidorckhair.com`, secure cookie, `SameSite=Lax`, `SESSION_LIFETIME=60`.
- `POST admin/auth/login` (generic error, session regenerate, last-login metadata), `POST admin/auth/logout`, `GET admin/auth/me`.
- Rate limit login at 5/min per email+IP. Middleware enforcing a 12-hour absolute session lifetime and `is_active`.
- Files: `config/sanctum.php`, `config/session.php`, `app/Modules/Staff/Http/Controllers/AuthController.php`, `app/Modules/Staff/Http/Middleware/`
- Done when: login works from the admin origin with `/sanctum/csrf-cookie` + `X-XSRF-TOKEN` and fails without the XSRF token.

### 1.4 Implement password change and reset
- `POST admin/auth/password` (current + new password, rotates session).
- Middleware returns `403 PASSWORD_CHANGE_REQUIRED` on every other admin route while `must_change_password` is true.
- `POST admin/staff/{id}/reset-password` (ADMIN) issues a one-time temporary password, sets `must_change_password`, revokes sessions.
- Files: `AuthController.php`, `StaffController.php`, `RequirePasswordChange.php`
- Done when: a reset user can only call `me`, `logout`, and `password` until they change it.

### 1.5 Implement staff management
- `GET/POST admin/staff`, `PATCH admin/staff/{id}` (role, `is_active`), ADMIN only via `StaffPolicy`.
- Disabling, role change, or reset deletes every `sessions` row for that user.
- Gates for the full `website-spec.md` §17.1 matrix (`manage-catalogue`, `decide-refunds`, `manage-staff`, `view-audit`, `edit-order-delivery`).
- Files: `app/Modules/Staff/Http/Controllers/StaffController.php`, `app/Modules/Staff/Policies/`, `app/Providers/AuthServiceProvider.php`
- Done when: a disabled user's next request returns 401.

### 1.6 Add audit log
- Migration for `audit_logs`; model throws on update/delete.
- `AuditLogger::record(actor, action, target, before, after)` with key redaction (`password`, `token`, `secret`, card/payment payloads).
- Log login success/failure, logout, password change, staff create/update/disable/reset.
- `GET admin/audit-logs` (ADMIN) with actor/action/target/date filters and pagination.
- Files: `app/Modules/Staff/Models/AuditLog.php`, `app/Modules/Staff/Actions/AuditLogger.php`, `AuditLogController.php`
- Done when: audit rows include the request ID and never contain a password.

### 1.7 Add auth and audit tests
- Cover login, CSRF, rate limits, session revocation, forced password change, and the role matrix.
- Files: `tests/Feature/Staff/**`
- Done when: the acceptance tests below pass.

## Acceptance Tests

- [x] **login_error_is_generic:** Given an unknown email and a wrong password for a known email, when each logs in, then both responses are identical 422s.
- [x] **login_requires_xsrf:** Given no XSRF token, when login is posted from the admin origin, then the response is 419.
- [x] **disabled_staff_is_logged_out_immediately:** Given an active session, when an Admin disables that user, then the user's next request returns 401.
- [x] **sales_cannot_manage_staff:** Given a `SALES_ORDERS` user, when they call `POST admin/staff`, then the response is 403 and nothing is created.
- [x] **forced_password_change_blocks_admin_routes:** Given `must_change_password = true`, when the user calls `GET admin/orders`, then the response is 403 `PASSWORD_CHANGE_REQUIRED`.
- [x] **audit_logs_are_immutable_and_redacted:** Given a password change, when the audit row is read, then it has no password field and updating it throws.

## Verification

- `./vendor/bin/pest --filter='Staff|Auth|Audit'`
- `php artisan test`
