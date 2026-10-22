<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Http\Requests\AdminVariantStoreRequest;
use App\Modules\Catalog\Http\Requests\AdminVariantUpdateRequest;
use App\Modules\Catalog\Http\Resources\Admin\VariantResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class VariantController
{
    public function index(Product $product, ManageCatalog $catalog): AnonymousResourceCollection
    {
        return VariantResource::collection($catalog->variants($product));
    }

    public function store(AdminVariantStoreRequest $request, Product $product, ManageCatalog $catalog): JsonResponse
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return (new VariantResource($catalog->createVariant($actor, $product, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(AdminVariantUpdateRequest $request, Product $product, ProductVariant $variant, ManageCatalog $catalog): VariantResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new VariantResource($catalog->updateVariant($actor, $product, $variant, $request->validated()));
    }
}
