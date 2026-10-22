<?php

use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Http\Resources\StaffResource;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('maps every permission to the exact role matrix', function () {
    $allowed = [
        StaffRole::ADMIN->value => Permission::cases(),
        StaffRole::SALES_ORDERS->value => [
            Permission::VIEW_DASHBOARD, Permission::VIEW_ORDERS, Permission::UPDATE_ORDER_STATUS,
            Permission::ADD_ORDER_NOTES, Permission::CREATE_MANUAL_ORDERS, Permission::VIEW_REFUNDS,
            Permission::ADD_REFUND_NOTES, Permission::VIEW_CUSTOMERS, Permission::ADD_CUSTOMER_NOTES,
            Permission::VIEW_CATALOGUE, Permission::CHANGE_OWN_PASSWORD,
        ],
        StaffRole::CUSTOMER_SERVICE->value => [
            Permission::VIEW_DASHBOARD, Permission::VIEW_ORDERS, Permission::ADD_ORDER_NOTES,
            Permission::VIEW_REFUNDS, Permission::ADD_REFUND_NOTES, Permission::VIEW_CUSTOMERS,
            Permission::ADD_CUSTOMER_NOTES, Permission::VIEW_CATALOGUE, Permission::VIEW_COMPLAINTS,
            Permission::HANDLE_COMPLAINTS, Permission::CHANGE_OWN_PASSWORD,
        ],
    ];

    foreach (StaffRole::cases() as $role) {
        foreach (Permission::cases() as $permission) {
            expect($role->allows($permission))->toBe(in_array($permission, $allowed[$role->value], true), $role->value.' '.$permission->value);
        }
    }
});

it('returns the permission values on staff resources', function () {
    $staff = new StaffUser(['name' => 'Customer Service', 'email' => 'cs@example.com', 'role' => StaffRole::CUSTOMER_SERVICE]);

    expect((new StaffResource($staff))->resolve()['permissions'])
        ->toBe(array_map(static fn (Permission $permission): string => $permission->value, StaffRole::CUSTOMER_SERVICE->permissions()));
});

it('enforces role constraints and preserves existing staff rows and related foreign keys', function () {
    $admin = StaffUser::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => StaffRole::ADMIN]);
    $sales = StaffUser::query()->create(['name' => 'Sales', 'email' => 'sales@example.com', 'password' => 'password', 'role' => StaffRole::SALES_ORDERS]);

    $this->assertDatabaseHas('staff_users', ['id' => $admin->id, 'role' => 'ADMIN']);
    $this->assertDatabaseHas('staff_users', ['id' => $sales->id, 'role' => 'SALES_ORDERS']);
    StaffUser::query()->create(['name' => 'Service', 'email' => 'service@example.com', 'password' => 'password', 'role' => StaffRole::CUSTOMER_SERVICE]);

    expect(fn () => DB::table('staff_users')->insert([
        'name' => 'Invalid', 'email' => 'invalid@example.com', 'password' => 'x', 'role' => 'INVALID',
    ]))->toThrow(QueryException::class);

    $foreignKeys = DB::select('PRAGMA foreign_key_list(order_content_revisions)');
    expect(collect($foreignKeys)->pluck('table'))->toContain('staff_users');
    expect(DB::select('PRAGMA foreign_key_check'))->toBeEmpty();
});

it('rebuilds the role check without losing rows, staff foreign keys, or explicit indexes', function () {
    $admin = StaffUser::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => StaffRole::ADMIN]);
    $customer = Customer::query()->create([
        'public_id' => '01J9ABCDEF0000000000000000', 'full_name' => 'Ama', 'phone_e164' => '+233241234567', 'phone_display' => '0241234567',
    ]);
    DB::table('customer_notes')->insert(['customer_id' => $customer->id, 'staff_id' => $admin->id, 'body' => 'Existing note', 'created_at' => now()]);
    DB::statement('CREATE INDEX staff_users_name_index ON staff_users (name)');

    $migration = require base_path('database/migrations/2026_10_19_090000_allow_customer_service_staff_role.php');
    $migration->down();
    $migration->up();

    $this->assertDatabaseHas('staff_users', ['id' => $admin->id, 'role' => 'ADMIN']);
    $this->assertDatabaseHas('customer_notes', ['staff_id' => $admin->id, 'body' => 'Existing note']);
    expect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'staff_users_name_index'"))->not->toBeEmpty();
    expect(DB::select('PRAGMA foreign_key_check'))->toBeEmpty();
    StaffUser::query()->create(['name' => 'Service', 'email' => 'service@example.com', 'password' => 'password', 'role' => StaffRole::CUSTOMER_SERVICE]);
});

it('authorizes representative routes by role and denies inactive staff and order deletion for everyone', function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        $staff = StaffUser::query()->create(['name' => $role->value, 'email' => strtolower($role->value).'@example.com', 'password' => 'Long-Staff-Password9!', 'role' => $role]);
        $this->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => 'Long-Staff-Password9!'])->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/admin/orders')->assertOk();
        $manualOrder = $this->postJson('/api/v1/admin/orders/manual', []);
        if ($role === StaffRole::CUSTOMER_SERVICE) {
            $manualOrder->assertForbidden();
        } else {
            $manualOrder->assertUnprocessable();
        }
        $this->assertFalse(Gate::forUser($staff)->allows('delete-orders'));
        $staff->update(['is_active' => false]);
        expect($this->getJson('/api/v1/admin/orders')->getStatusCode())->toBeIn([401, 403]);
        $this->postJson('/api/v1/admin/auth/logout');
        Auth::forgetGuards();
    }
});
