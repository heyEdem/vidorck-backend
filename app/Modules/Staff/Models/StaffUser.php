<?php

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\StaffRole;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * SQLite CHECK constraints are declared in raw SQL; document their model types.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property StaffRole $role
 * @property bool $is_active
 * @property bool $must_change_password
 * @property int $session_version
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StaffUser extends Authenticatable
{
    protected $fillable = [
        'name', 'email', 'password', 'role', 'is_active', 'must_change_password',
    ];

    protected $hidden = ['password', 'session_version'];

    protected $attributes = ['is_active' => true, 'must_change_password' => false, 'session_version' => 1];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => StaffRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'session_version' => 'integer',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }
}
