<?php

namespace App\Modules\Catalog\Http\Controllers\Public;

use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoryController
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::query()->active()->orderBy('display_order')->orderBy('name')->get());
    }
}
