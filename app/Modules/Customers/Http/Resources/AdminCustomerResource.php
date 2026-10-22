<?php

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Customer;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @property Customer $resource
 */
final class AdminCustomerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'publicId' => $c->public_id,
            'fullName' => $c->full_name,
            'phone' => $c->phone_display,
            'phoneE164' => $c->phone_e164,
            'email' => $c->email,
            'firstOrderAt' => $c->first_order_at?->toIso8601String(),
            'lastOrderAt' => $c->last_order_at?->toIso8601String(),
            'ordersCount' => $c->orders_count,
            'lifetimePaidMinor' => $c->lifetime_paid_minor,
            'orders' => $this->whenLoaded('orders', fn () => $c->orders->sortByDesc('id')->values()->map(static fn ($o): array => [
                'orderNumber' => $o->order_number, 'status' => $o->status->value, 'source' => $o->source->value,
                'totalPaidMinor' => $o->total_paid_minor, 'currency' => $o->currency,
                'paidAt' => $o->paid_at?->toIso8601String(), 'createdAt' => $o->created_at?->toIso8601String(),
            ])->all()),
            'notes' => $this->whenLoaded('notes', fn () => $c->notes->sortBy('id')->values()->map(static fn ($n): array => [
                'id' => $n->id, 'body' => $n->body, 'staffId' => $n->staff_id,
                'staffName' => $n->staff->name, 'createdAt' => $n->created_at->toIso8601String(),
            ])->all()),
            'complaints' => $this->when(Gate::allows('view-complaints') && $c->relationLoaded('complaints'), fn () => $c->complaints->sortByDesc('id')->values()->map(static fn ($x): array => [
                'reference' => $x->reference, 'topic' => $x->topic->value, 'status' => $x->status->value, 'createdAt' => $x->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
