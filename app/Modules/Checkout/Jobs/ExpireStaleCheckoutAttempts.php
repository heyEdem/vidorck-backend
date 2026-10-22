<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Support\AttemptHasNoOrder;
use Illuminate\Support\Facades\DB;

/** Marks unpaid, unverified attempts past their expiry as abandoned. Not audited, like other provider-driven status changes. */
final class ExpireStaleCheckoutAttempts
{
    public function __invoke(): void
    {
        $now = now();
        AttemptHasNoOrder::apply(DB::table('checkout_attempts'))
            ->where('status', CheckoutAttemptStatus::PENDING->value)
            ->where('expires_at', '<=', $now)
            ->whereNull('verified_at')
            ->chunkById(100, function ($attempts) use ($now): void {
                foreach ($attempts as $attempt) {
                    AttemptHasNoOrder::apply(DB::table('checkout_attempts'))->where('id', $attempt->id)
                        ->where('status', CheckoutAttemptStatus::PENDING->value)
                        ->whereNull('verified_at')
                        ->update(['status' => CheckoutAttemptStatus::ABANDONED->value, 'updated_at' => $now]);
                }
            });
    }
}
