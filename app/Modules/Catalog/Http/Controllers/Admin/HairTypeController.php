<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Http\Resources\Admin\HairTypeResource;
use App\Modules\Catalog\Models\HairType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class HairTypeController
{
    public function index(): AnonymousResourceCollection
    {
        return HairTypeResource::collection(HairType::query()->orderBy('display_order')->orderBy('name')->get());
    }
}
