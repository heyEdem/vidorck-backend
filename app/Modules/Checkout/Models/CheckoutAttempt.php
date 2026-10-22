<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CheckoutAttempt extends Model
{
    protected $fillable = [
        'public_id', 'paystack_reference', 'customer_snapshot_json', 'delivery_snapshot_json', 'items_snapshot_json',
        'amount_minor', 'currency', 'status', 'expires_at', 'parent_attempt_id', 'last_verified_at', 'verified_at',
        'verification_snapshot_json', 'scheduled_verified_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            $attempt->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'customer_snapshot_json' => 'array', 'delivery_snapshot_json' => 'array', 'items_snapshot_json' => 'array',
            'verification_snapshot_json' => 'array', 'amount_minor' => 'integer', 'status' => CheckoutAttemptStatus::class,
            'expires_at' => 'immutable_datetime', 'last_verified_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
            'scheduled_verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parentAttempt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_attempt_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_attempt_id');
    }

    /** @return HasOne<Order, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
