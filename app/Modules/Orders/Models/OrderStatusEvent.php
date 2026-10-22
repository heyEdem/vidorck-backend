<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property OrderActorType $actor_type
 * @property CarbonImmutable|null $created_at
 */
class OrderStatusEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Order status events are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Order status events are append-only.'));
    }

    protected $fillable = ['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['from_status' => OrderStatus::class, 'to_status' => OrderStatus::class, 'actor_type' => OrderActorType::class, 'actor_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
