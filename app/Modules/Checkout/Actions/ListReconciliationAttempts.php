<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListReconciliationAttempts
{
    /**
     * Attempts needing reconciliation: MISMATCH, or verified but without an order (product-spec §6.8).
     *
     * @return Builder<CheckoutAttempt>
     */
    public function query(): Builder
    {
        return CheckoutAttempt::query()->where(function (Builder $query): void {
            $query->where('status', CheckoutAttemptStatus::MISMATCH->value)
                ->orWhere(function (Builder $verified): void {
                    $verified->whereNotNull('verified_at')->whereDoesntHave('order');
                });
        });
    }

    /**
     * @param  array{page?: int, perPage?: int}  $filters
     * @return LengthAwarePaginator<int, CheckoutAttempt>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        return $this->query()
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['perPage'] ?? 15, ['*'], 'page', $filters['page'] ?? 1);
    }
}
