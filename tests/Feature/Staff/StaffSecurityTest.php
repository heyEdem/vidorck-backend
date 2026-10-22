<?php

use App\Modules\Staff\Actions\ManageStaff;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Validated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->sales = StaffUser::create(['name' => 'Sales', 'email' => 'sales@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::SALES_ORDERS]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

it('fails login closed when recording the successful audit event fails', function () {
    DB::unprepared("CREATE TRIGGER reject_login_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'auth.login_succeeded' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertStatus(500);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    expect($this->sales->fresh()->last_login_at)->toBeNull();
});

it('rolls back staff and password mutations if their audit event cannot be written', function (string $action) {
    DB::unprepared("CREATE TRIGGER reject_mutation_audit BEFORE INSERT ON audit_logs WHEN NEW.action = '$action' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $this->sales->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $response = match ($action) {
        'staff.created' => $this->postJson('/api/v1/admin/staff', ['name' => 'New', 'email' => 'new@example.com', 'role' => 'ADMIN']),
        'staff.disabled' => $this->patchJson('/api/v1/admin/staff/'.$this->sales->id, ['isActive' => false]),
        'staff.password_reset' => $this->postJson('/api/v1/admin/staff/'.$this->sales->id.'/reset-password'),
        'auth.password_changed' => $this->postJson('/api/v1/admin/auth/password', [
            'currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'New-Staff-Password7!', 'newPasswordConfirmation' => 'New-Staff-Password7!',
        ]),
    };
    $response->assertStatus(500);
    expect(StaffUser::count())->toBe(2)
        ->and($this->sales->fresh()->is_active)->toBeTrue()
        ->and($this->sales->fresh()->session_version)->toBe(1)
        ->and(Hash::check('Long-Staff-Password9!', $this->sales->fresh()->password))->toBeTrue()
        ->and(Hash::check('Long-Staff-Password9!', $this->admin->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-session')->exists())->toBeTrue();
})->with(['staff.created', 'staff.disabled', 'staff.password_reset', 'auth.password_changed']);

it('preserves whitespace in password-manager credentials', function () {
    $password = '  New-Staff-Password7!  ';
    $this->postJson('/api/v1/admin/auth/password', [
        'currentPassword' => 'Long-Staff-Password9!', 'newPassword' => $password, 'newPasswordConfirmation' => $password,
    ])->assertOk();
    expect(Hash::check($password, $this->admin->fresh()->password))->toBeTrue();
});

it('invalidates the session even when logout auditing fails', function () {
    DB::unprepared("CREATE TRIGGER reject_logout_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'auth.logout' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    $this->postJson('/api/v1/admin/auth/logout')->assertStatus(500);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

it('throttles Admin password resets', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/admin/staff/'.$this->sales->id.'/reset-password')->assertOk();
    }
    $this->postJson('/api/v1/admin/staff/'.$this->sales->id.'/reset-password')->assertStatus(429)->assertHeader('Retry-After');
    expect(AuditLog::where('action', 'staff.password_reset')->count())->toBe(5);
});

it('verifies passwords outside the SQLite write transaction', function () {
    $level = DB::transactionLevel();
    $proxy = Mockery::mock(Hash::getFacadeRoot());
    $proxy->shouldReceive('check')->andReturnUsing(function (string $plain, string $hash) use ($level): bool {
        expect(DB::transactionLevel())->toBe($level);

        return password_verify($plain, $hash);
    });
    Hash::swap($proxy);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
    $this->postJson('/api/v1/admin/auth/password', [
        'currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'New-Staff-Password7!', 'newPasswordConfirmation' => 'New-Staff-Password7!',
    ])->assertOk();
});

it('rejects credentials reset between password verification and session issuance', function () {
    Event::listen(Validated::class, function (Validated $event): void {
        StaffUser::whereKey($event->user->getAuthIdentifier())->increment('session_version');
    });
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertUnprocessable();
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

it('rechecks the acting admin inside staff mutation transactions', function (string $operation, string $change) {
    StaffUser::whereKey($this->admin->id)->update(match ($change) {
        'disabled' => ['is_active' => false],
        'demoted' => ['role' => 'SALES_ORDERS'],
        'revoked' => ['session_version' => 2],
    });
    $action = app(ManageStaff::class);
    expect(fn () => match ($operation) {
        'create' => $action->create($this->admin, ['name' => 'New', 'email' => 'new@example.com', 'role' => 'ADMIN']),
        'update' => $action->update($this->admin, $this->sales, ['role' => 'ADMIN']),
        'reset' => $action->resetPassword($this->admin, $this->sales),
    })->toThrow(AuthorizationException::class);
    expect(StaffUser::count())->toBe(2)->and($this->sales->fresh()->role)->toBe(StaffRole::SALES_ORDERS);
})->with(['create', 'update', 'reset'])->with(['disabled', 'demoted', 'revoked']);

it('validates session restrictions before route model binding', function (string $restriction) {
    if ($restriction === 'forced') {
        $this->admin->update(['must_change_password' => true]);
    } else {
        $this->travel(12)->hours();
    }
    Auth::forgetGuards();
    $this->patchJson('/api/v1/admin/staff/999999', ['isActive' => false])
        ->assertStatus($restriction === 'forced' ? 403 : 401);
})->with(['forced', 'expired']);

it('logs a login lockout once per window and rejects stateless login before audit', function () {
    $this->withoutHeader('Origin');
    for ($attempt = 0; $attempt < 35; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'misplaced-secret', 'password' => 'wrong'])->assertUnauthorized();
    }
    expect(AuditLog::where('action', 'auth.login_locked_out')->count())->toBe(0);
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertStatus($attempt < 5 ? 422 : 429);
    }
    expect(AuditLog::where('action', 'auth.login_locked_out')->count())->toBe(1);
});
