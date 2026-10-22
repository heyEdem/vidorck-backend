<?php

namespace App\Modules\Checkout\Support;

use Illuminate\Database\Query\Builder;

/** Excludes attempts with a linked order or an unlinked (manual) order sharing the Paystack reference. */
final class AttemptHasNoOrder
{
    public static function apply(Builder $query): Builder
    {
        return $query
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('orders')->whereColumn('orders.checkout_attempt_id', 'checkout_attempts.id'))
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('orders')->whereRaw('lower(orders.payment_reference) = lower(checkout_attempts.paystack_reference)'));
    }
}
