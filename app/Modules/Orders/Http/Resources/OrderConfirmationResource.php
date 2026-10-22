<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Compact success-page payload (website-spec §14.3).
 *
 * @property Order $resource
 */
final class OrderConfirmationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            'orderNumber' => $o->order_number,
            'status' => $o->status->value,
            'paidAt' => $o->paid_at?->toIso8601String(),
            'totalPaidMinor' => $o->total_paid_minor,
            'currency' => $o->currency,
            'deliveryFeeNote' => $o->delivery_fee_note,
        ];
    }
}
