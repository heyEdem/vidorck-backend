<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** product-spec §6.8: filters status, source, date range, search by order number/name/phone. */
final class ListOrders
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = Order::query()->with('customer');
        foreach (['status', 'source'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (is_string($filters['from'] ?? null)) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'])->utc());
        }
        if (is_string($filters['to'] ?? null)) {
            $to = CarbonImmutable::parse($filters['to'])->utc();
            $query->where('created_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to']) ? $to->endOfDay() : $to);
        }
        if (is_string($filters['search'] ?? null) && trim($filters['search']) !== '') {
            $this->search($query, trim($filters['search']));
        }
        $page = is_numeric($filters['page'] ?? null) ? (int) $filters['page'] : 1;
        $perPage = is_numeric($filters['perPage'] ?? null) ? (int) $filters['perPage'] : 15;

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @param  Builder<Order>  $query */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '\\%_').'%';
        try {
            $e164 = PhoneNumber::fromGhanaInput($term)->e164;
        } catch (\InvalidArgumentException) {
            $e164 = null;
        }
        $query->where(function (Builder $q) use ($like, $e164): void {
            $q->whereRaw("order_number LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('customer', function (Builder $c) use ($like, $e164): void {
                    $c->whereRaw("full_name LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("phone_display LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("phone_e164 LIKE ? ESCAPE '\\'", [$like]);
                    if ($e164 !== null) {
                        $c->orWhere('phone_e164', $e164);
                    }
                });
        });
    }
}
