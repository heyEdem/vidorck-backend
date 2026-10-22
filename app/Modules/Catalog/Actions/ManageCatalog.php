<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\SlugRedirect;
use App\Modules\Catalog\Support\CatalogNormalizer;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageCatalog
{
    public function __construct(private AuditLogger $audit, private CatalogInvalidation $invalidation) {}

    /** @return list<Product> */
    public function products(): array
    {
        return Product::query()->with(['category', 'hairTypes', 'variants', 'activeMedia.owner'])
            ->orderBy('display_order')->orderBy('name')->orderBy('id')->get()->all();
    }

    /** @param array<string,mixed> $input */
    public function createProduct(StaffUser $actor, array $input): Product
    {
        try {
            $product = DB::transaction(function () use ($actor, $input): Product {
                CatalogActorGuard::assertAdmin($actor);
                $slug = $input['slug'] ?? Str::slug($input['name']);
                if ($slug === '' || mb_strlen($slug) > 255 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'A product slug could not be derived from its name.', ['slug' => ['Provide a lowercase kebab-case slug.']]);
                }
                $this->assertSlugAvailable($slug);
                $this->assertCategory($input['categoryId']);
                $product = Product::create($this->productFields($input, $slug));
                if (array_key_exists('hairTypeIds', $input)) {
                    $this->syncHairTypes($product, $input['hairTypeIds']);
                }
                foreach ($input['variants'] ?? [] as $variant) {
                    $this->insertVariant($product, $variant);
                }
                $product->load(['category', 'hairTypes', 'variants', 'activeMedia.owner']);
                $this->audit->record($actor, 'catalog.product.created', $product, after: $this->productSnapshot($product));

                return $product;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->validationConflict('The product or variant conflicts with an existing catalogue entry.', $exception);
        }
        $this->invalidation->product($product);

        return $product;
    }

    /** @param array<string,mixed> $input */
    public function updateProduct(StaffUser $actor, Product $routeProduct, array $input): Product
    {
        try {
            [$product, $oldSlug, $changed] = DB::transaction(function () use ($actor, $routeProduct, $input): array {
                CatalogActorGuard::assertAdmin($actor);
                $product = Product::query()->findOrFail($routeProduct->getKey());
                $before = $this->productSnapshot($product->load(['category', 'hairTypes', 'variants']));
                $oldSlug = $product->slug;
                $fields = $this->productFields($input, $input['slug'] ?? $oldSlug);
                $this->assertSlugAvailable($fields['slug'], 'product', $product->id);
                if (isset($fields['category_id'])) {
                    $this->assertCategory($fields['category_id']);
                }
                $product->fill($fields);
                $product->save();
                if (array_key_exists('hairTypeIds', $input)) {
                    $this->syncHairTypes($product, $input['hairTypeIds']);
                }
                if ($oldSlug !== $product->slug) {
                    $this->recordSlugChange('product', $oldSlug, $product->slug);
                }
                $product->load(['category', 'hairTypes', 'variants', 'activeMedia.owner']);
                $after = $this->productSnapshot($product);
                $changed = $before !== $after;
                if ($changed) {
                    $this->audit->record($actor, 'catalog.product.updated', $product, $before, $after);
                }

                return [$product, $oldSlug, $changed];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->validationConflict('The product conflicts with an existing catalogue entry.', $exception);
        }
        if ($changed) {
            $this->invalidation->product($product, $oldSlug === $product->slug ? null : $oldSlug);
        }

        return $product;
    }

    /** @return list<Category> */
    public function categories(): array
    {
        return Category::query()->withCount('products')->orderBy('display_order')->orderBy('name')->get()->all();
    }

    /** @param array<string,mixed> $input */
    public function createCategory(StaffUser $actor, array $input): Category
    {
        try {
            $category = DB::transaction(function () use ($actor, $input): Category {
                CatalogActorGuard::assertAdmin($actor);
                $this->assertCategoryUnique($input);
                $category = Category::create($this->categoryFields($input));
                $this->audit->record($actor, 'catalog.category.created', $category, after: $this->categorySnapshot($category));

                return $category;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->validationConflict('The category name or slug is already in use.', $exception);
        }
        $this->invalidation->categories($category);

        return $category;
    }

    /** @param array<string,mixed> $input */
    public function updateCategory(StaffUser $actor, Category $routeCategory, array $input): Category
    {
        try {
            [$category, $changed] = DB::transaction(function () use ($actor, $routeCategory, $input): array {
                CatalogActorGuard::assertAdmin($actor);
                $category = Category::query()->findOrFail($routeCategory->getKey());
                $before = $this->categorySnapshot($category);
                $this->assertCategoryUnique($input, $category->id);
                $category->fill($this->categoryFields($input));
                $category->save();
                $after = $this->categorySnapshot($category);
                $changed = $before !== $after;
                if ($changed) {
                    $this->audit->record($actor, 'catalog.category.updated', $category, $before, $after);
                }

                return [$category, $changed];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->validationConflict('The category name or slug is already in use.', $exception);
        }
        if ($changed) {
            $this->invalidation->categories($category);
        }

        return $category;
    }

    /** @return list<ProductVariant> */
    public function variants(Product $product): array
    {
        return ProductVariant::query()->where('product_id', $product->id)->orderBy('display_order')->orderBy('id')->get()->all();
    }

    /** @param array<string,mixed> $input */
    public function createVariant(StaffUser $actor, Product $routeProduct, array $input): ProductVariant
    {
        try {
            $variant = DB::transaction(function () use ($actor, $routeProduct, $input): ProductVariant {
                CatalogActorGuard::assertAdmin($actor);
                $product = Product::query()->findOrFail($routeProduct->getKey());
                $variant = $this->insertVariant($product, $input);
                $this->audit->record($actor, 'catalog.variant.created', $variant, after: $this->variantSnapshot($variant));

                return $variant;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->validationConflict('The variant conflicts with an existing product code or size.', $exception);
        }
        $this->invalidation->product($variant->product);

        return $variant;
    }

    /** @param array<string,mixed> $input */
    public function updateVariant(StaffUser $actor, Product $routeProduct, ProductVariant $routeVariant, array $input): ProductVariant
    {
        try {
            [$variant, $changed] = DB::transaction(function () use ($actor, $routeProduct, $routeVariant, $input): array {
                CatalogActorGuard::assertAdmin($actor);
                $product = Product::query()->findOrFail($routeProduct->getKey());
                $variant = ProductVariant::query()->where('product_id', $product->id)->findOrFail($routeVariant->getKey());
                $before = $this->variantSnapshot($variant);
                $fields = $this->variantFields($input);
                $this->assertProductCodeAvailable($fields['product_code'] ?? $variant->product_code, $variant->id);
                if (array_key_exists('label', $input)) {
                    $this->assertSizeAvailable($product->id, $input['label'], $variant->id);
                }
                $variant->fill($fields);
                $variant->save();
                $after = $this->variantSnapshot($variant);
                $changed = $before !== $after;
                if ($changed) {
                    $this->audit->record($actor, 'catalog.variant.updated', $variant, $before, $after);
                }

                return [$variant->load('product'), $changed];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->validationConflict('The variant conflicts with an existing product code or size.', $exception);
        }
        if ($changed) {
            $this->invalidation->product($variant->product);
        }

        return $variant;
    }

    public function setProductPublished(StaffUser $actor, Product $routeProduct, bool $published): Product
    {
        [$product, $changed] = DB::transaction(function () use ($actor, $routeProduct, $published): array {
            CatalogActorGuard::assertAdmin($actor);
            $product = Product::query()->findOrFail($routeProduct->getKey());
            $before = ['isPublished' => $product->is_published];
            $changed = $product->is_published !== $published;
            if ($changed) {
                $product->is_published = $published;
                $product->save();
                $this->audit->record($actor, $published ? 'catalog.product.published' : 'catalog.product.unpublished', $product, $before, ['isPublished' => $published]);
            }

            return [$product->load(['category', 'hairTypes', 'variants', 'activeMedia.owner']), $changed];
        }, 3);
        if ($changed) {
            $this->invalidation->product($product);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function productFields(array $input, string $slug): array
    {
        $fields = ['slug' => $slug];
        foreach (['name' => 'name', 'categoryId' => 'category_id', 'displayOrder' => 'display_order',
            'isPublished' => 'is_published', 'isNewLook' => 'is_new_look', 'imageAlt' => 'image_alt',
            'imagePosition' => 'image_position'] as $key => $column) {
            if (array_key_exists($key, $input)) {
                $fields[$column] = $input[$key];
            }
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function insertVariant(Product $product, array $input): ProductVariant
    {
        $fields = $this->variantFields($input);
        $fields['product_id'] = $product->id;
        $this->assertProductCodeAvailable($fields['product_code']);
        $this->assertSizeAvailable($product->id, $input['label']);

        return ProductVariant::create($fields);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function variantFields(array $input): array
    {
        $fields = [];
        foreach (['label' => 'size_label', 'productCode' => 'product_code', 'sourcePriceMinor' => 'source_price_minor',
            'priceMinor' => 'price_minor', 'isActive' => 'is_active', 'displayOrder' => 'display_order'] as $key => $column) {
            if (array_key_exists($key, $input)) {
                $fields[$column] = $input[$key];
            }
        }
        if (array_key_exists('sourcePriceMinor', $input) && $input['sourcePriceMinor'] !== null && ! array_key_exists('priceMinor', $input)) {
            $derivedPrice = ((int) $input['sourcePriceMinor']) + 200;
            if ($derivedPrice > 1_000_000_000) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'The derived price exceeds the catalogue limit.', ['sourcePriceMinor' => ['The derived price must not exceed 1000000000.']]);
            }
            $fields['price_minor'] = $derivedPrice;
        }

        return $fields;
    }

    /** @param list<int> $ids */
    private function syncHairTypes(Product $product, array $ids): void
    {
        $valid = HairType::query()->whereIn('id', $ids)->count();
        if ($valid !== count(array_unique($ids))) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'One or more hair types do not exist.', ['hairTypeIds' => ['Select existing hair types.']]);
        }
        $product->hairTypes()->sync($ids);
    }

    private function assertCategory(int $id): void
    {
        if (! Category::query()->whereKey($id)->exists()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The selected category does not exist.', ['categoryId' => ['Select an existing category.']]);
        }
    }

    /** @param array<string,mixed> $input */
    private function assertCategoryUnique(array $input, ?int $exceptCategoryId = null): void
    {
        foreach (['name', 'slug'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $query = Category::query()->where($field, $input[$field]);
            if ($exceptCategoryId !== null) {
                $query->whereKeyNot($exceptCategoryId);
            }
            if ($query->exists()) {
                $apiField = $field;
                throw new ApiException(422, 'VALIDATION_ERROR', 'The category '.$field.' is already in use.', [$apiField => ['The '.$field.' has already been taken.']]);
            }
        }
    }

    private function assertSizeAvailable(int $productId, string $label, ?int $exceptVariantId = null): void
    {
        $query = ProductVariant::query()->where('product_id', $productId)
            ->where('normalized_size', CatalogNormalizer::normalize($label));
        if ($exceptVariantId !== null) {
            $query->whereKeyNot($exceptVariantId);
        }
        if ($query->exists()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The product already has a variant with this size.', ['label' => ['The normalized size has already been taken for this product.']]);
        }
    }

    private function assertProductCodeAvailable(string $code, ?int $exceptVariantId = null): void
    {
        $variant = ProductVariant::query()->where('product_code', $code);
        if ($exceptVariantId !== null) {
            $variant->whereKeyNot($exceptVariantId);
        }
        if ($variant->exists() || DB::table('bundles')->where('product_code', $code)->exists()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The product code is already in use.', ['productCode' => ['The product code has already been taken.']]);
        }
    }

    private function assertSlugAvailable(string $slug, string $entityType = 'product', ?int $exceptId = null): void
    {
        $product = DB::table('products')->where('slug', $slug);
        $bundle = DB::table('bundles')->where('slug', $slug);
        if ($entityType === 'product' && $exceptId !== null) {
            $product->where('id', '<>', $exceptId);
        }
        if ($entityType === 'bundle' && $exceptId !== null) {
            $bundle->where('id', '<>', $exceptId);
        }
        if ($product->exists() || $bundle->exists() || SlugRedirect::query()->where('old_slug', $slug)->exists()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The slug is already in use or reserved by slug history.', ['slug' => ['The slug has already been taken.']]);
        }
    }

    private function recordSlugChange(string $entity, string $old, string $new): void
    {
        SlugRedirect::query()->where('entity_type', $entity)->where('new_slug', $old)->update(['new_slug' => $new]);
        SlugRedirect::create(['entity_type' => $entity, 'old_slug' => $old, 'new_slug' => $new]);
    }

    private function validationConflict(string $message, UniqueConstraintViolationException $exception): ApiException
    {
        return new ApiException(422, 'VALIDATION_ERROR', $message, ['catalogue' => ['Check slug, product code, and variant size uniqueness.']]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function categoryFields(array $input): array
    {
        $fields = [];
        foreach (['name' => 'name', 'slug' => 'slug', 'displayOrder' => 'display_order', 'isActive' => 'is_active'] as $key => $column) {
            if (array_key_exists($key, $input)) {
                $fields[$column] = $input[$key];
            }
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    private function productSnapshot(Product $product): array
    {
        return ['name' => $product->name, 'slug' => $product->slug, 'categoryId' => $product->category_id,
            'displayOrder' => $product->display_order, 'isPublished' => $product->is_published, 'isNewLook' => $product->is_new_look,
            'imageAlt' => $product->image_alt, 'imagePosition' => $product->image_position,
            'hairTypeIds' => $product->relationLoaded('hairTypes') ? $product->hairTypes->modelKeys() : [],
            'variants' => $product->relationLoaded('variants') ? $product->variants->map(fn (ProductVariant $v): array => $this->variantSnapshot($v))->all() : []];
    }

    /** @return array<string,mixed> */
    private function variantSnapshot(ProductVariant $variant): array
    {
        return ['label' => $variant->size_label, 'productCode' => $variant->product_code,
            'sourcePriceMinor' => $variant->source_price_minor, 'priceMinor' => $variant->price_minor,
            'isActive' => $variant->is_active, 'displayOrder' => $variant->display_order];
    }

    /** @return array<string,mixed> */
    private function categorySnapshot(Category $category): array
    {
        return ['name' => $category->name, 'slug' => $category->slug, 'displayOrder' => $category->display_order, 'isActive' => $category->is_active];
    }
}
