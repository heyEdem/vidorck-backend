# Phase 2 — Catalogue & media progress

Plan: `docs/tasks/phase_2_catalogue_media.md`. Authority: `docs/product-spec.md`.
Branch: `feat/phase-2-catalogue-media`.
Implementation: Luna subagents. Reviews and second opinions: Claude Opus 5.5.

## Task ledger

| Task | Status | Shared interfaces / checks |
|---|---|---|
| 2.1 Schema and structural seeders | Complete | Ten schema tests pass; Opus seeder/negative-money findings reproduced and fixed. |
| 2.2 Public APIs | Complete | Public API tests pass; explicit public allowlist and global facets. |
| 2.3 Admin CRUD | Complete | Uses 2.1 models, Staff gates/audit, and 2.6 invalidation. |
| 2.4 Bundles | Complete | Uses ordered variant items; stored price authoritative; all components visible. |
| 2.5 Images | Complete | Uses Media owner relation and Staff auditing; retains replaced media for 30 days. |
| 2.6 Revalidation | Complete | One after-commit job per action; old/new slug and dependent bundle tags. |
| 2.7 Tests and integration | Complete | API contract parity, Pest, Pint, Larastan, clean scratch-database migration. |

## Rulings

- Work on a new feature branch in the shared checkout so delegated changes are immediately reviewable.
- Public image objects and exclusion of source prices follow the backend spec; the current frontend's string image and source-price field require its already-planned migration.
- Bundle fixtures belong in tests; production seeders create structural catalogue data only. Bundle `eyebrow` is additive for compatibility with the existing bundle type.
- Delivery-area schema and CRUD belong to Phase 3; Phase 2 supplies reusable tag dispatch for that future integration.
- Use camelCase API inputs and metadata as mandated by the API conventions; retain `hair_type` as the documented query parameter.
- Media includes `retired_at`; an SQLite partial unique index enforces one current image per owner. Phase 6 prunes files after 30 days.
- Small images are not upscaled; all 400/800/1200 rendition keys remain available with widths capped at the source width.
- Slug redirects keep the spec's columns, flatten history on rename, and reserve historical aliases rather than requiring a new entity-id column.
- Use the existing Phase 1 fresh-actor recheck pattern. Mandatory optimistic-locking versions are outside this phase's API contract.
- Structural reseeding preserves category edits; SQLite triggers reject negative variant/source/bundle prices.
- Large-image processing uses one decode and descending resizes; a pre-decode memory guard rejects insufficient capacity. PHP upload settings require 512M memory, 8M uploads, 10M POST; Nginx body limit 10m.
- Category changes invalidate affected product and dependent bundle detail tags as well as catalogue lists.

## Verification

| Check | Result |
|---|---|
| `composer check` | Passed: Pint, Larastan level 6, 171 tests / 1089 assertions |
| Catalogue suite | 55 tests, including all six acceptance requirements |
| `composer validate --strict` | Passed |
| OpenAPI route parity and schema references | Passed |
| Fresh migration and structural seed in production mode | Passed against isolated in-memory SQLite; no staff seeded |
| Configuration and route caching | Passed using isolated `/tmp` cache files |
| Local HTTP smoke | Categories (11), hair types (2), empty products/bundles, and `/media/*` returned 200 |
| Local setup | Additive migration, structural catalogue seeder, and storage symlinks applied |
| Opus 5.5 | Preflight and implementation findings addressed; see `phase_2_review.md` |

The complete code and docs remain on `feat/phase-2-catalogue-media`; no commit or
push was requested. Frontend migration and revalidation endpoint remain in
`VHI_Website`; scheduled media pruning remains in Phase 6.
