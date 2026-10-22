<?php

namespace App\Modules\Complaints\Models;

use App\Modules\Complaints\Enums\ComplaintContactMethod;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Complaints\Enums\ComplaintTopic;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ComplaintTopic $topic
 * @property ComplaintStatus $status
 * @property ComplaintContactMethod $contact_method
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Complaint extends Model
{
    protected $fillable = ['reference', 'name', 'topic', 'message', 'contact_method', 'phone_e164', 'email', 'order_number_entered', 'order_id', 'customer_id', 'status', 'assigned_staff_id', 'resolved_at'];

    protected function casts(): array
    {
        return ['topic' => ComplaintTopic::class, 'contact_method' => ComplaintContactMethod::class, 'status' => ComplaintStatus::class, 'resolved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'assigned_staff_id');
    }

    /** @return HasMany<ComplaintEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ComplaintEvent::class)->orderBy('id');
    }
}
