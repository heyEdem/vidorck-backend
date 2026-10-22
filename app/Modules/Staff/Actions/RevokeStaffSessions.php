<?php

namespace App\Modules\Staff\Actions;

use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;

final class RevokeStaffSessions
{
    // Call inside the same transaction as the account or credential mutation.
    public function execute(StaffUser $staff, StaffUser $actor, string $reason): void
    {
        $staff->forceFill(['session_version' => $staff->session_version + 1])->save();
        DB::table('sessions')->where('user_id', $staff->id)->delete();
        app(AuditLogger::class)->record($actor, 'auth.sessions_revoked', $staff, after: ['reason' => $reason]);
    }
}
