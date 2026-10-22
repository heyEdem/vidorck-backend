<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Models\RefundRequest;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @property RefundRequest $resource */
final class AdminRefundResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $order = $r->order;

        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'channel' => $r->channel->value,
            'reason' => $r->reason,
            'requesterName' => $r->requester_name,
            'requesterPhone' => $r->requester_phone_e164,
            'decidedByStaffId' => $r->decided_by_staff_id,
            'decidedAt' => $r->decided_at?->toIso8601String(),
            'decisionReason' => $r->decision_reason,
            'createdAt' => $r->created_at?->toIso8601String(),
            'order' => [
                'orderNumber' => $order->order_number, 'status' => $order->status->value, 'source' => $order->source->value,
                'totalPaidMinor' => $order->total_paid_minor, 'currency' => $order->currency,
            ],
            // Money confirmed but the order could not be moved to REFUNDED.
            'needsReconciliation' => $r->status === RefundStatus::COMPLETED && $order->status !== OrderStatus::REFUNDED,
            'transactions' => $this->whenLoaded('transactions', fn () => $r->transactions->sortBy('id')->values()->map(static fn ($t): array => [
                'id' => $t->id, 'method' => $t->method->value, 'providerReference' => $t->provider_reference,
                'amountMinor' => $t->amount_minor, 'status' => $t->status->value, 'createdAt' => $t->created_at->toIso8601String(),
            ])->all()),
            'notes' => $this->whenLoaded('notes', fn () => $r->notes->sortBy('id')->values()->map(static fn ($n): array => [
                'id' => $n->id, 'body' => $n->body, 'staffId' => $n->staff_id,
                'staffName' => $n->staff->name, 'createdAt' => $n->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}
