# Phase 6 — Catalogue Import & Scheduled Jobs

**Goal:** Load and update the catalogue from the Excel workbook through a safe, repeatable Artisan command, and register the scheduled jobs that keep checkout, media, sessions, and SQLite healthy.
**Estimate:** ~3 days · **Depends on:** Phases 2 and 3.
**Source:** [§6.11 Excel import](../product-spec.md#611-excel-catalogue-import) · [§8 Reliability](../product-spec.md#reliability-and-performance) · `website-spec.md` §21

## Current Code Audit

- [x] Implemented 2026-10-13: `catalog:import` and eight scheduled jobs. See `docs/implementation/phase_6_progress.md`.

## Tasks

### 6.1 Implement dry-run report
- `php artisan catalog:import {file} --dry-run` reads the workbook (PhpSpreadsheet), maps columns from `website-spec.md` §21.2, and validates every row.
- Report counts (created/updated/unchanged/skipped/error) and a row table with sheet, row, field, reason, suggested fix; optional `--report=path.csv`.
- Unknown or missing columns stop the run with a proposed mapping instead of guessing.
- Files: `app/Modules/Catalog/Console/ImportCatalogCommand.php`, `app/Modules/Catalog/Import/`
- Done when: dry run writes nothing to the database.

### 6.2 Implement apply mode
- `--apply` takes a `VACUUM INTO storage/backups/catalog-{timestamp}.sqlite` snapshot first.
- Upserts by normalised product name + size; each product with its variants in its own transaction; derives `price_minor` with the +200 pesewas markup.
- Bundle sheet: skip the whole bundle if any component is invalid. Never deletes or unpublishes products missing from the workbook.
- Writes one audit row per run and dispatches one storefront revalidation.
- Files: `app/Modules/Catalog/Import/CatalogImporter.php`
- Done when: re-running the same workbook reports everything `unchanged`.

### 6.3 Implement image matching
- `--images=folder` matches filenames by normalised name plus `config/catalog_image_overrides.php`.
- Matched files go through the Phase 2 `StoreImage` action; ambiguous or missing matches are reported, never guessed.
- Files: `app/Modules/Catalog/Import/ImageMatcher.php`, `config/catalog_image_overrides.php`
- Done when: two plausible matches for one product produce an error row, not an upload.

### 6.4 Register scheduled jobs
- Hourly: expire `PENDING` checkout attempts past `expires_at` → `ABANDONED`.
- Every 15 minutes: re-verify `PENDING` attempts older than 15 minutes once with Paystack.
- Daily: delete media marked for deletion more than 30 days ago; prune expired sessions; delete failed checkout attempts older than 90 days; `PRAGMA optimize`.
- Hourly: check Litestream replication freshness and email Admin if stale for more than 10 minutes (wired in Phase 7).
- Files: `routes/console.php`, `app/Modules/*/Jobs/`
- Done when: `php artisan schedule:list` shows every job with the right frequency.

### 6.5 Add import and scheduler tests
- Use small fixture workbooks covering valid rows, blank prices, ambiguous sizes, bad bundles, and re-runs.
- Files: `tests/Feature/Catalog/Import/**`, `tests/Fixtures/catalog/`, `tests/Feature/Scheduler/**`
- Done when: the acceptance tests below pass.

## Acceptance Tests

- [x] **dry_run_does_not_mutate:** Given a valid workbook, when imported with `--dry-run`, then product and variant counts are unchanged.
- [x] **bad_row_does_not_block_good_rows:** Given one row with a blank price and nine valid rows, when applied, then nine variants are upserted and one error is reported.
- [x] **import_is_idempotent:** Given a workbook already applied, when applied again, then all rows report `unchanged`.
- [x] **bundle_with_invalid_component_is_skipped:** Given a bundle referencing an unknown size, when applied, then the bundle is unchanged and an error is reported.
- [x] **stale_attempts_are_abandoned:** Given a `PENDING` attempt past `expires_at`, when the expiry job runs, then its status is `ABANDONED`.

## Verification

- `./vendor/bin/pest --filter='Import|Scheduler'`
- `php artisan catalog:import tests/Fixtures/catalog/valid.xlsx --dry-run`
- `php artisan schedule:list`
