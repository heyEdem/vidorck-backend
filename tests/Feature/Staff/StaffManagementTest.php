<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

it('creates staff with a forced temporary password and paginates safely', function () {
    $response = $this->postJson('/api/v1/admin/staff', [
        'name' => 'New Sales', 'email' => 'NEW@example.com', 'role' => 'SALES_ORDERS',
    ])->assertCreated()->assertJsonPath('data.mustChangePassword', true)
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data.password');
    $created = StaffUser::where('email', 'new@example.com')->sole();
    expect(Hash::check($response->json('temporaryPassword'), $created->password))->toBeTrue();
    $this->getJson('/api/v1/admin/staff?perPage=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3)->assertJsonMissingPath('temporaryPassword');
    expect(AuditLog::where('action', 'staff.created')->count())->toBe(1);
});

it('sales_cannot_manage_staff', function () {
    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
    $this->getJson('/api/v1/admin/staff')->assertForbidden();
    $this->postJson('/api/v1/admin/staff', [])->assertForbidden();
    $this->patchJson('/api/v1/admin/staff/'.$this->admin->id, ['role' => 'SALES_ORDERS'])->assertForbidden();
    $this->postJson('/api/v1/admin/staff/'.$this->admin->id.'/reset-password')->assertForbidden();
    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    expect(StaffUser::count())->toBe(2);
});

it('disabled_staff_is_logged_out_immediately', function () {
    DB::table('sessions')->insert(['id' => 'sales-session', 'user_id' => $this->sales->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->patchJson('/api/v1/admin/staff/'.$this->sales->id, ['isActive' => false])->assertOk()->assertJsonPath('data.isActive', false);
    expect(DB::table('sessions')->where('user_id', $this->sales->id)->exists())->toBeFalse()
        ->and($this->sales->fresh()->session_version)->toBe(2);
    // A request already loaded before row deletion still fails the active/version checks.
    $this->actingAs($this->sales, 'web')->withSession([
        'staff_authenticated_at' => now()->timestamp, 'staff_session_version' => 1,
    ])->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
});

it('revokes all sessions on role change and reset and exposes temporary passwords once', function () {
    DB::table('sessions')->insert(['id' => 'sales-session', 'user_id' => $this->sales->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->patchJson('/api/v1/admin/staff/'.$this->sales->id, ['role' => 'ADMIN'])->assertOk();
    expect(DB::table('sessions')->where('user_id', $this->sales->id)->count())->toBe(0);
    $response = $this->postJson('/api/v1/admin/staff/'.$this->sales->id.'/reset-password')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.mustChangePassword', true);
    $temporary = $response->json('temporaryPassword');
    expect(Hash::check($temporary, $this->sales->fresh()->password))->toBeTrue()
        ->and($this->sales->fresh()->session_version)->toBe(3)
        ->and(AuditLog::all()->toJson())->not->toContain($temporary);
    $this->getJson('/api/v1/admin/staff')->assertJsonMissingPath('temporaryPassword');
    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => $temporary])->assertOk();
    $this->getJson('/api/v1/admin/staff')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
});

it('protects self-disable, self-demotion, and the last active admin', function () {
    $this->patchJson('/api/v1/admin/staff/'.$this->admin->id, ['isActive' => false])->assertStatus(409);
    $this->patchJson('/api/v1/admin/staff/'.$this->admin->id, ['role' => 'SALES_ORDERS'])->assertStatus(409);
    $this->postJson('/api/v1/admin/staff/'.$this->admin->id.'/reset-password')->assertStatus(409);
    expect($this->admin->fresh()->is_active)->toBeTrue()->and($this->admin->fresh()->role)->toBe(StaffRole::ADMIN);
});

it('validates roles, duplicate emails, bounded pagination and update fields', function () {
    $this->postJson('/api/v1/admin/staff', ['name' => 'New', 'email' => 'ADMIN@example.com', 'role' => 'ADMIN'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/staff', ['name' => 'New', 'email' => 'new@example.com', 'role' => 'OWNER'])->assertUnprocessable();
    $this->patchJson('/api/v1/admin/staff/'.$this->sales->id, [])->assertUnprocessable();
    $this->patchJson('/api/v1/admin/staff/'.$this->sales->id, ['role' => 'OWNER'])->assertUnprocessable();
    $this->getJson('/api/v1/admin/staff?perPage=101')->assertUnprocessable();
    $this->patchJson('/api/v1/admin/staff/9999', ['isActive' => false])->assertNotFound();
});

it('enforces the full staff capability matrix and denies inactive accounts', function () {
    $both = ['view-dashboard', 'view-orders', 'update-order-status', 'add-order-notes', 'create-manual-orders',
        'view-catalogue', 'view-refunds', 'add-refund-notes', 'view-customers', 'add-customer-notes', 'change-own-password'];
    $adminOnly = ['manage-catalogue', 'publish-catalogue', 'manage-images', 'edit-order-delivery',
        'decide-refunds', 'process-refunds', 'manage-staff', 'view-audit', 'view-reconciliation'];
    foreach ($both as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability))->toBeTrue($ability)
            ->and(Gate::forUser($this->sales)->allows($ability))->toBeTrue($ability);
    }
    foreach ($adminOnly as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability))->toBeTrue($ability)
            ->and(Gate::forUser($this->sales)->allows($ability))->toBeFalse($ability);
    }
    expect(Gate::forUser($this->admin)->allows('delete-orders'))->toBeFalse();
    $this->admin->update(['is_active' => false]);
    foreach ([...$both, ...$adminOnly] as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability))->toBeFalse($ability);
    }
});
