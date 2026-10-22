<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Actions\UpsertCustomer;
use App\Modules\Notifications\Planners\OrderNotificationPlanner;
use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class CreateOrderFromAttempt
{
    public function execute(CheckoutAttempt $attempt): Order
    {
        return DB::transaction(function () use ($attempt): Order {
            $fresh = CheckoutAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            $linker = app(LinkAttemptToOrder::class);
            $existing = $linker->existingOrder($fresh);
            if ($existing !== null) {
                // Already fulfilled (e.g. by a manual order with this reference): clean no-op, never a unique violation.
                if ($existing->checkout_attempt_id === null || $fresh->getRawOriginal('status') !== CheckoutAttemptStatus::SUCCEEDED->value) {
                    $linker->link($fresh, $existing, null);
                }

                return $existing;
            }

            $verification = $this->verifiedData($fresh);
            $customerSnapshot = $this->object($fresh->customer_snapshot_json);
            $deliverySnapshot = $this->object($fresh->delivery_snapshot_json);
            $lines = $fresh->getAttribute('items_snapshot_json');
            if (! is_array($lines) || $lines === []) {
                throw new \InvalidArgumentException('Checkout item snapshot is invalid.');
            }

            $paidAt = $this->paidAt($verification['paid_at'] ?? null);
            $customer = app(UpsertCustomer::class)->execute($customerSnapshot, $fresh->amount_minor, $paidAt);
            $order = Order::query()->create([
                'order_number' => app(NextOrderNumber::class)->execute((int) $paidAt->format('Y')),
                'tracking_token' => Str::random(32), 'source' => OrderSource::ONLINE, 'customer_id' => $customer->id,
                'checkout_attempt_id' => $fresh->id, 'status' => OrderStatus::PAID,
                'subtotal_minor' => $fresh->amount_minor, 'total_paid_minor' => $fresh->amount_minor,
                'currency' => 'GHS', 'payment_method' => strtoupper((string) ($verification['channel'] ?? 'PAYSTACK')),
                'payment_reference' => $fresh->paystack_reference,
                'customer_snapshot_json' => $customerSnapshot, 'delivery_snapshot_json' => $deliverySnapshot,
                'delivery_fee_note' => 'Delivery fee is arranged separately.', 'paid_at' => $paidAt,
            ]);

            app(InsertOrderItems::class)->execute($order, $lines, $fresh->amount_minor);

            $wasMismatch = $fresh->getRawOriginal('status') === CheckoutAttemptStatus::MISMATCH->value;
            $fresh->forceFill(['status' => CheckoutAttemptStatus::SUCCEEDED])->save();
            if ($wasMismatch) {
                $mismatchAuditId = AuditLog::query()->where('target_type', $fresh->getMorphClass())
                    ->where('target_id', (string) $fresh->getKey())->where('action', 'checkout.mismatch.detected')
                    ->orderBy('id')->value('id');
                app(AuditLogger::class)->record(null, 'checkout.mismatch.resolved', $fresh, ['status' => CheckoutAttemptStatus::MISMATCH->value], [
                    'status' => CheckoutAttemptStatus::SUCCEEDED->value,
                    'orderNumber' => $order->order_number,
                    'originalAuditEventId' => $mismatchAuditId,
                ]);
            }
            app(RecordOrderStatusEvent::class)->record($order, null, OrderStatus::PAID, OrderActorType::SYSTEM);
            app(AuditLogger::class)->record(null, 'order.paid', $order, ['status' => null], [
                'status' => OrderStatus::PAID->value,
                'orderNumber' => $order->order_number,
                'source' => OrderSource::ONLINE->value,
                'totalPaidMinor' => $order->total_paid_minor,
                'currency' => $order->currency,
                'checkoutAttemptId' => $fresh->id,
            ]);
            app(OrderNotificationPlanner::class)->orderPaid($order);
            Event::dispatch(new OrderPaid($order));

            return $order;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function verifiedData(CheckoutAttempt $attempt): array
    {
        if ($attempt->verified_at === null) {
            throw new \InvalidArgumentException('Checkout attempt has not been verified.');
        }
        $snapshot = $attempt->verification_snapshot_json;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        if (! is_array($snapshot)) {
            throw new \InvalidArgumentException('Checkout verification snapshot is invalid.');
        }
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : $snapshot;
        $metadata = $data['metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        if (($data['status'] ?? null) !== 'success' || ($data['reference'] ?? null) !== $attempt->paystack_reference
            || ($data['currency'] ?? null) !== 'GHS' || ! is_int($data['amount'] ?? null)
            || $data['amount'] !== $attempt->amount_minor || ! is_array($metadata)
            || ($metadata['attemptId'] ?? null) !== $attempt->public_id) {
            throw new \InvalidArgumentException('Checkout verification does not match this attempt.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException('Checkout snapshot is invalid.');
        }

        return $value;
    }

    private function paidAt(mixed $value): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return now();
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return now();
        }
    }
}
