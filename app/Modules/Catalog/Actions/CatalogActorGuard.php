<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Auth\Access\AuthorizationException;

final class CatalogActorGuard
{
    public static function assertAdmin(StaffUser $actor): void
    {
        $current = StaffUser::query()->lockForUpdate()->find($actor->getKey());
        if (! $current || ! $current->is_active || $current->role !== StaffRole::ADMIN
            || $current->session_version !== $actor->session_version || $current->must_change_password) {
            throw new AuthorizationException;
        }
    }
}
