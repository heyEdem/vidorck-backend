<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('stores staff passwords with Argon2id and hides credentials', function () {
    $staff = StaffUser::create([
        'name' => 'Ada', 'email' => 'ADA@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN,
    ]);

    expect($staff->password)->toStartWith('$argon2id$')
        ->and(Hash::check('Long-Staff-Password9!', $staff->password))->toBeTrue()
        ->and($staff->fresh()->role)->toBe(StaffRole::ADMIN)
        ->and($staff->fresh()->is_active)->toBeTrue()
        ->and($staff->email)->toBe('ada@example.com')
        ->and($staff->toArray())->not->toHaveKeys(['password', 'remember_token'])
        ->and(config('auth.providers.staff.model'))->toBe(StaffUser::class);
});

it('enforces staff roles in SQLite', function () {
    DB::table('staff_users')->insert([
        'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'hash', 'role' => 'OWNER',
    ]);
})->throws(QueryException::class);

it('enforces case insensitive unique staff email addresses', function () {
    DB::table('staff_users')->insert([
        'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'hash', 'role' => 'ADMIN',
    ]);
    DB::table('staff_users')->insert([
        'name' => 'Ada', 'email' => 'ADA@example.com', 'password' => 'hash', 'role' => 'ADMIN',
    ]);
})->throws(QueryException::class);
