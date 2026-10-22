<?php

use App\Modules\Staff\Enums\StaffRole;
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
