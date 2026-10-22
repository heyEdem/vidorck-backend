<?php

namespace App\Modules\Staff\Policies;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;

final class StaffPolicy
{
    public function viewAny(StaffUser $actor): bool
    {
        return $actor->is_active && $actor->role === StaffRole::ADMIN;
    }

    public function create(StaffUser $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(StaffUser $actor, StaffUser $staff): bool
    {
        return $this->viewAny($actor);
    }

    public function resetPassword(StaffUser $actor, StaffUser $staff): bool
    {
        return $this->viewAny($actor);
    }
}
