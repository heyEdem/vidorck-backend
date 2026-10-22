<?php

namespace App\Modules\Orders\Models;

use App\Modules\Staff\Models\StaffUser;
use App\Support\Models\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $refund_request_id
 * @property int $staff_id
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class RefundNote extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $fillable = ['refund_request_id', 'staff_id', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RefundRequest, $this> */
    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_id');
    }
}
