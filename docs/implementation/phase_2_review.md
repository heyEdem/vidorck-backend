# Phase 2 — Opus 5.5 review

Reviewer: `claude-opus-5-5`, invoked through Claude CLI for read-only preflight,
schema, public/media, and full catalogue reviews. Tests and runtime verification
were performed separately by Luna implementers and the parent agent.

| Finding | Resolution |
|---|---|
| Structural reseeding overwrites Admin category edits | Seed only missing defaults, matching existing name or slug. Regression preserves renamed, reordered, and inactive categories. |
| SQLite ignores unsigned money columns | Insert/update triggers reject negative variant/source/bundle prices; direct-database regression tests cover the guards. |
| Large images repeatedly decode full-resolution pixels and risk exhaustion | Decode once, create descending renditions, and check a conservative memory budget before decoding. Tests reject constrained-memory uploads safely and accept 6000×4000 at 512M. PHP/Nginx upload requirements are documented. |
| Category changes miss product and bundle detail tags | Pass the affected category to invalidation and include affected product/dependent bundle detail tags. Integration tests check tags and public hiding. |
| Catalogue validation errors use a different machine code | Use the established `VALIDATION_ERROR`; duplicate normalized sizes and category names/slugs produce field-specific errors. Unique indexes remain the race fallback. |
| Admin OpenAPI differs from runtime resources | Remove nonexistent normalizedName, document productId and nullable sourcePriceMinor, and permit negative unclamped suggested prices. Route parity and schema references verified. |
| Embedded Admin variants omit productId required by the shared schema | Reuse the standalone Admin VariantResource for product variants; regression compares both representations and checks OpenAPI required fields. |
| Visibility acceptance coverage omits search/filters and unpublished bundle components | Added endpoint-driven product unpublish, every public lookup/filter, bundle list/detail, and category-deactivation coverage. |

Additional verification covers real outer-transaction commit/rollback behavior,
fresh actor checks, rollback when audit insertion fails, immutable image
replacement paths, EXIF stripping, and retrying redirects without forwarding
the shared secret to another URL.

## Deferred to planned phases

- Phase 6 implements 30-day retired-media pruning and orphan-directory cleanup
  for process termination between filesystem writes and database commit.
- Phase 3 connects delivery-area writes to the available invalidation helper.
- The separate frontend migration consumes safe public resources and implements
  the actual shared-secret revalidation endpoint.
- Structural defaults can be recreated if both the name and slug of a seeded
  category are changed; this creates a new empty category and does not alter
  existing products or reactivate their category.

Final local checks: `composer check` passed **171 tests / 1089 assertions**, Pint,
and Larastan level 6. Composer validation, OpenAPI parity, fresh production-mode
in-memory migration/seeding, isolated config/route caching, and local HTTP
catalogue/media-link checks passed. The additive migration and structural
seeder were applied to the local database.

Scoped re-reviews confirmed the memory, invalidation, validation, transaction,
and OpenAPI fixes. The last contract review explicitly confirmed the shared
Admin variant resource resolves the remaining productId gap. No Critical or
Important review finding remains unresolved.
