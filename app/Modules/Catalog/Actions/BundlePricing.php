<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ProductVariant;

final class BundlePricing
{
    /** @param array<int, array{variantId:int, quantity:int}> $items */
    public function suggestedPrice(array $items): int
    {
        $ids = array_column($items, 'variantId');
        $prices = ProductVariant::query()->whereIn('id', $ids)->pluck('price_minor', 'id');
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) ($prices[$item['variantId']] ?? 0) * $item['quantity'];
        }

        return self::fromSubtotal($sum);
    }

    /** Suggested bundle price: component subtotal less GHS 3, never below zero. */
    public static function fromSubtotal(int $subtotalMinor): int
    {
        return max(0, $subtotalMinor - 300);
    }
}
