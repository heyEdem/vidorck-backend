<?php

namespace App\Modules\Orders\Models;

use App\Modules\Staff\Models\StaffUser;
use App\Support\Models\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $created_at */
class OrderNote extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $fillable = ['order_id', 'staff_id', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
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
