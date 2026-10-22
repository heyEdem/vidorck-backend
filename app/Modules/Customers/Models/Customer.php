<?php

namespace App\Modules\Customers\Models;

use App\Modules\Complaints\Models\Complaint;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property CarbonImmutable|null $first_order_at
 * @property CarbonImmutable|null $last_order_at
 */
class Customer extends Model
{
    protected $fillable = ['public_id', 'full_name', 'phone_e164', 'phone_display', 'email', 'first_order_at', 'last_order_at', 'orders_count', 'lifetime_paid_minor'];

    protected static function booted(): void
    {
        static::creating(fn (self $customer) => $customer->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['first_order_at' => 'immutable_datetime', 'last_order_at' => 'immutable_datetime', 'orders_count' => 'integer', 'lifetime_paid_minor' => 'integer'];
    }

    /** @return HasMany<CustomerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Complaint, $this> */
    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}
