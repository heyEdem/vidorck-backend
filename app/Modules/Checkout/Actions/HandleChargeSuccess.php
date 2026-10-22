<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Paystack\PaymentDataAllowlist;
use App\Modules\Checkout\Paystack\PaystackVerificationPending;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\LinkAttemptToOrder;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Actions\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class HandleChargeSuccess
{
    /** @param array<string, mixed> $verified */
    public function execute(CheckoutAttempt $attempt, array $verified): ?Order
    {
        $attempt = $attempt->fresh();
        if ($attempt === null) {
            return null;
        }
        $existingOrder = $attempt->order()->first();
        if ($existingOrder !== null) {
            return $existingOrder;
        }

        $referenced = app(LinkAttemptToOrder::class)->existingOrder($attempt);
        if ($referenced !== null) {
            return app(CreateOrderFromAttempt::class)->execute($attempt);
        }

        $providerStatus = $verified['status'] ?? null;
        if (is_string($providerStatus) && in_array($providerStatus, ['pending', 'ongoing', 'processing', 'queued'], true)) {
            throw new PaystackVerificationPending;
        }

        $metadata = PaymentDataAllowlist::metadata($verified['metadata'] ?? null);
        $valid = ($verified['status'] ?? null) === 'success'
            && ($verified['reference'] ?? null) === $attempt->paystack_reference
            && ($verified['currency'] ?? null) === 'GHS'
            && is_int($verified['amount'] ?? null)
            && $verified['amount'] === $attempt->amount_minor
            && ($metadata['attemptId'] ?? null) === $attempt->public_id;

        if (! $valid) {
            DB::transaction(function () use ($attempt): void {
                $updated = DB::table('checkout_attempts')->where('id', $attempt->id)
                    ->whereNotIn('status', [CheckoutAttemptStatus::SUCCEEDED->value, CheckoutAttemptStatus::MISMATCH->value])
                    ->whereNull('verified_at')
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('orders')->whereColumn('orders.checkout_attempt_id', 'checkout_attempts.id'))
                    ->update(['status' => CheckoutAttemptStatus::MISMATCH->value, 'updated_at' => now()]);
                if ($updated === 1) {
                    app(AuditLogger::class)->record(null, 'checkout.mismatch.detected', $attempt, ['status' => $attempt->getRawOriginal('status')], ['status' => CheckoutAttemptStatus::MISMATCH->value]);
                }
            }, 3);
            Log::warning('Paystack verification did not match checkout attempt.', [
                'attempt_id' => $attempt->public_id,
                'reference' => $attempt->paystack_reference,
            ]);

            return null;
        }

        $snapshot = PaymentDataAllowlist::snapshot($verified);
        CheckoutAttempt::query()->whereKey($attempt->id)
            ->whereDoesntHave('order')
            ->whereNot('status', CheckoutAttemptStatus::SUCCEEDED->value)
            ->update([
                'verified_at' => now(), 'verification_snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

        return app(CreateOrderFromAttempt::class)->execute($attempt->fresh());
    }
}
