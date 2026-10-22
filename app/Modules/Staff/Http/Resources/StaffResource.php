<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin StaffUser */
final class StaffResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'permissions' => array_map(static fn (Permission $permission): string => $permission->value, $this->role->permissions()),
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
