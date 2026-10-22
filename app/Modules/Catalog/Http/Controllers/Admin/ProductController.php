<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Http\Requests\AdminProductIndexRequest;
use App\Modules\Catalog\Http\Requests\AdminProductStoreRequest;
use App\Modules\Catalog\Http\Requests\AdminProductUpdateRequest;
use App\Modules\Catalog\Http\Resources\Admin\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogNormalizer;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductController
{
    public function index(AdminProductIndexRequest $request): AnonymousResourceCollection
    {
        $input = $request->validated();
        $query = Product::query()->with(['category', 'hairTypes', 'variants', 'activeMedia.owner']);
        if (! empty($input['q'])) {
            $normalized = CatalogNormalizer::normalize($input['q']);
            $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);
            $query->whereRaw("normalized_name LIKE ? ESCAPE '\\'", ['%'.$literal.'%']);
        }
        if (isset($input['categoryId'])) {
            $query->where('category_id', $input['categoryId']);
        }
        if (isset($input['isPublished'])) {
            $query->where('is_published', $input['isPublished']);
        }

        return ProductResource::collection($query->orderBy('display_order')->orderBy('name')->orderBy('id')
            ->paginate($input['perPage'] ?? 15)->appends($request->query()));
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['category', 'hairTypes', 'variants', 'activeMedia.owner']));
    }

    public function store(AdminProductStoreRequest $request, ManageCatalog $catalog): JsonResponse
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return (new ProductResource($catalog->createProduct($actor, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(AdminProductUpdateRequest $request, Product $product, ManageCatalog $catalog): ProductResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new ProductResource($catalog->updateProduct($actor, $product, $request->validated()));
    }

    public function publish(Request $request, Product $product, ManageCatalog $catalog): ProductResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new ProductResource($catalog->setProductPublished($actor, $product, true));
    }

    public function unpublish(Request $request, Product $product, ManageCatalog $catalog): ProductResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new ProductResource($catalog->setProductPublished($actor, $product, false));
    }
}
