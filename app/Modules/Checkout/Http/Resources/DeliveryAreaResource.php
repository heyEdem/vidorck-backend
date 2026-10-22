<?php

namespace App\Modules\Checkout\Http\Resources;

use App\Modules\Checkout\Models\DeliveryArea;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin DeliveryArea */
final class DeliveryAreaResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['name' => $this->name, 'aliases' => $this->aliases_json ?? []];
    }
}
