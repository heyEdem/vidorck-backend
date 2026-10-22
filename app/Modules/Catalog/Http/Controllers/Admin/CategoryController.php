<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Http\Requests\AdminCategoryIndexRequest;
use App\Modules\Catalog\Http\Requests\AdminCategoryStoreRequest;
use App\Modules\Catalog\Http\Requests\AdminCategoryUpdateRequest;
use App\Modules\Catalog\Http\Resources\Admin\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoryController
{
    public function index(AdminCategoryIndexRequest $request, ManageCatalog $catalog): AnonymousResourceCollection
    {
        return CategoryResource::collection($catalog->categories());
    }

    public function store(AdminCategoryStoreRequest $request, ManageCatalog $catalog): JsonResponse
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return (new CategoryResource($catalog->createCategory($actor, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(AdminCategoryUpdateRequest $request, Category $category, ManageCatalog $catalog): CategoryResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new CategoryResource($catalog->updateCategory($actor, $category, $request->validated()));
    }
}
