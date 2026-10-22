<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Http\Middleware\EnsureStaffSession;
use App\Modules\Staff\Http\Middleware\RequirePasswordChange;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    $this->staff = StaffUser::create(['name' => 'Ada', 'email' => 'ada@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN, 'must_change_password' => true]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

it('forced_password_change_blocks_admin_routes', function () {
    Route::get('/api/v1/admin/orders', fn () => ['ok' => true])
        ->middleware(['api', 'auth:sanctum', EnsureStaffSession::class, RequirePasswordChange::class]);
    $this->getJson('/api/v1/admin/orders')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    $this->getJson('/api/v1/admin/auth/me')->assertOk();
    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
});

it('changes passwords, revokes other sessions, and keeps the absolute deadline', function () {
    $oldId = session()->getId();
    $started = session('staff_authenticated_at');
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->staff->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->travel(1)->hours();
    $this->postJson('/api/v1/admin/auth/password', [
        'currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'New-Staff-Password7!', 'newPasswordConfirmation' => 'New-Staff-Password7!',
    ])->assertOk()->assertJsonPath('data.mustChangePassword', false);
    expect(session()->getId())->not->toBe($oldId)
        ->and(session('staff_authenticated_at'))->toBe($started)
        ->and($this->staff->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('New-Staff-Password7!', $this->staff->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'auth.password_changed')->count())->toBe(1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertOk();
    $this->travel(11)->hours();
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

it('rejects incorrect current, weak, identical, or unconfirmed new passwords', function (array $input) {
    $this->postJson('/api/v1/admin/auth/password', $input)->assertUnprocessable();
    expect($this->staff->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('Long-Staff-Password9!', $this->staff->fresh()->password))->toBeTrue();
})->with([
    [['currentPassword' => 'wrong', 'newPassword' => 'New-Staff-Password7!', 'newPasswordConfirmation' => 'New-Staff-Password7!']],
    [['currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'weak', 'newPasswordConfirmation' => 'weak']],
    [['currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'Long-Staff-Password9!', 'newPasswordConfirmation' => 'Long-Staff-Password9!']],
    [['currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'New-Staff-Password7!']],
]);
