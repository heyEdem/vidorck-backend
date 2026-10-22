<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\StoreImage;
use App\Modules\Catalog\Http\Requests\ImageUploadRequest;
use App\Modules\Catalog\Http\Resources\ImageResource;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

final class ImageController
{
    public function product(ImageUploadRequest $request, Product $product, StoreImage $storeImage): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof StaffUser) {
            throw new AuthorizationException;
        }
        $image = $storeImage->store($product, $request->file('image'), $actor, $request->input('alt'));

        return response()->json(['data' => ['image' => (new ImageResource($image))->resolve($request)]], 201);
    }

    public function bundle(ImageUploadRequest $request, Bundle $bundle, StoreImage $storeImage): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof StaffUser) {
            throw new AuthorizationException;
        }
        $image = $storeImage->store($bundle, $request->file('image'), $actor, $request->input('alt'));

        return response()->json(['data' => ['image' => (new ImageResource($image))->resolve($request)]], 201);
    }
}
