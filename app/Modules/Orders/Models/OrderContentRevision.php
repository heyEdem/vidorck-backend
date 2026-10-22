<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\SettlementMethod;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of an Admin edit to an order's items. Mistakes are corrected only by a further revision.
 *
 * @property int $id
 * @property int $order_id
 * @property int $staff_id
 * @property string $reason
 * @property list<array<string, mixed>> $before_items_json
 * @property list<array<string, mixed>> $after_items_json
 * @property int $before_subtotal_minor
 * @property int $after_subtotal_minor
 * @property int $difference_minor
 * @property SettlementMethod $settlement_method
 * @property string|null $settlement_note
 * @property CarbonImmutable $created_at
 */
class OrderContentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'staff_id', 'reason', 'before_items_json', 'after_items_json', 'before_subtotal_minor',
        'after_subtotal_minor', 'difference_minor', 'settlement_method', 'settlement_note', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Order content revisions are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Order content revisions are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'before_items_json' => 'array', 'after_items_json' => 'array', 'before_subtotal_minor' => 'integer',
            'after_subtotal_minor' => 'integer', 'difference_minor' => 'integer',
            'settlement_method' => SettlementMethod::class, 'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_id');
    }
}
