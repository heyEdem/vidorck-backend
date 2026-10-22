<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    $this->staff = StaffUser::create(['name' => 'Ada', 'email' => 'ada@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
});

it('logs in with a rotated session and safe staff resource', function () {
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $oldId = session()->getId();
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ADA@example.com', 'password' => 'Long-Staff-Password9!'])
        ->assertOk()->assertJsonPath('data.role', 'ADMIN')->assertJsonPath('data.mustChangePassword', false)
        ->assertJsonMissingPath('data.password')->assertHeader('Cache-Control', 'no-store, private');
    expect(session()->getId())->not->toBe($oldId)
        ->and($this->staff->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'auth.login_succeeded')->count())->toBe(1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

it('login_error_is_generic', function () {
    $unknown = $this->postJson('/api/v1/admin/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertUnprocessable()->json();
    $known = $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])->assertUnprocessable()->json();
    $this->staff->update(['is_active' => false]);
    $disabled = $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!'])->assertUnprocessable()->json();
    expect($known)->toBe($unknown)->toBe($disabled)
        ->and($known['message'])->toBe('Invalid email or password.')
        ->and(AuditLog::where('action', 'auth.login_failed')->count())->toBe(3);
});

it('login_requires_xsrf', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!'])
        ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
});

it('accepts a real XSRF token for login and rejects authenticated mutations without it', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $token = session()->token();
    $this->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/v1/admin/auth/login', [
        'email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!',
    ])->assertOk();
    $this->withoutHeader('X-CSRF-TOKEN')->postJson('/api/v1/admin/auth/logout')->assertStatus(419);
});

it('throttles login per normalized email and IP and logs lockout', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'ADA@example.com', 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
        ->assertStatus(429)->assertHeader('Retry-After');
    expect(AuditLog::where('action', 'auth.login_locked_out')->count())->toBe(1);
});

it('expires absolute sessions and rejects inactive or revoked staff', function (string $reason) {
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
    if ($reason === 'expired') {
        $this->travel(12)->hours();
    } elseif ($reason === 'disabled') {
        $this->staff->update(['is_active' => false]);
    } else {
        $this->staff->forceFill(['session_version' => 2])->save();
    }
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
})->with(['expired', 'disabled', 'revoked']);

it('rejects admin authentication without a first party session', function () {
    $this->withoutHeader('Origin')->postJson('/api/v1/admin/auth/login', [
        'email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!',
    ])->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});
