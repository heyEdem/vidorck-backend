<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** product-spec §6.8: search by name, phone, or email. */
final class ListCustomers
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = Customer::query();
        if (is_string($filters['search'] ?? null) && trim($filters['search']) !== '') {
            $term = trim($filters['search']);
            $like = '%'.addcslashes($term, '\\%_').'%';
            try {
                $e164 = PhoneNumber::fromGhanaInput($term)->e164;
            } catch (\InvalidArgumentException) {
                $e164 = null;
            }
            $query->where(function (Builder $q) use ($like, $e164): void {
                $q->whereRaw("full_name LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("email LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("phone_display LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("phone_e164 LIKE ? ESCAPE '\\'", [$like]);
                if ($e164 !== null) {
                    $q->orWhere('phone_e164', $e164);
                }
            });
        }
        $page = is_numeric($filters['page'] ?? null) ? (int) $filters['page'] : 1;
        $perPage = is_numeric($filters['perPage'] ?? null) ? (int) $filters['perPage'] : 15;

        return $query->orderByDesc('last_order_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }
}
