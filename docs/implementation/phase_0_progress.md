# Phase 0 implementation record

Scope: `docs/tasks/phase_0_foundation.md`, against product specification v1.0.

- Read the phase plan and specification. Repository initially contains documentation only.
- Consulted `claude-opus-5-5` for foundation pitfalls; confirmed connection-scoped pragmas, JSON exception handling, preserved HTTP headers, and global middleware placement before CORS.
- PHP and Composer are absent initially. Installing PHP 8.4 via Homebrew; Composer will be installed in a temporary tools directory.
- Scaffolded the official Laravel 12 skeleton without overwriting existing documents. Removed frontend asset tooling because this repository is an API application.
- Added acceptance tests before implementing foundation behavior.

Decisions:
- Keep implementation in the current workspace. No commits or pushes are part of this request.
- Keep the default scaffold user model and migration for now; Phase 1 replaces the authentication domain with staff users.
- Use Laravel's native SQLite connector settings, including `pragmas.temp_store`, so reconnects receive all settings without a custom connector.
- Test WAL against a temporary on-disk SQLite database; ordinary isolated tests may continue to use in-memory SQLite.
- Module route files remain empty until their feature phases. Each module receives separate public and admin route files grouped under the required prefixes.
- Maintain OpenAPI by hand in this foundation and compare registered paths/methods to it in CI; document only endpoints currently implemented.

- Dependency installation hit Composer's 300-second network timeout on PHPStan; retried with a 1200-second socket timeout using the cached other packages. Full test execution is delayed by this download; acceptance tests were written first, but most foundation behavior has not yet had a witnessed RED run. PHP syntax checks passed for all 45 files.

- Inspected the actual locked Laravel 12 connector source: its native `pragmas` configuration supports `temp_store`, so removed the custom connector and binding. Resource collections call item `resolve`, so camelCase mapping now runs after field selection; a small collection class supplies the specified pagination meta.


## Verification and review

- Installed Sanctum through `php artisan install:api`, preserving the versioned module loader and bootstrap configuration. Generated a local key and migrated the local SQLite database.
- Initial suite: 29 passed, 4 failed. Fixed real unauthenticated middleware redirects (500 instead of 401); corrected test assertions for object-valued JSON and SQLite's `timeout` result column.
- Non-strict money caller tests failed for floats, numeric strings and booleans; explicit integer validation made all cases pass.
- Opus 5.5 source review completed. Added regression coverage and addressed domain error codes, middleware response exceptions, nested resource values, and unsupported paginator types.
- Ruling: paginated API collections require a length-aware paginator because the contract includes total counts. Simple and cursor pagination fail explicitly at construction; endpoints needing another pagination contract must introduce it deliberately.
- Ruling: metadata explicitly supplied through resource `with()`/`additional()` uses camelCase at its source; automatic normalization covers resource fields and nested serializable values. Metadata callers must follow the documented convention.
- Final minor tracked for Phase 7: configure original-client-IP handling at the Cloudflare/Nginx boundary before production rate limits are enabled.
- Final checks: 50 Pest tests / 200 assertions passed; Pint passed; Larastan level 6 passed; Composer strict validation passed; config and route caches built successfully and were then cleared.
- At the initial implementation handoff, no commit, push, deployment, or remote CI execution had been performed. Dependency versions are pinned in composer.lock; GitHub Actions is ready for the next push/PR.

- Delivery follow-up: Product Owner explicitly requested pushing the completed Phase 0 implementation to `main`; rerun local checks, verify the remote state, then commit and push.
