<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class CatalogInvalidation
{
    public function product(Product $product, ?string $oldSlug = null): void
    {
        $tags = ['products', 'product:'.$product->slug, 'bundles'];
        if ($oldSlug !== null) {
            $tags[] = 'product:'.$oldSlug;
        }

        $dependentSlugs = Bundle::query()
            ->whereHas('items.variant', fn ($variants) => $variants->where('product_id', $product->getKey()))
            ->pluck('slug');
        foreach ($dependentSlugs as $slug) {
            $tags[] = 'bundle:'.$slug;
        }

        $this->dispatch($tags);
    }

    public function bundle(Bundle $bundle, ?string $oldSlug = null): void
    {
        $tags = ['bundles', 'bundle:'.$bundle->slug];
        if ($oldSlug !== null) {
            $tags[] = 'bundle:'.$oldSlug;
        }

        $this->dispatch($tags);
    }

    public function categories(?Category $category = null): void
    {
        $tags = ['categories', 'products', 'bundles'];
        $products = Product::query();
        if ($category !== null) {
            $products->where('category_id', $category->getKey());
        }

        foreach ($products->pluck('slug') as $slug) {
            $tags[] = 'product:'.$slug;
        }

        $bundles = Bundle::query()->whereHas('items.variant.product', function (Builder $query) use ($category): void {
            if ($category !== null) {
                $query->where('category_id', $category->getKey());
            }
        });
        foreach ($bundles->pluck('slug') as $slug) {
            $tags[] = 'bundle:'.$slug;
        }

        $this->dispatch($tags);
    }

    /** @param list<string> $slugTags Specific `product:`/`bundle:` tags touched by the import. */
    public function import(array $slugTags): void
    {
        $tags = array_values(array_unique(['products', 'bundles', ...$slugTags]));
        sort($tags);
        // Deliberately not afterCommit: on the database queue the job commits or rolls back with the caller's transaction.
        RevalidateStorefront::dispatch($tags);
    }

    public function deliveryAreas(): void
    {
        $this->dispatch(['delivery-areas']);
    }

    /** @param list<string> $tags */
    private function dispatch(array $tags): void
    {
        $tags = array_values(array_unique($tags));
        sort($tags);

        RevalidateStorefront::dispatch($tags)->afterCommit();
    }
}
