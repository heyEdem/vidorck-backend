# Phase 6 — Catalogue Import & Scheduled Jobs progress

Plan: `docs/tasks/phase_6_import_scheduler.md` and `docs/implementation/phase_6_plan.md`.
Authority: `docs/product-spec.md` §§6.11, 8 and the frontend `website-spec.md` §21.
Branch: `feat/phase-6-import-scheduler`.
Implementation: Claude Sonnet 5.5 subagents. Advice: Claude Opus 5.5 (advisor). Plan and three review passes: Codex.
Workflow: operation commits, each verified with Pint, Larastan and tests.

## Operation ledger

| Commit | Plan task | Summary |
|---|---|---|
| `e6e4c97` | plan | Phase 6 implementation plan |
| `a74accf` | 6.1 | `phpoffice/phpspreadsheet` |
| `4b7486b` | 6.4 | Scheduled checkout, media, session and maintenance jobs; `scheduled_verified_at` |
| `c5f2bf4` | 6.4 | Fix: static analysis in retired media cleanup |
| `12ed1c4` | 6.1–6.3 | `catalog:import` dry-run, apply, snapshot, image matching; `docs/catalogue-import.md` |
| `2ad8abe` | 6.4 | Fix (Codex review): no downgrade of verified attempts, reference-shared orders, throttle-aware scheduled claim, session connection |
| `9355e28` | 6.2 | Fix (Codex review): collision-free variant keys, consistency across bad rows, code reservation on acceptance, hair-type dedupe, atomic audit + revalidation, unique snapshot names |
| `e8c9629` | 6.2 | Fix (Codex review): bundle code reservation on acceptance, key-preserving code merge |
| `658a1a6` / revert | 6.4 | Reverted: verified-attempt guard in `LinkAttemptToOrder` contradicted intended Phase 4 MISMATCH flagging |
| `1a81570` | 6.2 | PO decisions: create unknown categories/hair types; exit codes 0/2/1 |

Task 6.5 (tests) was written alongside each operation.

## Command

```
php artisan catalog:import path/to/workbook.xlsx --dry-run [--report=path.csv]
php artisan catalog:import path/to/workbook.xlsx --apply [--images=dir] [--report=path.csv]
```

- Exactly one mode. A missing or unknown header stops before any snapshot or write, printing a proposed mapping.
- Apply: `VACUUM INTO storage/backups/catalog-{timestamp-with-microseconds}.sqlite` (gitignored), then one transaction per product, bundles after products, then one transaction dispatching one `RevalidateStorefront` and appending one SYSTEM `catalog.import.applied` audit row. A no-change re-run still records both.
- Images (`--images`): override map, `image_key`, then normalised name; ambiguous or missing is an error row. A product with active media is `unchanged`. Uploads go through `StoreImage::storeAsSystem()` (no actor; Admin `store()` unchanged) after the product transaction commits, and each writes its own `catalogue.image_replaced` audit row.
- Unknown categories and hair types are created (inside the product's transaction) and listed as info lines. Exit codes: `0` clean, `2` row errors, `1` fatal.

## Schedule (`php artisan schedule:list`)

| Cron | Name |
|---|---|
| `0 * * * *` | `checkout:expire-stale-attempts` |
| `*/15 * * * *` | `checkout:reverify-pending-attempts` |
| `0 0 * * *` | `catalog:prune-retired-media`, `sessions:prune-expired`, `checkout:prune-failed-attempts`, `queue:prune-failed --hours=24`, `sqlite:optimize` |
| `0 * * * *` | `backups:check-litestream` (placeholder until 7.4) |

All use `withoutOverlapping()`. "No order" (`Checkout/Support/AttemptHasNoOrder`) means no linked order and no order whose `payment_reference` matches the attempt reference case-insensitively, in both the selection and the compare-and-set write. Expiry is not audited, matching existing `FAILED`/`ABANDONED` transitions.

## Schema

Migration `2026_10_13_090000_add_scheduled_verified_at_to_checkout_attempts` (additive): nullable `scheduled_verified_at` plus `(status, scheduled_verified_at, created_at)` index. Applied locally.

## Tests

`tests/Feature/Catalog/Import/{CatalogImportTest,CatalogImportAtomicityTest,CatalogSnapshotTest}.php`, `tests/Feature/Scheduler/ScheduledJobsTest.php`, fixture `tests/Fixtures/catalog/valid.xlsx`, helper `tests/Support/CatalogWorkbook.php`. All five acceptance tests are present under their exact names.

## Verification (2026-10-13)

- `php artisan test`: 499 passed (3388 assertions).
- `./vendor/bin/pint --test`: passed. `./vendor/bin/phpstan analyse --memory-limit=1G`: no errors.
- `php artisan migrate --force`: additive migration applied locally.
- `php artisan schedule:list`: all eight entries with the frequencies above.
- `catalog:import tests/Fixtures/catalog/valid.xlsx --dry-run` on a throwaway seeded database: 8 created, 0 errors.
