<?php

use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

it('filters audit events by actor action target and UTC dates with bounded pagination', function () {
    $this->travelTo(now()->startOfSecond());
    app(AuditLogger::class)->record($this->admin, 'catalogue.test', $this->admin, after: ['status' => 'first']);
    $this->travel(2)->hours();
    $matching = app(AuditLogger::class)->record($this->admin, 'staff.test', $this->admin, after: ['status' => 'matching']);
    $this->travel(2)->hours();
    app(AuditLogger::class)->record(null, 'staff.test');
    $query = http_build_query([
        'actorId' => $this->admin->id, 'action' => 'staff.test',
        'targetType' => $this->admin->getMorphClass(), 'targetId' => $this->admin->id,
        'from' => now()->subHours(3)->toIso8601String(), 'to' => now()->subHour()->toIso8601String(), 'perPage' => 1,
    ]);
    $this->getJson('/api/v1/admin/audit-logs?'.$query)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matching->id)->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.after.status', 'matching')->assertJsonStructure(['data' => [['requestId', 'createdAt', 'actorType']]]);
    $this->getJson('/api/v1/admin/audit-logs?perPage=101')->assertUnprocessable();
    $this->getJson('/api/v1/admin/audit-logs?from=invalid')->assertUnprocessable();
    $this->getJson('/api/v1/admin/audit-logs?from=2026-10-08&to=2026-10-07')->assertUnprocessable();
    $this->getJson('/api/v1/admin/audit-logs?to=2099-01-01')->assertOk();
    $this->getJson('/api/v1/admin/audit-logs?action=staff.test&to='.now()->toDateString())
        ->assertOk()->assertJsonPath('meta.total', 2);
});

it('audit_logs_are_immutable_and_redacted', function () {
    $this->withHeader('X-Request-Id', 'password-audit')->postJson('/api/v1/admin/auth/password', [
        'currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'New-Staff-Password7!', 'newPasswordConfirmation' => 'New-Staff-Password7!',
    ])->assertOk();
    $log = AuditLog::where('action', 'auth.password_changed')->sole();
    expect($log->request_id)->toBe('password-audit')
        ->and($log->toJson())->not->toContain('Long-Staff-Password9!', 'New-Staff-Password7!', 'passwordConfirmation');
    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class);
});
