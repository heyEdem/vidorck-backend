<?php

namespace App\Modules\Checkout\Http\Controllers\Public;

use App\Modules\Checkout\Http\Resources\DeliveryAreaResource;
use App\Modules\Checkout\Models\DeliveryArea;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DeliveryAreaController
{
    public function index(): AnonymousResourceCollection
    {
        return DeliveryAreaResource::collection(DeliveryArea::query()->where('is_active', true)
            ->orderBy('display_order')->orderBy('id')->orderBy('name')->get());
    }
}
