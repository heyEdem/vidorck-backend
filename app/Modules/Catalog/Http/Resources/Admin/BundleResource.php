<?php

namespace App\Modules\Catalog\Http\Resources\Admin;

use App\Modules\Catalog\Actions\BundlePricing;
use App\Modules\Catalog\Http\Resources\ImageResource;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin Bundle */
final class BundleResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $suggested = $this->items->sum(fn (BundleItem $item): int => $item->variant->price_minor * $item->quantity);
        $visible = $this->is_published && $this->items->isNotEmpty() && $this->items->every(fn (BundleItem $item): bool => $item->variant->is_active && $item->variant->product->is_published && $item->variant->product->category?->is_active === true);

        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'productCode' => $this->product_code,
            'eyebrow' => $this->eyebrow, 'priceMinor' => $this->price_minor, 'suggestedPriceMinor' => BundlePricing::fromSubtotal($suggested),
            'isPublished' => $this->is_published, 'isPubliclyVisible' => $visible, 'displayOrder' => $this->display_order,
            'imageAlt' => $this->image_alt, 'items' => $this->items->map(fn (BundleItem $item): array => [
                'id' => $item->id, 'variantId' => $item->product_variant_id, 'quantity' => $item->quantity,
                'displayOrder' => $item->display_order, 'product' => ['id' => $item->variant->product->id,
                    'name' => $item->variant->product->name, 'slug' => $item->variant->product->slug],
                'variant' => ['id' => $item->variant->id, 'label' => $item->variant->size_label,
                    'productCode' => $item->variant->product_code, 'priceMinor' => $item->variant->price_minor],
            ])->values(), 'image' => $this->activeMedia === null ? null : new ImageResource($this->activeMedia)];
    }
}
