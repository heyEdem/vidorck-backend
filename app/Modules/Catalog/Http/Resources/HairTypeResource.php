<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\HairType;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin HairType */
final class HairTypeResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'display_order' => $this->display_order,
        ];
    }
}
