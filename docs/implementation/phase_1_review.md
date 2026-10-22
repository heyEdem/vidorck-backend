# Phase 1 — Opus 5.5 review

Reviewer: `claude-opus-5-5`, invoked through Claude CLI for read-only review of
the plan, spec, Staff implementation, framework source, and tests. Runtime
verification was performed separately. The reviewer saw the latest sources;
the findings below were reproduced and addressed in a single fix pass.

| Finding | Disposition |
|---|---|
| Password verification/timeboxing holds SQLite's IMMEDIATE write lock | Fixed: verify and hash outside transactions, then recheck stored hash, active state, and session version inside a short transaction. Login rehashes use the same compare-before-write check. Regression test checks transaction depth during real password verification. |
| Login lockouts can generate unlimited permanent audit rows | Fixed: reject stateless requests before throttling, audit once per limiter window using atomic cache insertion, and store an email hash instead of arbitrary input. Regression test sends 35 stateless requests and repeated blocked SPA attempts. |
| Concurrent requests can exceed a check-then-increment login limit | Regraded Important and fixed: increment atomically first and reject counts above the limit. Existing email/IP limit tests verify the threshold. |
| An Admin disabled/demoted/revoked during a request can finish a staff mutation | Regraded Important and fixed: recheck the actor inside every staff mutation transaction. Nine regression cases cover create/update/reset against disabled/demoted/revoked actors. |
| Bindings can run before session and forced-password checks | Regraded Important and fixed for session restrictions: register explicit middleware priority. Missing staff IDs now return 401 for expired sessions and 403 for forced-change sessions. Role policy authorization still follows binding, as in Laravel's normal policy pipeline. |
| Date-only audit upper bounds exclude the rest of that day | Regraded Important and fixed: date-only `to` uses UTC end-of-day; explicit timestamps remain exact. Added a filtered-query regression assertion. |
| Audit column names differ from the data-model table | Aligned the spec with the implementation: separate redacted `before` / `after` JSON and `ip_address`; audit intent is unchanged. |

## Deferred minors

- Bootstrap audit summaries replace the backed role enum with `[redacted]`.
  Bootstrap action and target still identify the Admin creation; normal HTTP
  staff mutations record the string role correctly.
- Conservative redaction also removes non-secret `payment` / `key` fields.
  No Phase 1 audit summary uses those fields. Revisit explicit safe summaries
  when adding payment and idempotency auditing in later phases; secrets remain
  excluded throughout.
- Concurrent staff creates with the same email can produce a 500 for the losing
  request after both pass FormRequest uniqueness validation. The database unique
  constraint prevents duplicate accounts and the transaction prevents partial
  audit/account changes. Translate that constraint failure to 422 in a follow-up.

The reviewer found no auth bypass, CSRF gap, session-revocation gap, last-Admin
bypass, or password-reset flaw. All Important findings above have regression
coverage; no second model review was used as a substitute for runtime tests.

Final local verification: `composer check` passed — **113 tests / 688 assertions**,
Pint, and Larastan level 6. Composer manifest validation and configuration/route
caching also passed. Both additive migrations were applied locally.
