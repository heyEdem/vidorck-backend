<?php

namespace App\Modules\Catalog\Http\Resources\Admin;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin ProductVariant */
final class VariantResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'productId' => $this->product_id, 'label' => $this->size_label,
            'normalizedSize' => $this->normalized_size, 'productCode' => $this->product_code,
            'sourcePriceMinor' => $this->source_price_minor, 'priceMinor' => $this->price_minor,
            'isActive' => $this->is_active, 'displayOrder' => $this->display_order];
    }
}
