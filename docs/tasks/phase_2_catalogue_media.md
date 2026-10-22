# Phase 2 — Catalogue & Media

**Goal:** Serve the published catalogue and bundles to the storefront in the shape the existing frontend types expect, let Admin manage products, prices, bundles, and images, and refresh the storefront when anything changes.
**Estimate:** ~4-5 days · **Depends on:** Phases 0 and 1.
**Source:** [§5.1 Catalogue](../product-spec.md#51-catalogue) · [§6.1 Catalogue API](../product-spec.md#61-catalogue-api-public) · [§6.2 Images](../product-spec.md#62-images-admin-upload) · [§6.10 Revalidation](../product-spec.md#610-storefront-revalidation) · `website-spec.md` §8–10

## Current Code Audit

- [x] Catalogue schema, public/admin APIs, media processing, and revalidation are implemented. `VHI_Website/src/data/catalog.ts` and `bundles.ts` provide the compatibility fixtures; safe image/source-price changes follow the backend spec.

## Tasks

### 2.1 Add catalogue schema and seeders
- Migrations for `categories`, `hair_types`, `products`, `product_hair_type`, `product_variants`, `media`, `bundles`, `bundle_items`, `slug_redirects` with indexes from spec §5.6.
- Seed categories, hair types (Natural Hair, Permed Hair), and nothing else.
- Files: `database/migrations/`, `app/Modules/Catalog/Models/`, `database/seeders/`
- Done when: `migrate:fresh --seed` succeeds and foreign keys block orphan variants.

### 2.2 Implement public product and bundle APIs
- `GET products` (`q`, `category`, `hair_type`, 15 per page, facet counts), `GET products/{slug}`, `GET bundles`, `GET bundles/{slug}`, `GET categories`, `GET hair-types`.
- Publish filter applied in queries; `normalized_name` search (case + diacritic insensitive).
- Resources mirror `StorefrontProduct` / `ProductVariant`; never expose `source_price_minor` or internal IDs.
- Files: `app/Modules/Catalog/Http/Controllers/Public/`, `app/Modules/Catalog/Http/Resources/`
- Done when: the response for a seeded product lines up field for field with the matching entry in `catalog.ts`.

### 2.3 Implement admin catalogue CRUD
- `GET/POST/PATCH admin/products`, `.../publish`, `.../unpublish`, `GET/POST/PATCH admin/products/{id}/variants`, `GET/POST/PATCH admin/categories`.
- Price form accepts `source_price_minor` and derives `price_minor` (+200 pesewas), or accepts an explicit `price_minor`.
- Changing a slug writes a `slug_redirects` row; public `GET products/{old}` returns 301 with `redirectTo`.
- Every write is audited; writes are ADMIN only, reads are both roles.
- Files: `app/Modules/Catalog/Http/Controllers/Admin/`, `app/Modules/Catalog/Actions/`
- Done when: `SALES_ORDERS` gets 403 on every catalogue write.

### 2.4 Implement bundle management
- `GET/POST/PATCH admin/bundles` with items (variant + quantity + order), publish/unpublish.
- Helper that computes the suggested set price (sum of item prices − 300 pesewas) for Admin to confirm; the stored `price_minor` is authoritative.
- A bundle is hidden publicly if any component is unpublished or inactive.
- Files: `app/Modules/Catalog/Actions/BundlePricing.php`, `BundleController.php`
- Done when: the seeded Natural Hair Set computes to 14300 and Permed Hair Set to 14400 against current prices.

### 2.5 Implement image upload pipeline
- `POST admin/products/{id}/image` and `POST admin/bundles/{id}/image` (multipart, JPEG/PNG/WebP, ≤ 8 MB, ≤ 6000 px).
- Intervention Image (GD): validate real MIME, strip EXIF, write original + WebP at 400/800/1200 to `storage/app/public/media/{ulid}/`.
- Replaced media are marked for deletion after 30 days, not deleted immediately.
- Files: `app/Modules/Catalog/Actions/StoreImage.php`, `app/Modules/Catalog/Models/Media.php`, `config/filesystems.php`
- Done when: the API returns `image.urls` for each width and the old URL still resolves after replacement.

### 2.6 Implement storefront revalidation
- `RevalidateStorefront` queued job posting tags to `STOREFRONT_REVALIDATE_URL` with `REVALIDATE_SECRET` header; retries with backoff.
- Fired by model events/actions for products, variants, bundles, media, categories, delivery areas.
- Files: `app/Modules/Catalog/Jobs/RevalidateStorefront.php`
- Done when: a price change dispatches one job with `products` and `product:{slug}` tags.

### 2.7 Add catalogue tests
- Cover visibility, search, pagination, price derivation, bundle hiding, slug redirects, image upload, and role checks.
- Files: `tests/Feature/Catalog/**`
- Done when: the acceptance tests below pass.

## Acceptance Tests

- [x] **unpublished_product_is_invisible_everywhere:** Given an unpublished product, when listing, searching, filtering, fetching by slug, or listing bundles, then it never appears and its slug returns 404.
- [x] **public_responses_hide_source_price:** Given any public catalogue response, when inspected, then no `sourcePriceMinor` or internal `id` field exists.
- [x] **search_is_case_and_accent_insensitive:** Given "Natural Shampoo", when searching `natural shampóo`, then it is returned.
- [x] **bundle_hidden_when_component_unpublished:** Given a published bundle, when one component is unpublished, then the bundle disappears from public APIs.
- [x] **image_upload_creates_webp_renditions:** Given a 3000 px JPEG, when Admin uploads it, then 400/800/1200 WebP files exist and EXIF is stripped.
- [x] **non_image_upload_is_rejected:** Given a `.php` file renamed `.jpg`, when uploaded, then the response is 422 and nothing is stored.

## Verification

- `./vendor/bin/pest --filter='Catalog|Bundle|Media'`
- `php artisan test`

Implementation and verified results: [Phase 2 progress](../implementation/phase_2_progress.md) · [Opus 5.5 review](../implementation/phase_2_review.md).
