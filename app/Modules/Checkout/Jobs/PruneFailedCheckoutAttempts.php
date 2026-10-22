<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Support\AttemptHasNoOrder;
use Illuminate\Support\Facades\DB;

/** Deletes old failed attempts that never verified, never produced an order and have no retry child. */
final class PruneFailedCheckoutAttempts
{
    public function __invoke(): void
    {
        AttemptHasNoOrder::apply(DB::table('checkout_attempts'))
            ->where('status', CheckoutAttemptStatus::FAILED->value)
            ->where('created_at', '<', now()->subDays(90))
            ->whereNull('verified_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('checkout_attempts as children')->whereColumn('children.parent_attempt_id', 'checkout_attempts.id'))
            ->chunkById(100, function ($attempts): void {
                foreach ($attempts as $attempt) {
                    AttemptHasNoOrder::apply(DB::table('checkout_attempts'))->where('id', $attempt->id)
                        ->where('status', CheckoutAttemptStatus::FAILED->value)
                        ->whereNull('verified_at')
                        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('checkout_attempts as children')->whereColumn('children.parent_attempt_id', 'checkout_attempts.id'))
                        ->delete();
                }
            });
    }
}
