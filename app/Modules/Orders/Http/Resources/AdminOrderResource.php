<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Enums\StaffRole;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff order view (product-spec §6.8). Includes payment reference and notes; never the tracking token.
 *
 * @property Order $resource
 */
final class AdminOrderResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $isAdmin = $request->user()?->role === StaffRole::ADMIN;
        $snapshot = is_array($o->customer_snapshot_json) ? $o->customer_snapshot_json : [];

        return [
            'orderNumber' => $o->order_number,
            'status' => $o->status->value,
            'allowedStatuses' => array_map(static fn ($s): string => $s->value, app(OrderStateMachine::class)->allowedNext($o->status, $isAdmin)),
            'source' => $o->source->value,
            'totalPaidMinor' => $o->total_paid_minor,
            'subtotalMinor' => $o->subtotal_minor,
            'currency' => $o->currency,
            'paymentMethod' => $o->payment_method,
            'paymentReference' => $o->payment_reference,
            'paidAt' => $o->paid_at?->toIso8601String(),
            'createdAt' => $o->created_at?->toIso8601String(),
            'customer' => [
                'publicId' => $o->relationLoaded('customer') ? $o->customer->public_id : null,
                'fullName' => $snapshot['fullName'] ?? null,
                'phone' => $snapshot['phoneDisplay'] ?? null,
                'email' => $snapshot['email'] ?? null,
            ],
            'delivery' => is_array($o->delivery_snapshot_json) ? $o->delivery_snapshot_json : [],
            'deliveryFeeNote' => $o->delivery_fee_note,
            'createdByStaffId' => $o->created_by_staff_id,
            'items' => $this->whenLoaded('items', fn () => $o->items->sortBy('id')->values()->map(static fn ($i): array => [
                'id' => $i->id, 'lineType' => $i->line_type->value, 'parentItemId' => $i->parent_item_id,
                'name' => $i->name_snapshot, 'size' => $i->size_snapshot, 'productCode' => $i->product_code_snapshot,
                'imageRef' => $i->image_ref_snapshot, 'bundleContents' => $i->bundle_contents_json,
                'quantity' => $i->quantity, 'unitPriceMinor' => $i->unit_price_minor, 'lineTotalMinor' => $i->line_total_minor,
                'currentUnitPriceMinor' => self::currentPrice($i),
            ])->all()),
            'statusEvents' => $this->whenLoaded('statusEvents', fn () => $o->statusEvents->sortBy('id')->values()->map(static fn ($e): array => [
                'fromStatus' => $e->from_status?->value, 'toStatus' => $e->to_status->value,
                'actorType' => $e->actor_type->value, 'actorId' => $e->actor_id, 'reason' => $e->reason,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->all()),
            'notes' => $this->whenLoaded('notes', fn () => $o->notes->sortBy('id')->values()->map(static fn ($n): array => [
                'id' => $n->id, 'body' => $n->body, 'staffId' => $n->staff_id,
                'staffName' => $n->staff->name, 'createdAt' => $n->created_at->toIso8601String(),
            ])->all()),
            'contentRevisions' => $this->whenLoaded('contentRevisions', fn () => $o->contentRevisions->sortBy('id')->values()->map(static fn ($r): array => [
                'id' => $r->id, 'staffId' => $r->staff_id, 'staffName' => $r->staff->name, 'reason' => $r->reason,
                'beforeItems' => $r->before_items_json, 'afterItems' => $r->after_items_json,
                'beforeSubtotalMinor' => $r->before_subtotal_minor, 'afterSubtotalMinor' => $r->after_subtotal_minor,
                'differenceMinor' => $r->difference_minor, 'settlementMethod' => $r->settlement_method->value,
                'settlementNote' => $r->settlement_note, 'createdAt' => $r->created_at->toIso8601String(),
            ])->all()),
            'complaints' => $this->when(Gate::allows('view-complaints') && $o->relationLoaded('complaints'), fn () => $o->complaints->sortByDesc('id')->values()->map(static fn ($x): array => [
                'reference' => $x->reference, 'topic' => $x->topic->value, 'status' => $x->status->value, 'createdAt' => $x->created_at?->toIso8601String(),
            ])->all()),
        ];
    }

    /** Today's price for the catalogue item behind an order line, or null when it is no longer on sale. */
    private static function currentPrice(OrderItem $item): ?int
    {
        if ($item->product_variant_id !== null) {
            $variant = $item->productVariant;
            $visible = $variant !== null && $variant->is_active
                && Product::query()->visible()->whereKey($variant->product_id)->exists();

            return $visible ? (int) $variant->price_minor : null;
        }
        if ($item->bundle_id !== null) {
            $bundle = Bundle::query()->visible()->find($item->bundle_id);

            return $bundle === null ? null : (int) $bundle->price_minor;
        }

        return null;
    }
}
