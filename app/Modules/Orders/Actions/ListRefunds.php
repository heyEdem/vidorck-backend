<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListRefunds
{
    /**
     * Provider-confirmed refunds whose order could not be moved to REFUNDED (needs staff reconciliation).
     *
     * @return Builder<RefundRequest>
     */
    public function needsReconciliation(): Builder
    {
        return RefundRequest::query()->where('status', RefundStatus::COMPLETED->value)
            ->whereHas('order', static fn (Builder $o): Builder => $o->where('status', '!=', OrderStatus::REFUNDED->value));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RefundRequest>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = isset($filters['needsReconciliation']) && filter_var($filters['needsReconciliation'], FILTER_VALIDATE_BOOLEAN)
            ? $this->needsReconciliation() : RefundRequest::query();
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['open']) && filter_var($filters['open'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::open()));
        }
        if (is_string($filters['orderNumber'] ?? null) && $filters['orderNumber'] !== '') {
            $number = $filters['orderNumber'];
            $query->whereHas('order', static fn (Builder $o): Builder => $o->where('order_number', $number));
        }
        $page = is_numeric($filters['page'] ?? null) ? (int) $filters['page'] : 1;
        $perPage = is_numeric($filters['perPage'] ?? null) ? (int) $filters['perPage'] : 15;

        return $query->with('order')->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Refund reconciliation items: every `refund.reconciliation_required` audit row (including confirmations with no
     * in-flight request, which have no COMPLETED request) plus any derived COMPLETED-but-not-REFUNDED request not
     * already covered by such a row.
     */
    public function reconciliationCount(): int
    {
        $flagged = AuditLog::query()->where('action', 'refund.reconciliation_required');
        $covered = (clone $flagged)->where('target_type', (new RefundRequest)->getMorphClass())->pluck('target_id')->all();

        return (clone $flagged)->count()
            + $this->needsReconciliation()->whereNotIn('id', array_map('intval', $covered))->count();
    }
}
