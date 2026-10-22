<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Checkout\Models\DeliveryArea;

/** Free-text areas are allowed; text matching a delivery_areas.name (case-insensitive, trimmed) takes the canonical name. */
final class CanonicalDeliveryArea
{
    public function resolve(string $area): string
    {
        $needle = mb_strtolower(trim($area));
        $match = DeliveryArea::query()->get(['name'])->first(fn (DeliveryArea $a): bool => mb_strtolower(trim($a->name)) === $needle);

        return $match !== null ? trim($match->name) : trim($area);
    }
}
