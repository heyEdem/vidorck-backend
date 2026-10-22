<?php

namespace App\Modules\Orders\Models;

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrderStatus $status
 * @property OrderSource $source
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $customer_snapshot_json
 * @property array<string, mixed>|null $delivery_snapshot_json
 */
class Order extends Model
{
    protected $fillable = [
        'order_number', 'tracking_token', 'source', 'customer_id', 'checkout_attempt_id', 'status', 'subtotal_minor',
        'total_paid_minor', 'currency', 'payment_method', 'payment_reference', 'customer_snapshot_json',
        'delivery_snapshot_json', 'delivery_fee_note', 'created_by_staff_id', 'paid_at',
    ];

    protected static function booted(): void
    {
        // Status is written only by OrderStateMachine after creation; new orders enter as PAID.
        static::creating(static function (self $order): void {
            if ($order->getAttribute('status') !== null && $order->getAttribute('status') !== OrderStatus::PAID) {
                throw new \LogicException('Orders must be created with status PAID.');
            }
        });
        static::updating(static function (self $order): void {
            if ($order->isDirty('status')) {
                throw new \LogicException('Order status can only change through OrderStateMachine.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source' => OrderSource::class, 'status' => OrderStatus::class, 'subtotal_minor' => 'integer',
            'total_paid_minor' => 'integer', 'customer_snapshot_json' => 'array', 'delivery_snapshot_json' => 'array',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CheckoutAttempt, $this> */
    public function checkoutAttempt(): BelongsTo
    {
        return $this->belongsTo(CheckoutAttempt::class);
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }

    /** @return HasMany<OrderNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /** @return HasMany<OrderContentRevision, $this> */
    public function contentRevisions(): HasMany
    {
        return $this->hasMany(OrderContentRevision::class);
    }

    /** @return HasMany<Complaint, $this> */
    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}
