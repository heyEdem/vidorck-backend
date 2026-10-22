<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Actions\EffectiveTimeline;
use App\Modules\Orders\Models\Order;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Customer-safe tracking view (product-spec §6.5). Allowlist only: no notes, staff, reasons,
 * payment references, tracking token, addresses, or courier details.
 *
 * @property Order $resource
 */
final class CustomerOrderResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $status = $o->status;

        return [
            'orderNumber' => $o->order_number,
            'status' => $status->value,
            'createdAt' => $o->created_at?->toIso8601String(),
            'paidAt' => $o->paid_at?->toIso8601String(),
            'totalPaidMinor' => $o->total_paid_minor,
            'currency' => $o->currency,
            'items' => $o->items->sortBy('id')->values()->map(static fn ($item): array => [
                'name' => $item->name_snapshot, 'size' => $item->size_snapshot, 'quantity' => $item->quantity,
            ])->all(),
            'timeline' => app(EffectiveTimeline::class)->build($o->statusEvents),
        ];
    }
}
