<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\RefundChannel;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Mutable workflow row; decision history lives in append-only refund_transactions/refund_notes.
 *
 * @property int $id
 * @property int $provider_attempts
 * @property int $order_id
 * @property RefundStatus $status
 * @property RefundChannel $channel
 * @property string $reason
 * @property int|null $decided_by_staff_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property string|null $requester_name
 * @property string|null $requester_phone_e164
 * @property Carbon|null $created_at
 */
class RefundRequest extends Model
{
    protected $fillable = [
        'order_id', 'channel', 'reason', 'status', 'requester_name', 'requester_phone_e164',
        'decided_by_staff_id', 'decided_at', 'decision_reason',
    ];

    protected static function booted(): void
    {
        static::deleting(static fn (): never => throw new \LogicException('Refund requests are never deleted.'));
    }

    protected function casts(): array
    {
        return ['channel' => RefundChannel::class, 'status' => RefundStatus::class, 'decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by_staff_id');
    }

    /** @return HasMany<RefundTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(RefundTransaction::class);
    }

    /** @return HasMany<RefundNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(RefundNote::class);
    }
}
