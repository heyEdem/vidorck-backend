<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Bundle;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin Bundle */
final class BundleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'product_code' => $this->product_code,
            'eyebrow' => $this->eyebrow,
            'items' => $this->items->map(fn ($item): array => [
                'product' => new ProductResource($item->variant->product),
                'variant' => new VariantResource($item->variant),
                'quantity' => $item->quantity,
            ])->values(),
            'price_minor' => $this->price_minor,
            'currency' => 'GHS',
            'image' => $this->activeMedia === null ? null : new ImageResource($this->activeMedia),
        ];
    }
}
