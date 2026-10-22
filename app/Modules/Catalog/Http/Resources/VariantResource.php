<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin ProductVariant */
final class VariantResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'label' => $this->size_label,
            'product_code' => $this->product_code,
            'price_minor' => $this->price_minor,
        ];
    }
}
