<?php

namespace App\Modules\Catalog\Http\Resources\Admin;

use App\Modules\Catalog\Http\Resources\ImageResource;
use App\Modules\Catalog\Models\Product;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin Product */
final class ProductResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug,
            'categoryId' => $this->category_id, 'category' => $this->category?->name, 'displayOrder' => $this->display_order,
            'isPublished' => $this->is_published, 'isNewLook' => $this->is_new_look, 'imageAlt' => $this->image_alt,
            'imagePosition' => $this->image_position, 'hairTypeIds' => $this->hairTypes->modelKeys(),
            'hairTypes' => $this->hairTypes->map(fn ($type): array => ['id' => $type->id, 'name' => $type->name, 'slug' => $type->slug])->values(),
            'variants' => VariantResource::collection($this->variants),
            'image' => $this->activeMedia === null ? null : new ImageResource($this->activeMedia)];
    }
}
