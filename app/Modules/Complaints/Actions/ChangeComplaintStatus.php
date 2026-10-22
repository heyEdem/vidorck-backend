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

final class ChangeComplaintStatus
{
    public function __construct(private readonly ComplaintEventRepository $events, private readonly AuditLogger $audit) {}

    public function execute(Complaint $complaint, StaffUser $actor, string $to, ?string $note): Complaint
    {
        DB::transaction(function () use ($complaint, $actor, $to, $note): void {
            $current = Complaint::query()->findOrFail($complaint->id);
            $from = $current->status;
            $target = ComplaintStatus::from($to);
            $valid = ($from === ComplaintStatus::NEW && in_array($target, [ComplaintStatus::IN_PROGRESS, ComplaintStatus::RESOLVED], true))
                || ($from === ComplaintStatus::IN_PROGRESS && $target === ComplaintStatus::RESOLVED)
                || ($from === ComplaintStatus::RESOLVED && $target === ComplaintStatus::IN_PROGRESS);
            if (! $valid) {
                throw ValidationException::withMessages(['toStatus' => 'This complaint status transition is not allowed.']);
            }
            if (($target === ComplaintStatus::RESOLVED || $from === ComplaintStatus::RESOLVED) && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'A note is required to resolve or reopen a complaint.']);
            }
            $current->status = $target;
            $current->resolved_at = $target === ComplaintStatus::RESOLVED ? now()->toImmutable() : null;
            if ($target === ComplaintStatus::RESOLVED) {
                $current->assigned_staff_id = $actor->id;
            }
            $current->save();
            $this->events->insert(['complaint_id' => $current->id, 'staff_id' => $actor->id, 'type' => ComplaintEventType::STATUS_CHANGED->value, 'from_status' => $from->value, 'to_status' => $target->value, 'body' => $note === null ? null : trim($note), 'created_at' => now()]);
            $this->audit->record($actor, 'complaint.status.changed', $current, ['status' => $from->value], ['status' => $target->value]);
        });

        return $complaint->fresh();
    }
}
