<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Actions\CheckCheckoutStatus;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Verifies each stale pending attempt with Paystack exactly once; a provider failure still uses that attempt. */
final class ReverifyPendingCheckoutAttempts
{
    public function __invoke(): void
    {
        CheckoutAttempt::query()
            ->where('status', CheckoutAttemptStatus::PENDING->value)
            ->where('created_at', '<=', now()->subMinutes(15))
            ->whereNull('scheduled_verified_at')
            ->whereDoesntHave('order')
            ->chunkById(50, function ($attempts): void {
                foreach ($attempts as $attempt) {
                    try {
                        app(CheckCheckoutStatus::class)->execute($attempt, scheduled: true);
                    } catch (Throwable $exception) {
                        Log::warning('Scheduled checkout re-verification failed.', ['attempt_id' => $attempt->public_id, 'exception' => $exception::class]);
                    }
                }
            });
    }
}
