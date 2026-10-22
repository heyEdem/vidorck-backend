<?php

namespace App\Modules\Catalog\Http\Resources\Admin;

use App\Modules\Catalog\Models\Category;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin Category */
final class CategoryResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'displayOrder' => $this->display_order,
            'isActive' => $this->is_active, 'productCount' => $this->products_count ?? $this->products()->count()];
    }
}
