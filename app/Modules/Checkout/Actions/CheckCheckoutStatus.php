<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackTransactionNotFound;
use App\Modules\Checkout\Paystack\PaystackUnavailable;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\LinkAttemptToOrder;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CheckCheckoutStatus
{
    /** @return array{status: string, orderNumber?: string, trackingToken?: string, retryable?: bool} */
    public function execute(CheckoutAttempt $attempt, bool $scheduled = false): array
    {
        $attempt = $attempt->fresh();
        if ($attempt === null) {
            return ['status' => 'FAILED', 'retryable' => false];
        }

        $order = $attempt->order()->first();
        if ($order !== null) {
            return $this->succeeded($order);
        }
        $referenced = app(LinkAttemptToOrder::class)->existingOrder($attempt);
        if ($referenced !== null) {
            // Reference already on an order (manual order): fulfilled, no provider call.
            if (DB::transaction(fn () => $this->settle($attempt, $referenced))) {
                return $this->succeeded($referenced);
            }
        }
        if ($attempt->verified_at !== null) {
            $order = app(CreateOrderFromAttempt::class)->execute($attempt);

            return $this->succeeded($order);
        }

        $status = $attempt->getRawOriginal('status');
        if ($status === CheckoutAttemptStatus::MISMATCH->value) {
            return ['status' => 'FAILED', 'retryable' => false];
        }
        if (in_array($status, [CheckoutAttemptStatus::FAILED->value, CheckoutAttemptStatus::ABANDONED->value], true)) {
            return ['status' => 'FAILED', 'retryable' => true];
        }
        if ($status === CheckoutAttemptStatus::SUCCEEDED->value) {
            return ['status' => 'PENDING'];
        }

        $now = now();
        $expired = Carbon::parse($attempt->expires_at)->lessThanOrEqualTo($now);
        $oldEnough = $attempt->created_at !== null && $attempt->created_at->lessThanOrEqualTo($now->copy()->subSeconds(10));
        if (! $oldEnough || ! $this->claim($attempt, $now, $scheduled)) {
            return $this->current($attempt);
        }

        try {
            // Claim commits before any provider I/O. The durable timestamp also bounds concurrent callers.
            $verified = app(PaystackClient::class)->verify($attempt->paystack_reference);
        } catch (PaystackUnavailable|PaystackTransactionNotFound) {
            return $this->current($attempt->fresh() ?? $attempt);
        }

        $providerStatus = $verified['status'] ?? null;
        if ($providerStatus === 'success') {
            app(HandleChargeSuccess::class)->execute($attempt, $verified);

            return $this->current($attempt->fresh() ?? $attempt);
        }

        if (is_string($providerStatus) && in_array($providerStatus, ['failed', 'reversed'], true)) {
            $this->setTerminal($attempt, CheckoutAttemptStatus::FAILED);
        } elseif ($expired && $providerStatus === 'abandoned') {
            $this->setTerminal($attempt, CheckoutAttemptStatus::ABANDONED);
        } elseif (! is_string($providerStatus)
            || ! in_array($providerStatus, ['pending', 'ongoing', 'processing', 'queued', 'abandoned'], true)) {
            app(HandleChargeSuccess::class)->execute($attempt, $verified);
        }

        return $this->current($attempt->fresh() ?? $attempt);
    }

    private function settle(CheckoutAttempt $attempt, Order $order): bool
    {
        $locked = CheckoutAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

        return $locked !== null && app(LinkAttemptToOrder::class)->link($locked, $order, null);
    }

    private function claim(CheckoutAttempt $attempt, Carbon $now, bool $scheduled = false): bool
    {
        return DB::transaction(function () use ($attempt, $now, $scheduled): bool {
            $query = DB::table('checkout_attempts')->where('id', $attempt->id)->where('status', CheckoutAttemptStatus::PENDING->value);
            $query->where(function ($builder) use ($now): void {
                $builder->whereNull('last_verified_at')->orWhere('last_verified_at', '<=', $now->copy()->subSeconds(10));
            });

            $values = ['last_verified_at' => $now, 'updated_at' => $now];
            if ($scheduled) {
                // The one scheduled attempt is only consumed by a claim that proceeds to the provider.
                $query->whereNull('scheduled_verified_at');
                $values['scheduled_verified_at'] = $now;
            }

            return $query->update($values) === 1;
        }, 3);
    }

    private function setTerminal(CheckoutAttempt $attempt, CheckoutAttemptStatus $status): void
    {
        DB::table('checkout_attempts')->where('id', $attempt->id)
            ->whereNotIn('status', [CheckoutAttemptStatus::SUCCEEDED->value, CheckoutAttemptStatus::MISMATCH->value])
            ->whereNull('verified_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('orders')->whereColumn('orders.checkout_attempt_id', 'checkout_attempts.id'))
            ->update(['status' => $status->value, 'updated_at' => now()]);
    }

    /** @return array{status: string, orderNumber?: string, trackingToken?: string, retryable?: bool} */
    private function current(CheckoutAttempt $attempt): array
    {
        $attempt = $attempt->fresh() ?? $attempt;
        $order = $attempt->order()->first();
        if ($order !== null) {
            return $this->succeeded($order);
        }
        $status = $attempt->getRawOriginal('status');
        if ($status === CheckoutAttemptStatus::MISMATCH->value) {
            return ['status' => 'FAILED', 'retryable' => false];
        }
        if (in_array($status, [CheckoutAttemptStatus::FAILED->value, CheckoutAttemptStatus::ABANDONED->value], true)) {
            return ['status' => 'FAILED', 'retryable' => true];
        }

        return ['status' => 'PENDING'];
    }

    /** @return array{status: string, orderNumber: string, trackingToken: string} */
    private function succeeded(Order $order): array
    {
        return ['status' => 'SUCCEEDED', 'orderNumber' => $order->order_number, 'trackingToken' => $order->tracking_token];
    }
}
