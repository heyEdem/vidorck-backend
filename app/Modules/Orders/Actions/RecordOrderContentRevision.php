<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\SettlementMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderContentRevision;
use App\Modules\Staff\Models\StaffUser;

/** Insert-only write path for order_content_revisions. */
final class RecordOrderContentRevision
{
    /**
     * @param  list<array<string, mixed>>  $beforeItems
     * @param  list<array<string, mixed>>  $afterItems
     */
    public function record(
        Order $order,
        StaffUser $staff,
        string $reason,
        array $beforeItems,
        array $afterItems,
        int $beforeSubtotal,
        int $afterSubtotal,
        SettlementMethod $method,
        ?string $note,
    ): OrderContentRevision {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        return OrderContentRevision::query()->create([
            'order_id' => $order->id,
            'staff_id' => $staff->id,
            'reason' => trim($reason),
            'before_items_json' => $beforeItems,
            'after_items_json' => $afterItems,
            'before_subtotal_minor' => $beforeSubtotal,
            'after_subtotal_minor' => $afterSubtotal,
            'difference_minor' => $afterSubtotal - $beforeSubtotal,
            'settlement_method' => $method,
            'settlement_note' => $note,
            'created_at' => now(),
        ]);
    }
}
