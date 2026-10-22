<?php

namespace App\Modules\Complaints\Actions;

use App\Modules\Complaints\Models\Complaint;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListComplaints
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Complaint>
     */
    public function execute(array $filters, StaffUser $staff): LengthAwarePaginator
    {
        $query = Complaint::query()->with('assignedStaff');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['mine'] ?? false) === true || ($filters['mine'] ?? null) === '1') {
            $query->where('assigned_staff_id', $staff->id);
        }
        if (($filters['unassigned'] ?? false) === true || ($filters['unassigned'] ?? null) === '1') {
            $query->whereNull('assigned_staff_id');
        }

        $query->orderByRaw("CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END")
            ->orderBy('created_at')->orderBy('id');

        return $query->paginate((int) ($filters['perPage'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
