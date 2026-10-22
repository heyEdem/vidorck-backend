<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;

/**
 * An attempt whose Paystack reference is already held by an order (a manual order entered for the same payment,
 * product-spec §6.8). Linking goes on orders.checkout_attempt_id (unique); the attempt becomes SUCCEEDED when the
 * amounts agree, otherwise it is flagged MISMATCH and stays on the reconciliation list.
 */
final class LinkAttemptToOrder
{
    /** Order that already holds this attempt: by link, else by payment reference (case-insensitive). */
    public function existingOrder(CheckoutAttempt $attempt): ?Order
    {
        return Order::query()->where('checkout_attempt_id', $attempt->id)->first()
            ?? Order::query()->whereRaw('lower(payment_reference) = ?', [mb_strtolower((string) $attempt->paystack_reference)])->first();
    }

    /** Call inside a transaction with $attempt locked. Returns true when linked and SUCCEEDED. */
    public function link(CheckoutAttempt $attempt, Order $order, ?StaffUser $actor): bool
    {
        if ($order->checkout_attempt_id !== null && $order->checkout_attempt_id !== $attempt->id) {
            return false;
        }
        $before = $attempt->getRawOriginal('status');
        $paystackAmount = $this->paystackAmount($attempt);
        if ($paystackAmount !== $order->total_paid_minor) {
            if (! in_array($before, [CheckoutAttemptStatus::SUCCEEDED->value, CheckoutAttemptStatus::MISMATCH->value], true)) {
                $attempt->forceFill(['status' => CheckoutAttemptStatus::MISMATCH])->save();
                app(AuditLogger::class)->record($actor, 'checkout.mismatch.detected', $attempt, ['status' => $before], [
                    'status' => CheckoutAttemptStatus::MISMATCH->value, 'orderNumber' => $order->order_number,
                    'paystackAmountMinor' => $paystackAmount, 'orderAmountMinor' => $order->total_paid_minor,
                ]);
            }

            return false;
        }

        if ($order->checkout_attempt_id === null) {
            $order->forceFill(['checkout_attempt_id' => $attempt->id])->save();
        }
        if ($before !== CheckoutAttemptStatus::SUCCEEDED->value) {
            $attempt->forceFill(['status' => CheckoutAttemptStatus::SUCCEEDED])->save();
        }
        app(AuditLogger::class)->record($actor, 'checkout.attempt.linked', $attempt, ['status' => $before], [
            'status' => CheckoutAttemptStatus::SUCCEEDED->value, 'orderNumber' => $order->order_number,
            'source' => $order->source->value,
        ]);

        return true;
    }

    private function paystackAmount(CheckoutAttempt $attempt): int
    {
        /** @var mixed $snapshot */
        $snapshot = $attempt->getAttribute('verification_snapshot_json');
        $data = is_array($snapshot) ? (is_array($snapshot['data'] ?? null) ? $snapshot['data'] : $snapshot) : [];
        $amount = $data['amount'] ?? null;

        return is_int($amount) ? $amount : $attempt->amount_minor;
    }
}
