# Phase 1 — execution record

Plan: `docs/tasks/phase_1_staff_auth_audit.md`.
Authority: `docs/product-spec.md` §§5.5, 6.7, 6.8, 6.12, and website §17.

## Pre-flight

- Staff schema supplies the web guard, policies, session middleware, and audit actors.
- Shared admin middleware protects every module; login alone is public.
- Password changes, account changes, and audit writes must be transactional.
- Existing camelCase resources and the error envelope remain the HTTP contract.
- Work is isolated on `feat/phase-1-staff-auth-audit`; no push is authorized.

## Tasks

- 1.1: complete — 3 schema tests / 10 assertions, Pint and Larastan pass.
- 1.2: complete — bootstrap tests pass; shared strength validation.
- 1.3: complete — 9 auth tests; full suite 69 tests / 284 assertions, Pint and Larastan pass.
- 1.4: complete — current/strong/confirmed password checks, reset and forced-change flow tested.
- 1.5: complete — staff management, immediate revocation, and full role-matrix tests pass (119 assertions).
- 1.6: complete — redaction, model/bulk immutability, request-ID correlation and filtered HTTP access pass.
- 1.7: complete — 63 staff tests plus 50 foundation tests; final full suite 113 tests / 688 assertions passes after the Opus fix pass.

Opus 5.5 design consultation completed: advised Sanctum without API-token trait, session version checks, database audit triggers, last-active-Admin protection, reset throttling, and explicit CSRF tests.
Ruling: add `session_version` alongside session-row deletion — rejects stale session resurrection and works across session drivers; cost if wrong: one internal schema column.
Ruling: prevent self-disable/self-demotion/self-reset and removal of the last active Admin — avoids locking the business out; self password changes use the password endpoint; cost if wrong: another Admin or CLI recovery is required for those account changes.
Ruling: staff creation issues a generated temporary password shown once, matching the reset flow — no user-chosen initial credentials or self-registration; cost if wrong: clients must handle `temporaryPassword` when creating staff.
Ruling: use a strong local password policy without external breach-lookup calls — avoids a network dependency in credential changes; cost if wrong: no breached-password screening in v1.
Ruling: temporary credentials remain usable until changed or reset, with forced-change access restrictions — matches the backend spec's forced temporary credential option; cost if wrong: add an expiry column and expiry UI later.

Failure-path verification: audit insert failures reproduce partial-login persistence and logout failure; login now rolls back DB changes and explicitly clears session state, while logout invalidates in `finally`. Staff mutation failures roll back the account change, session deletion, and audit together.
Password-manager verification: excluded camelCase password fields from Laravel TrimStrings; regression test verifies leading/trailing spaces are preserved.

## Verification

- Final `composer check`: Pint, Larastan level 6, and all 113 tests / 688 assertions passed.
- `composer validate --strict`: passed.
- `php artisan config:cache` and `php artisan route:cache`: passed; nine admin routes registered.
- Route/config caches cleared afterward to preserve local development behavior.
- Tests use isolated SQLite databases; no existing application data was deleted.
- Applied the two additive migrations to the local application database.

## Final review

Read-only Opus 5.5 review completed; full dispositions are in `phase_1_review.md`.
The review found two Medium findings and seven Low findings (its introduction
said six). Regraded by user impact, five additional security/correctness issues
were addressed or documented alongside the two Medium fixes.

Final: fixed SQLite lock duration — password-verification transaction-depth and reset-during-validation regressions RED→GREEN.
Final: fixed unlimited lockout audit writes — stateless/repeated-lockout regression RED→GREEN.
Final: fixed actor authorization race — nine stale-actor mutation regressions RED→GREEN.
Final: fixed session-check priority — forced/expired missing-ID regressions RED→GREEN.
Final: fixed inclusive date filter — date-only end-date regression RED→GREEN.
Final: Ruling: audit fields are separate redacted before/after summaries and `ip_address` — aligned the product-spec data-model table with the logger interface — cost if wrong: migrate the column representation before external consumers depend on it.
Final: minor (deferred): bootstrap role enum is over-redacted in its audit summary.
Final: minor (deferred): conservative key/payment redaction may hide useful summaries in future phases.
Final: minor (deferred): duplicate-email create races can return 500 instead of 422 while uniqueness and rollback remain enforced.

Final fix-pass verification: suite 113/113 green (688 assertions), Pint and Larastan pass. All reproduced Important findings above went RED→GREEN before this suite run. Increment-first throttling is covered by the existing threshold test; audit schema naming was a documentation alignment.

Ruling: implement audit storage before authentication — login and staff mutations need transactional audit writes from their first implementation; cost if wrong: task-order-only change.
Ruling: enforce audit immutability with SQLite triggers as well as model hooks — query-builder writes bypass Eloquent events; cost if wrong: explicit retention migrations must drop triggers.
