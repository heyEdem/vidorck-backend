<?php

namespace App\Modules\Staff\Actions;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ManageStaff
{
    public function __construct(private AuditLogger $audit, private RevokeStaffSessions $sessions) {}

    /** @param array<string, mixed> $input
     * @return array{staff: StaffUser, temporaryPassword: string}
     */
    public function create(StaffUser $actor, array $input): array
    {
        $temporary = Str::password(24);
        $hash = Hash::make($temporary);

        return DB::transaction(function () use ($actor, $input, $temporary, $hash): array {
            $this->authorizeActor($actor);
            $staff = StaffUser::create([...$input, 'password' => $hash, 'must_change_password' => true]);
            $this->audit->record($actor, 'staff.created', $staff, after: $this->snapshot($staff));

            return ['staff' => $staff, 'temporaryPassword' => $temporary];
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function update(StaffUser $actor, StaffUser $target, array $input): StaffUser
    {
        return DB::transaction(function () use ($actor, $target, $input): StaffUser {
            $this->authorizeActor($actor);
            $staff = StaffUser::findOrFail($target->id);
            $role = isset($input['role']) ? StaffRole::from($input['role']) : $staff->role;
            $active = array_key_exists('isActive', $input) ? (bool) $input['isActive'] : $staff->is_active;
            if ($actor->id === $staff->id && (! $active || $role !== StaffRole::ADMIN)) {
                throw new ApiException(409, 'CONFLICT', 'You cannot disable or demote your own account.');
            }
            if ($staff->is_active && $staff->role === StaffRole::ADMIN && (! $active || $role !== StaffRole::ADMIN)
                && StaffUser::where('role', StaffRole::ADMIN)->where('is_active', true)->count() <= 1) {
                throw new ApiException(409, 'CONFLICT', 'At least one active Admin is required.');
            }
            $before = $this->snapshot($staff);
            if ($role !== $staff->role || $active !== $staff->is_active) {
                $staff->fill(['role' => $role, 'is_active' => $active])->save();
                $this->sessions->execute($staff, $actor, 'staff_updated');
                $this->audit->record($actor, $active ? 'staff.updated' : 'staff.disabled', $staff, $before, $this->snapshot($staff));
            }

            return $staff;
        }, 3);
    }

    /** @return array{staff: StaffUser, temporaryPassword: string} */
    public function resetPassword(StaffUser $actor, StaffUser $target): array
    {
        if ($actor->id === $target->id) {
            throw new ApiException(409, 'CONFLICT', 'Use the change-password endpoint for your own account.');
        }

        $temporary = Str::password(24);
        $hash = Hash::make($temporary);

        return DB::transaction(function () use ($actor, $target, $temporary, $hash): array {
            $this->authorizeActor($actor);
            $staff = StaffUser::findOrFail($target->id);
            $staff->forceFill(['password' => $hash, 'must_change_password' => true])->save();
            $this->sessions->execute($staff, $actor, 'password_reset');
            $this->audit->record($actor, 'staff.password_reset', $staff);

            return ['staff' => $staff, 'temporaryPassword' => $temporary];
        }, 3);
    }

    private function authorizeActor(StaffUser $actor): void
    {
        $current = StaffUser::find($actor->id);
        if (! $current || ! $current->is_active || $current->role !== StaffRole::ADMIN
            || $current->session_version !== $actor->session_version) {
            throw new AuthorizationException;
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(StaffUser $staff): array
    {
        return ['name' => $staff->name, 'email' => $staff->email, 'role' => $staff->role->value, 'is_active' => $staff->is_active];
    }
}
