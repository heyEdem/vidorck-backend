<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\SlugRedirect;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ManageBundles
{
    public function __construct(private AuditLogger $audit, private CatalogInvalidation $invalidation, private BundlePricing $pricing) {}

    /** @return list<Bundle> */
    public function bundles(): array
    {
        return Bundle::query()->with(['items.variant.product.category', 'items.variant.product.hairTypes', 'items.variant.product.variants',
            'items.variant.product.activeMedia.owner', 'activeMedia.owner'])
            ->orderBy('display_order')->orderBy('name')->orderBy('id')->get()->all();
    }

    /** @param array<string,mixed> $input */
    public function create(StaffUser $actor, array $input): Bundle
    {
        try {
            $bundle = DB::transaction(function () use ($actor, $input): Bundle {
                CatalogActorGuard::assertAdmin($actor);
                $this->assertSlugAvailable($input['slug']);
                $this->assertProductCodeAvailable($input['productCode']);
                $this->assertItems($input['items']);
                $bundle = Bundle::create($this->fields($input));
                $this->replaceItems($bundle, $input['items']);
                $bundle->load($this->relations());
                $this->audit->record($actor, 'catalog.bundle.created', $bundle, after: $this->snapshot($bundle));

                return $bundle;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->uniqueConflict();
        }
        $this->invalidation->bundle($bundle);

        return $bundle;
    }

    /** @param array<string,mixed> $input */
    public function update(StaffUser $actor, Bundle $routeBundle, array $input): Bundle
    {
        try {
            [$bundle, $oldSlug, $changed] = DB::transaction(function () use ($actor, $routeBundle, $input): array {
                CatalogActorGuard::assertAdmin($actor);
                $bundle = Bundle::query()->findOrFail($routeBundle->getKey());
                $before = $this->snapshot($bundle->load($this->relations()));
                $oldSlug = $bundle->slug;
                $fields = $this->fields($input);
                $this->assertSlugAvailable($fields['slug'] ?? $oldSlug, $bundle->id);
                $this->assertProductCodeAvailable($fields['product_code'] ?? $bundle->product_code, $bundle->id);
                if (array_key_exists('items', $input)) {
                    $this->assertItems($input['items']);
                }
                $bundle->fill($fields)->save();
                if (array_key_exists('items', $input) && ! $this->itemsEquivalent($bundle, $input['items'])) {
                    $this->replaceItems($bundle, $input['items']);
                }
                if ($oldSlug !== $bundle->slug) {
                    $this->recordSlugChange($oldSlug, $bundle->slug);
                }
                $bundle->load($this->relations());
                $after = $this->snapshot($bundle);
                $changed = $before !== $after;
                if ($changed) {
                    $this->audit->record($actor, 'catalog.bundle.updated', $bundle, $before, $after);
                }

                return [$bundle, $oldSlug, $changed];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->uniqueConflict();
        }
        if ($changed) {
            $this->invalidation->bundle($bundle, $oldSlug === $bundle->slug ? null : $oldSlug);
        }

        return $bundle;
    }

    public function setPublished(StaffUser $actor, Bundle $routeBundle, bool $published): Bundle
    {
        [$bundle, $changed] = DB::transaction(function () use ($actor, $routeBundle, $published): array {
            CatalogActorGuard::assertAdmin($actor);
            $bundle = Bundle::query()->findOrFail($routeBundle->getKey());
            $before = ['isPublished' => $bundle->is_published];
            $changed = $bundle->is_published !== $published;
            if ($changed) {
                $bundle->is_published = $published;
                $bundle->save();
                $this->audit->record($actor, $published ? 'catalog.bundle.published' : 'catalog.bundle.unpublished', $bundle,
                    $before, ['isPublished' => $published]);
            }

            return [$bundle->load($this->relations()), $changed];
        }, 3);
        if ($changed) {
            $this->invalidation->bundle($bundle);
        }

        return $bundle;
    }

    /** @param list<array{variantId:int,quantity:int,displayOrder?:int}> $items */
    public function suggestedPrice(array $items): int
    {
        return $this->pricing->suggestedPrice($items);
    }

    /** @param list<array{variantId:int,quantity:int,displayOrder?:int}> $items */
    private function assertItems(array $items): void
    {
        if ($items === []) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'A bundle must contain at least one variant.', ['items' => ['Add at least one item.']]);
        }
        $ids = array_column($items, 'variantId');
        if (count($ids) !== count(array_unique($ids))) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'A bundle cannot contain a variant more than once.', ['items' => ['Variant IDs must be distinct.']]);
        }
        if (ProductVariant::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'One or more variants do not exist.', ['items' => ['Select existing variants.']]);
        }
    }

    /** @param list<array{variantId:int,quantity:int,displayOrder?:int}> $items */
    private function replaceItems(Bundle $bundle, array $items): void
    {
        BundleItem::query()->where('bundle_id', $bundle->id)->delete();
        foreach ($items as $index => $item) {
            BundleItem::create(['bundle_id' => $bundle->id, 'product_variant_id' => $item['variantId'],
                'quantity' => $item['quantity'], 'display_order' => $item['displayOrder'] ?? $index]);
        }
    }

    /** @param list<array{variantId:int,quantity:int,displayOrder?:int}> $items */
    private function itemsEquivalent(Bundle $bundle, array $items): bool
    {
        $current = $bundle->items->map(fn (BundleItem $item): array => [
            'variantId' => (int) $item->product_variant_id,
            'quantity' => (int) $item->quantity,
            'displayOrder' => (int) $item->display_order,
        ])->all();
        $requested = [];
        foreach ($items as $index => $item) {
            $requested[] = ['variantId' => (int) $item['variantId'], 'quantity' => (int) $item['quantity'],
                'displayOrder' => (int) ($item['displayOrder'] ?? $index), '_index' => $index];
        }
        usort($requested, fn (array $left, array $right): int => $left['displayOrder'] <=> $right['displayOrder'] ?: $left['_index'] <=> $right['_index']);
        $requested = array_map(fn (array $item): array => ['variantId' => $item['variantId'], 'quantity' => $item['quantity'], 'displayOrder' => $item['displayOrder']], $requested);

        return $current === $requested;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function fields(array $input): array
    {
        $fields = [];
        foreach (['name' => 'name', 'slug' => 'slug', 'productCode' => 'product_code', 'eyebrow' => 'eyebrow',
            'priceMinor' => 'price_minor', 'isPublished' => 'is_published', 'displayOrder' => 'display_order', 'imageAlt' => 'image_alt'] as $key => $column) {
            if (array_key_exists($key, $input)) {
                $fields[$column] = $input[$key];
            }
        }

        return $fields;
    }

    private function assertSlugAvailable(string $slug, ?int $exceptBundleId = null): void
    {
        $bundle = DB::table('bundles')->where('slug', $slug);
        if ($exceptBundleId !== null) {
            $bundle->where('id', '<>', $exceptBundleId);
        }
        if ($bundle->exists() || DB::table('products')->where('slug', $slug)->exists()
            || SlugRedirect::query()->where('old_slug', $slug)->exists()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The slug is already in use or reserved by slug history.', ['slug' => ['The slug has already been taken.']]);
        }
    }

    private function assertProductCodeAvailable(string $code, ?int $exceptBundleId = null): void
    {
        $bundle = DB::table('bundles')->where('product_code', $code);
        if ($exceptBundleId !== null) {
            $bundle->where('id', '<>', $exceptBundleId);
        }
        if ($bundle->exists() || DB::table('product_variants')->where('product_code', $code)->exists()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The product code is already in use.', ['productCode' => ['The product code has already been taken.']]);
        }
    }

    private function recordSlugChange(string $old, string $new): void
    {
        SlugRedirect::query()->where('entity_type', 'bundle')->where('new_slug', $old)->update(['new_slug' => $new]);
        SlugRedirect::create(['entity_type' => 'bundle', 'old_slug' => $old, 'new_slug' => $new]);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['items.variant.product.category', 'items.variant.product.hairTypes', 'items.variant.product.variants',
            'items.variant.product.activeMedia.owner', 'activeMedia.owner'];
    }

    /** @return array<string,mixed> */
    private function snapshot(Bundle $bundle): array
    {
        return ['name' => $bundle->name, 'slug' => $bundle->slug, 'productCode' => $bundle->product_code,
            'eyebrow' => $bundle->eyebrow, 'priceMinor' => $bundle->price_minor, 'isPublished' => $bundle->is_published,
            'displayOrder' => $bundle->display_order, 'imageAlt' => $bundle->image_alt,
            'items' => $bundle->items->map(fn (BundleItem $item): array => ['variantId' => $item->product_variant_id,
                'quantity' => $item->quantity, 'displayOrder' => $item->display_order])->all()];
    }

    private function uniqueConflict(): ApiException
    {
        return new ApiException(422, 'VALIDATION_ERROR', 'The bundle conflicts with an existing unique value.', ['catalogue' => ['Check slug, product code, and item uniqueness.']]);
    }
}
