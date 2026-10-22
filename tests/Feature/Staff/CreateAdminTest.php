<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('bootstraps an admin using supplied credentials', function () {
    $this->artisan('staff:create-admin')
        ->expectsQuestion('Name', 'Ada')
        ->expectsQuestion('Email', 'ADA@example.com')
        ->expectsQuestion('Password', 'Long-Staff-Password9!')
        ->assertSuccessful();

    $admin = StaffUser::sole();
    expect($admin->role)->toBe(StaffRole::ADMIN)
        ->and($admin->email)->toBe('ada@example.com')
        ->and(Hash::check('Long-Staff-Password9!', $admin->password))->toBeTrue()
        ->and($admin->must_change_password)->toBeFalse();

    $this->artisan('staff:create-admin')->assertFailed();
    expect(StaffUser::count())->toBe(1);
});

it('allows an additional admin only with force and rejects weak passwords', function () {
    StaffUser::create(['name' => 'First', 'email' => 'first@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);

    $this->artisan('staff:create-admin --force')
        ->expectsQuestion('Name', 'Second')->expectsQuestion('Email', 'second@example.com')
        ->expectsQuestion('Password', 'weak')->assertFailed();
    expect(StaffUser::count())->toBe(1);

    $this->artisan('staff:create-admin --force')
        ->expectsQuestion('Name', 'Second')->expectsQuestion('Email', 'second@example.com')
        ->expectsQuestion('Password', 'Another-Strong-Password7!')->assertSuccessful();
    expect(StaffUser::count())->toBe(2);
});

it('bootstraps an admin from config once and stays idempotent', function () {
    config(['auth.bootstrap_admin' => ['name' => 'Boot', 'email' => ' BOOT@example.com ', 'password' => 'Long-Staff-Password9!']]);

    $this->artisan('staff:create-admin --from-env')->assertSuccessful();
    $admin = StaffUser::sole();
    expect($admin->email)->toBe('boot@example.com')
        ->and(Hash::check('Long-Staff-Password9!', $admin->password))->toBeTrue()
        ->and(AuditLog::where('action', 'staff.bootstrap')->count())->toBe(1);

    config(['auth.bootstrap_admin.password' => 'Changed-Staff-Password9!']);
    $this->artisan('staff:create-admin --from-env')->assertSuccessful();
    expect(StaffUser::count())->toBe(1)
        ->and(Hash::check('Long-Staff-Password9!', $admin->fresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'staff.bootstrap')->count())->toBe(1);
});

it('rejects missing or weak bootstrap credentials without creating an admin', function (array $credentials) {
    config(['auth.bootstrap_admin' => $credentials]);

    $this->artisan('staff:create-admin --from-env')->assertFailed();
    expect(StaffUser::count())->toBe(0)->and(AuditLog::count())->toBe(0);
})->with([
    'missing' => [['name' => 'Boot', 'email' => null, 'password' => null]],
    'weak password' => [['name' => 'Boot', 'email' => 'boot@example.com', 'password' => 'weak']],
]);
