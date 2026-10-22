<?php

namespace App\Modules\Complaints\Actions;

use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Repositories\ComplaintEventRepository;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;

final class AddComplaintNote
{
    public function __construct(private readonly ComplaintEventRepository $events, private readonly AuditLogger $audit) {}

    public function execute(Complaint $complaint, StaffUser $actor, string $body): Complaint
    {
        DB::transaction(function () use ($complaint, $actor, $body): void {
            $this->events->insert(['complaint_id' => $complaint->id, 'staff_id' => $actor->id, 'type' => ComplaintEventType::NOTE->value, 'body' => trim($body), 'created_at' => now()]);
            $complaint->newQuery()->whereKey($complaint->id)->update(['updated_at' => now()]);
            $this->audit->record($actor, 'complaint.note.added', $complaint, [], ['eventId' => DB::getPdo()->lastInsertId()]);
        });

        return $complaint->fresh();
    }
}
