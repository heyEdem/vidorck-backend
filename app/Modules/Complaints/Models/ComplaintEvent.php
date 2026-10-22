<?php

namespace App\Modules\Complaints\Models;

use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable timeline record. Persist through ComplaintEventRepository::insert only.
 *
 * @property ComplaintEventType $type
 * @property ComplaintStatus|null $from_status
 * @property ComplaintStatus|null $to_status
 * @property CarbonImmutable $created_at
 */
class ComplaintEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['complaint_id', 'staff_id', 'type', 'from_status', 'to_status', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['type' => ComplaintEventType::class, 'from_status' => ComplaintStatus::class, 'to_status' => ComplaintStatus::class, 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('Complaint events are immutable.'));
        static::deleting(static fn () => throw new \LogicException('Complaint events are immutable.'));
    }

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }
}
