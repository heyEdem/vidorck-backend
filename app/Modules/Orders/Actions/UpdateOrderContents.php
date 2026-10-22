<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Actions\PriceCart;
use App\Modules\Notifications\Planners\OrderNotificationPlanner;
use App\Modules\Orders\Enums\OrderItemLineType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Enums\SettlementMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderContentRevision;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin edit of a paid order's items (admin spec §7a). Unchanged lines keep their purchase-time price and snapshots;
 * added lines and lines whose quantity changed are priced from the current catalogue and must still be on sale.
 * Price differences are settled offline, so total_paid_minor is
 * never changed and payment reconciliation and refunds keep using what was actually charged. Every edit appends an
 * immutable OrderContentRevision and an audit-log entry, in one IMMEDIATE transaction with the item replacement.
 */
final class UpdateOrderContents
{
    /** Goods are not packed yet. */
    public const EDITABLE = [OrderStatus::PAID, OrderStatus::PROCESSING];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PriceCart $pricer,
        private readonly InsertOrderItems $inserter,
        private readonly RecordOrderContentRevision $revisions,
    ) {}

    /** @param list<array<string, mixed>> $items validated request lines */
    public function execute(Order $order, StaffUser $actor, array $items, string $reason, SettlementMethod $method, ?string $note, int $basedOnRevision): Order
    {
        return DB::transaction(function () use ($order, $actor, $items, $reason, $method, $note, $basedOnRevision): Order {
            $staff = StaffUser::query()->find($actor->getKey());
            if ($staff === null || ! $staff->is_active || $staff->role !== StaffRole::ADMIN) {
                throw new AuthorizationException;
            }
            $fresh = Order::query()->findOrFail($order->getKey());
            $status = OrderStatus::from((string) DB::table('orders')->where('id', $fresh->id)->value('status'));
            if (! in_array($status, self::EDITABLE, true)) {
                throw new ApiException(409, 'ORDER_NOT_EDITABLE', 'Items can only be changed while the order is Paid or Processing.');
            }
            if (RefundRequest::query()->where('order_id', $fresh->id)
                ->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::open()))->exists()) {
                throw new ApiException(409, 'ORDER_REFUND_OPEN', 'This order has an open cancellation or refund request. Resolve it before changing items.');
            }
            if (OrderContentRevision::query()->where('order_id', $fresh->id)->count() !== $basedOnRevision) {
                throw new ApiException(409, 'ORDER_CONTENTS_CHANGED', 'These items were changed by someone else. Reload the order and try again.');
            }

            /** @var Collection<int, OrderItem> $existing */
            $existing = OrderItem::query()->where('order_id', $fresh->id)->orderBy('id')->get()->keyBy('id');
            $before = $existing->values()->map(fn (OrderItem $item): array => $this->snapshot($item))->all();
            $beforeSubtotal = (int) $fresh->subtotal_minor;

            [$lines, $errors] = $this->lines($existing, $items);
            if ($errors !== []) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Some items cannot be used.', $errors);
            }
            if ($this->signature($lines) === $this->signature($before)) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'Change at least one item.', ['items' => ['Change at least one item.']]);
            }
            $afterSubtotal = array_sum(array_map(static fn (array $line): int => (int) $line['lineTotalMinor'], $lines));
            if ($afterSubtotal <= 0) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'The items total must be greater than zero.', ['items' => ['Select items with a payable price.']]);
            }
            if ($afterSubtotal !== $beforeSubtotal) {
                $settlementErrors = [];
                if ($method === SettlementMethod::NONE) {
                    $settlementErrors['settlement.method'] = ['Say how the difference was settled.'];
                }
                if ($note === null) {
                    $settlementErrors['settlement.note'] = ['Describe the settlement, e.g. "Collected GHS 20 by MoMo".'];
                }
                if ($settlementErrors !== []) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'The items total changed, so a settlement is required.', $settlementErrors);
                }
            }

            // Children first: parent_item_id is restrictOnDelete.
            OrderItem::query()->where('order_id', $fresh->id)->whereNotNull('parent_item_id')->delete();
            OrderItem::query()->where('order_id', $fresh->id)->delete();
            $this->inserter->execute($fresh, $lines, $afterSubtotal);
            $fresh->update(['subtotal_minor' => $afterSubtotal]);

            $after = OrderItem::query()->where('order_id', $fresh->id)->orderBy('id')->get()
                ->map(fn (OrderItem $item): array => $this->snapshot($item))->all();
            $revision = $this->revisions->record($fresh, $staff, $reason, $before, $after, $beforeSubtotal, $afterSubtotal, $method, $note);
            $this->audit->record($staff, 'order.contents_updated', $fresh,
                ['subtotalMinor' => $beforeSubtotal, 'items' => $before],
                [
                    'subtotalMinor' => $afterSubtotal, 'items' => $after, 'orderNumber' => $fresh->order_number,
                    'reason' => trim($reason), 'revisionId' => $revision->id, 'differenceMinor' => $afterSubtotal - $beforeSubtotal,
                    'settlement' => ['method' => $method->value, 'note' => $note],
                ]);
            app(OrderNotificationPlanner::class)->contentsUpdated($fresh, $revision);

            return $fresh;
        }, 3);
    }

    /**
     * @param  Collection<int, OrderItem>  $existing
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>}
     */
    private function lines(Collection $existing, array $items): array
    {
        $kept = [];
        foreach ($items as $item) {
            if (isset($item['itemId'])) {
                $kept[(int) $item['itemId']] = true;
            }
        }
        $lines = [];
        $errors = [];
        foreach ($items as $index => $item) {
            $quantity = (int) $item['quantity'];
            if (isset($item['itemId'])) {
                $source = $existing->get((int) $item['itemId']);
                if ($source === null) {
                    $errors["items.$index.itemId"] = ['This line is not part of the order.'];

                    continue;
                }
                $parentKept = $source->parent_item_id !== null && isset($kept[$source->parent_item_id]);
                $type = $source->line_type === OrderItemLineType::BUNDLE_EXTRA && ! $parentKept ? OrderItemLineType::PRODUCT : $source->line_type;
                if ($quantity !== (int) $source->quantity) {
                    // Spec §7a: a changed line uses the current catalogue price and must still be on sale.
                    $identity = $this->catalogueIdentity($source);
                    if ($identity === null) {
                        $errors["items.$index"] = ['This item is no longer available.'];

                        continue;
                    }
                    $priced = $this->price($identity + ['quantity' => $quantity] + $this->expected($item), $index, $errors);
                    if ($priced !== null) {
                        $priced['lineType'] = $type->value;
                        $priced['lineId'] = 'item-'.$source->id;
                        $priced['parentLineId'] = $type === OrderItemLineType::BUNDLE_EXTRA ? 'item-'.$source->parent_item_id : null;
                        $lines[] = $priced;
                    }

                    continue;
                }
                $lines[] = [
                    'lineType' => $type->value, 'lineId' => 'item-'.$source->id,
                    'parentLineId' => $type === OrderItemLineType::BUNDLE_EXTRA ? 'item-'.$source->parent_item_id : null,
                    'productVariantId' => $source->product_variant_id, 'bundleId' => $source->bundle_id,
                    'name' => $source->name_snapshot, 'size' => $source->size_snapshot, 'productCode' => $source->product_code_snapshot,
                    'imageRef' => $source->image_ref_snapshot, 'bundleContents' => $source->bundle_contents_json,
                    'quantity' => $quantity, 'unitPriceMinor' => (int) $source->unit_price_minor,
                    'lineTotalMinor' => (int) $source->unit_price_minor * $quantity,
                ];

                continue;
            }
            $line = $this->price(['slug' => (string) $item['slug'], 'productCode' => (string) $item['productCode'], 'quantity' => $quantity] + $this->expected($item), $index, $errors);
            if ($line !== null) {
                $line['lineId'] = 'new-'.$index;
                $line['parentLineId'] = null;
                $lines[] = $line;
            }
        }

        return [$lines, $errors];
    }

    /**
     * Prices one line from the live catalogue; records a per-line error instead of throwing.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private function price(array $request, int $index, array &$errors): ?array
    {
        try {
            return $this->pricer->price([$request])['lines'][0];
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $data = $response instanceof JsonResponse ? $response->getData(true) : null;
            $why = is_array($data) ? ($data['issues'][0]['reason'] ?? null) : null;
            $errors["items.$index"] = [$why === 'PRICE_CHANGED' ? 'The price has changed.' : 'This item is no longer available.'];

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, int>
     */
    private function expected(array $item): array
    {
        return isset($item['expectedUnitPriceMinor']) ? ['expectedUnitPriceMinor' => (int) $item['expectedUnitPriceMinor']] : [];
    }

    /** @return array{slug: string, productCode: string}|null the catalogue item behind an order line, if it still exists */
    private function catalogueIdentity(OrderItem $item): ?array
    {
        if ($item->product_variant_id !== null) {
            $variant = ProductVariant::query()->with('product')->find($item->product_variant_id);

            return $variant?->product === null ? null : ['slug' => (string) $variant->product->slug, 'productCode' => (string) $variant->product_code];
        }
        if ($item->bundle_id !== null) {
            $bundle = Bundle::query()->find($item->bundle_id);

            return $bundle === null ? null : ['slug' => (string) $bundle->slug, 'productCode' => (string) $bundle->product_code];
        }

        return null;
    }

    /** @return array<string, mixed> the stored and returned shape of one line */
    private function snapshot(OrderItem $item): array
    {
        return [
            'itemId' => $item->id, 'lineType' => $item->line_type->value, 'parentItemId' => $item->parent_item_id,
            'name' => $item->name_snapshot, 'size' => $item->size_snapshot, 'productCode' => $item->product_code_snapshot,
            'bundleContents' => $item->bundle_contents_json,
            'quantity' => (int) $item->quantity, 'unitPriceMinor' => (int) $item->unit_price_minor, 'lineTotalMinor' => (int) $item->line_total_minor,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function signature(array $lines): string
    {
        // Generated ids are excluded; everything a customer or packer would see is compared.
        $parts = array_map(static fn (array $l): string => (string) json_encode([
            $l['lineType'], $l['productCode'], $l['name'], $l['size'] ?? null, $l['bundleContents'] ?? null,
            $l['quantity'], $l['unitPriceMinor'], ($l['parentLineId'] ?? $l['parentItemId'] ?? null) !== null,
        ]), $lines);
        sort($parts);

        return implode(';', $parts);
    }
}
