<?php

namespace App\Modules\Complaints\Actions;

use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Repositories\ComplaintEventRepository;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClaimComplaint
{
    public function __construct(private readonly ComplaintEventRepository $events, private readonly AuditLogger $audit) {}

    public function execute(Complaint $complaint, StaffUser $actor): Complaint
    {
        DB::transaction(function () use ($complaint, $actor): void {
            $current = Complaint::query()->findOrFail($complaint->id);
            if ($current->status === ComplaintStatus::RESOLVED) {
                throw ValidationException::withMessages(['status' => 'Resolved complaints must be reopened before they can be claimed.']);
            }
            $previousId = $current->assigned_staff_id;
            $fromStatus = $current->status;
            $current->assigned_staff_id = $actor->id;
            if ($current->status === ComplaintStatus::NEW) {
                $current->status = ComplaintStatus::IN_PROGRESS;
            }
            $current->save();
            $this->events->insert(['complaint_id' => $current->id, 'staff_id' => $actor->id, 'type' => ComplaintEventType::CLAIMED->value, 'from_status' => null, 'to_status' => null, 'body' => $previousId === null ? null : 'Assignment transferred from staff '.$previousId.'.', 'created_at' => now()]);
            if ($fromStatus === ComplaintStatus::NEW) {
                $this->events->insert(['complaint_id' => $current->id, 'staff_id' => $actor->id, 'type' => ComplaintEventType::STATUS_CHANGED->value, 'from_status' => ComplaintStatus::NEW->value, 'to_status' => ComplaintStatus::IN_PROGRESS->value, 'created_at' => now()]);
            }
            $this->audit->record($actor, 'complaint.claimed', $current, ['assignedStaffId' => $previousId], ['assignedStaffId' => $actor->id]);
        });

        return $complaint->fresh();
    }
}
