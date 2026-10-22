<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderItemLineType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

/** Persists priced cart-line snapshots as order items; shared by online and manual order creation. */
final class InsertOrderItems
{
    /** @param  list<mixed>  $lines */
    public function execute(Order $order, array $lines, int $expectedTotal): void
    {
        $items = [];
        $itemTotal = 0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw new \InvalidArgumentException('Checkout item snapshot is invalid.');
            }
            $lineType = OrderItemLineType::tryFrom((string) ($line['lineType'] ?? ''));
            if ($lineType === null || ! is_int($line['quantity'] ?? null) || $line['quantity'] <= 0
                || ! is_int($line['unitPriceMinor'] ?? null) || ! is_int($line['lineTotalMinor'] ?? null)
                || ! is_string($line['name'] ?? null) || ! is_string($line['productCode'] ?? null)) {
                throw new \InvalidArgumentException('Checkout item snapshot is invalid.');
            }
            if ($line['unitPriceMinor'] > intdiv(PHP_INT_MAX, $line['quantity'])
                || $line['lineTotalMinor'] !== $line['unitPriceMinor'] * $line['quantity']
                || $line['lineTotalMinor'] > $expectedTotal - $itemTotal) {
                throw new \InvalidArgumentException('Checkout item totals do not match the verified amount.');
            }
            $itemTotal += $line['lineTotalMinor'];
            $items[] = OrderItem::query()->create([
                'order_id' => $order->id, 'line_type' => $lineType,
                'product_variant_id' => $line['productVariantId'] ?? null, 'bundle_id' => $line['bundleId'] ?? null,
                'name_snapshot' => $line['name'], 'size_snapshot' => $line['size'] ?? null,
                'product_code_snapshot' => $line['productCode'], 'image_ref_snapshot' => $line['imageRef'] ?? null,
                'bundle_contents_json' => $line['bundleContents'] ?? null, 'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unitPriceMinor'], 'line_total_minor' => $line['lineTotalMinor'],
            ]);
        }

        $byLineId = [];
        foreach ($lines as $index => $line) {
            if (is_string($line['lineId'] ?? null)) {
                $byLineId[$line['lineId']] = $items[$index];
            }
        }
        foreach ($lines as $index => $line) {
            $parentId = $line['parentLineId'] ?? null;
            if ($parentId !== null) {
                $parent = is_string($parentId) ? ($byLineId[$parentId] ?? null) : null;
                if ($parent === null) {
                    throw new \InvalidArgumentException('Checkout item parent snapshot is invalid.');
                }
                $items[$index]->forceFill(['parent_item_id' => $parent->id])->save();
            }
        }
        if ($itemTotal !== $expectedTotal) {
            throw new \InvalidArgumentException('Checkout item totals do not match the verified amount.');
        }
    }
}
