<?php

namespace App\Modules\Catalog\Http\Controllers\Public;

use App\Modules\Catalog\Http\Requests\ProductIndexRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SlugRedirect;
use App\Modules\Catalog\Support\CatalogNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class ProductController
{
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Product::query()->visible()->with($this->productRelations());

        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $normalized = CatalogNormalizer::normalize($filters['q']);
            $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);
            $query->whereRaw("normalized_name LIKE ? ESCAPE '\\'", ['%'.$literal.'%']);
        }

        if (isset($filters['category'])) {
            $categoryIds = Category::query()->active()->get(['id', 'name', 'slug'])
                ->filter(fn (Category $category): bool => $this->matchesFilter($filters['category'], $category->slug, $category->name))
                ->modelKeys();
            $query->whereIn('category_id', $categoryIds);
        }

        if (isset($filters['hair_type'])) {
            $hairTypeIds = HairType::query()->get(['id', 'name', 'slug'])
                ->filter(fn (HairType $hairType): bool => $this->matchesFilter($filters['hair_type'], $hairType->slug, $hairType->name))
                ->modelKeys();
            $query->whereHas('hairTypes', fn (Builder $hairTypes): Builder => $hairTypes->whereIn('hair_types.id', $hairTypeIds));
        }

        $products = $query
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->appends($request->query());

        return ProductResource::collection($products)
            ->additional(['meta' => ['facets' => $this->facets()]]);
    }

    public function show(string $slug): ProductResource|JsonResponse
    {
        $product = Product::query()->visible()->with($this->productRelations())->where('slug', $slug)->first();

        if ($product !== null) {
            return new ProductResource($product);
        }

        $redirect = $this->visibleRedirect('product', $slug, fn (string $target): bool => Product::query()->visible()->where('slug', $target)->exists());

        if ($redirect === null) {
            abort(404);
        }

        return response()->json(['redirectTo' => $redirect])->header('Location', '/api/v1/products/'.$redirect)->setStatusCode(301);
    }

    /** @return array<string, mixed> */
    private function facets(): array
    {
        $categoryCounts = DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->where('categories.is_active', true)
            ->where('products.is_published', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.is_active', true);
            })
            ->select('categories.slug', 'categories.name', 'categories.display_order')
            ->selectRaw('COUNT(DISTINCT products.id) AS count')
            ->groupBy('categories.id', 'categories.slug', 'categories.name', 'categories.display_order')
            ->orderBy('categories.display_order')->orderBy('categories.name')->get()
            ->map(fn ($row): array => ['slug' => $row->slug, 'name' => $row->name, 'count' => (int) $row->count])
            ->all();

        $hairTypeCounts = DB::table('hair_types')
            ->join('product_hair_type', 'product_hair_type.hair_type_id', '=', 'hair_types.id')
            ->join('products', 'products.id', '=', 'product_hair_type.product_id')
            ->where('products.is_published', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('categories')
                    ->whereColumn('categories.id', 'products.category_id')
                    ->where('categories.is_active', true);
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.is_active', true);
            })
            ->select('hair_types.slug', 'hair_types.name', 'hair_types.display_order')
            ->selectRaw('COUNT(DISTINCT products.id) AS count')
            ->groupBy('hair_types.id', 'hair_types.slug', 'hair_types.name', 'hair_types.display_order')
            ->orderBy('hair_types.display_order')->orderBy('hair_types.name')->get()
            ->map(fn ($row): array => ['slug' => $row->slug, 'name' => $row->name, 'count' => (int) $row->count])
            ->all();

        return ['categories' => $categoryCounts, 'hairTypes' => $hairTypeCounts];
    }

    /** @return array<array-key, mixed> */
    private function productRelations(): array
    {
        return [
            'category',
            'hairTypes',
            'variants' => fn ($variants) => $variants->where('is_active', true),
            'activeMedia.owner',
        ];
    }

    private function matchesFilter(string $input, string $slug, string $name): bool
    {
        $normalizedInput = CatalogNormalizer::normalize($input);

        return mb_strtolower($slug) === mb_strtolower($input)
            || CatalogNormalizer::normalize($name) === $normalizedInput;
    }

    /**
     * @param  callable(string): bool  $targetIsVisible
     */
    private function visibleRedirect(string $entityType, string $oldSlug, callable $targetIsVisible): ?string
    {
        $seen = [$oldSlug => true];
        $current = $oldSlug;

        for ($depth = 0; $depth < 50; $depth++) {
            $next = SlugRedirect::query()->where('entity_type', $entityType)->where('old_slug', $current)->value('new_slug');
            if (! is_string($next) || isset($seen[$next])) {
                return null;
            }

            $seen[$next] = true;
            $current = $next;

            if (SlugRedirect::query()->where('entity_type', $entityType)->where('old_slug', $current)->exists()) {
                continue;
            }

            return $targetIsVisible($current) ? $current : null;
        }

        return null;
    }
}
