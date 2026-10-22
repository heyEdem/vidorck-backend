# Catalogue import

```
php artisan catalog:import book.xlsx --dry-run|--apply [--images=dir] [--report=issues.csv]
```

Exactly one of `--dry-run` (no writes at all) or `--apply`. Apply first takes `VACUUM INTO storage/backups/catalog-{timestamp}.sqlite` (abort on failure), writes each product with its variants and hair types in its own transaction, then bundles, then one `catalog.import.applied` audit row and one storefront revalidation. Re-running the same workbook reports everything `unchanged`. Nothing absent from the workbook is deleted or unpublished. `--report` writes the issue table (sheet, row, field, reason, suggested fix) as CSV.

## Exit codes

`0` clean run; `2` run completed but with row errors (valid rows are still applied or validated); `1` fatal (header mismatch, snapshot failure, bad arguments, unreadable file). Applies to both `--dry-run` and `--apply`.

## Header checkpoint

Headers must match exactly (case-sensitive, any column order). Missing, unknown or duplicate headers stop the run before any snapshot or write; the command prints expected vs found and a proposed rename mapping for approval.

## Sheet `Products` (one row per product size)

| Header | Required | Rule |
|---|---|---|
| product_name | yes | max 255; match key is the normalised name (lowercase, ASCII, squished) |
| size | yes | max 255; normalised; one row per size per product |
| price_ghs | yes | exact decimal, at most 2 places, > 0. `source_price_minor` = price x 100; `price_minor` = source + 200 |
| category | yes | existing category by normalised name or slug; otherwise it is created (name = trimmed value, slug from the name, active, next display order) |
| hair_types | yes | `;`-separated hair types (name or slug); unknown ones are created like categories; may be blank |
| is_published | yes | 0 or 1 |
| display_order | yes | whole number >= 0 |
| is_new_look | yes | 0 or 1 |
| image_key | no | image filename or mapping key; defaults to product_name |
| product_code | no | existing variant code is preserved if blank; new variants get a deterministic `VHI-` code |

Product-level columns (category, hair_types, is_published, display_order, is_new_look, image_key) must agree across a product's rows, otherwise the whole product is rejected. A bad row (blank price, bad value, duplicate size) skips only that row. New products get a slug from the name; a clash with a product, bundle or slug redirect is an error. Slug, image alt and image position of existing products are never changed. Variant order is the row order within the product; `is_active` is kept for existing variants and true for new ones.

Unknown categories and hair types are created inside the owning product's transaction (a rolled-back product leaves none behind) and reused within the run. They appear as `category` / `hair_type` rows in the counts table and as an info line per new name in the report (also in the CSV, prefixed `INFO:`), so typos are visible. A dry run only reports "Would create"; the apply audit row includes these counts.

## Sheet `Bundles` (optional; one row per component)

| Header | Required | Rule |
|---|---|---|
| bundle_name | yes | match key is the normalised name |
| fixed_price_ghs | yes | exact decimal, no markup; same on every row of the bundle |
| product_name, size | yes | must resolve to exactly one existing or workbook variant |
| quantity | yes | whole number >= 1 |
| display_order | yes | component order |
| bundle_code | no | preserved if blank; new bundles get a deterministic `BDL-` code |
| is_published | no | 0 or 1; blank keeps the current value (new bundles are unpublished) |

Any invalid, unknown, ambiguous or duplicate component skips the entire bundle (reported as an error).

## Images (`--images=dir`)

Without the flag images are not checked. With it, each product without an active image is matched by the normalised `image_key` (or product name) against `.jpg/.jpeg/.png/.webp` files; case, punctuation, whitespace and the suffixes `-final`, `-copy`, `-web`, `-edited`, `-compressed`, `@2x`, `(1)` are ignored. `config/catalog_image_overrides.php` (normalised name or key => filename) is consulted first. No match, or two plausible matches, is an error row and nothing is uploaded. Products that already have an active image are left alone.
