<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin Product */
final class ProductResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category?->name,
            'image' => $this->activeMedia === null ? null : new ImageResource($this->activeMedia),
            'image_position' => $this->image_position,
            'is_new_look' => $this->is_new_look,
            'hair_types' => $this->hairTypes->pluck('name')->values(),
            'variants' => VariantResource::collection($this->variants),
            'currency' => 'GHS',
        ];
    }
}
