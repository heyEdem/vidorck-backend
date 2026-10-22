<?php

use App\Modules\Complaints\Actions\AddComplaintNote;
use App\Modules\Complaints\Actions\ClaimComplaint;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('contact-submissions');
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::query()->create(['name' => $role->value, 'email' => strtolower($role->value).'@example.com', 'password' => 'Long-Staff-Password9!', 'role' => $role]);
    }
});

function complaintAdminLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
}

function createAdminComplaint(array $overrides = []): Complaint
{
    test()->postJson('/api/v1/complaints', array_merge(['name' => 'Ama Mensah', 'topic' => 'ORDER_PROBLEM', 'message' => 'Need help.', 'contactMethod' => 'CALL', 'phone' => '0241234567'], $overrides))->assertCreated();

    return Complaint::query()->latest('id')->firstOrFail();
}

it('gates complaint reads and mutations independently by permission', function (): void {
    $complaint = createAdminComplaint();
    foreach (['SALES_ORDERS'] as $role) {
        complaintAdminLogin($role);
        $this->getJson('/api/v1/admin/complaints')->assertForbidden();
        $this->getJson('/api/v1/admin/complaints/'.$complaint->reference)->assertForbidden();
        $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/claim', [])->assertForbidden();
        $this->postJson('/api/v1/admin/auth/logout');
        Auth::forgetGuards();
    }
    complaintAdminLogin('CUSTOMER_SERVICE');
    $this->getJson('/api/v1/admin/complaints')->assertOk();
    $this->getJson('/api/v1/admin/complaints/'.$complaint->reference)->assertOk();
});

it('filters, sorts open complaints first, paginates, and looks up exact references', function (): void {
    $old = createAdminComplaint();
    $old->created_at = now()->subDays(3);
    $old->save();
    $closed = createAdminComplaint();
    $closed->update(['status' => 'RESOLVED']);
    $new = createAdminComplaint();
    $new->update(['assigned_staff_id' => StaffUser::query()->where('role', StaffRole::CUSTOMER_SERVICE->value)->value('id')]);
    complaintAdminLogin('CUSTOMER_SERVICE');
    $this->getJson('/api/v1/admin/complaints?perPage=1&page=1')->assertOk()->assertJsonPath('meta', ['page' => 1, 'perPage' => 1, 'total' => 3])->assertJsonPath('data.0.reference', $old->reference);
    $this->getJson('/api/v1/admin/complaints?status=RESOLVED')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reference', $closed->reference);
    $this->getJson('/api/v1/admin/complaints?status=BOGUS')->assertUnprocessable();
    $this->getJson('/api/v1/admin/complaints?perPage=101')->assertUnprocessable();
    $this->getJson('/api/v1/admin/complaints/'.strtolower($new->reference))->assertNotFound();
    $this->getJson('/api/v1/admin/complaints?mine=1')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/admin/complaints?unassigned=1')->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/complaints?mine=1&unassigned=1')->assertJsonPath('meta.total', 0);
});

it('claims, transfers, adds notes and changes status with append-only events and audit records', function (): void {
    $complaint = createAdminComplaint();
    complaintAdminLogin('CUSTOMER_SERVICE');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/claim', [])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.assignedStaff.name', 'CUSTOMER_SERVICE');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/notes', ['body' => ' Followed up '])->assertOk()->assertJsonPath('data.events.3.body', 'Followed up');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'RESOLVED', 'note' => 'Done'])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/notes', ['body' => 'Customer replied'])->assertOk();
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'IN_PROGRESS', 'note' => 'Reopened'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    $this->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    complaintAdminLogin('ADMIN');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/claim', [])->assertOk()->assertJsonPath('data.assignedStaff.name', 'ADMIN');
    expect(DB::table('complaint_events')->where('complaint_id', $complaint->id)->pluck('type')->all())->toBe(['SUBMITTED', 'CLAIMED', 'STATUS_CHANGED', 'NOTE', 'STATUS_CHANGED', 'NOTE', 'STATUS_CHANGED', 'CLAIMED']);
    $serviceId = StaffUser::query()->where('role', StaffRole::CUSTOMER_SERVICE->value)->value('id');
    expect(DB::table('complaint_events')->where('complaint_id', $complaint->id)->where('type', 'CLAIMED')->orderByDesc('id')->value('body'))->toContain('staff '.$serviceId);
    expect(AuditLog::query()->where('target_id', (string) $complaint->id)->count())->toBe(7);
});

it('touches complaint updated_at when a note is appended without changing prior event history', function (): void {
    $complaint = createAdminComplaint();
    $originalUpdatedAt = $complaint->updated_at;
    $historyBefore = DB::table('complaint_events')->where('complaint_id', $complaint->id)->get()->toArray();
    $actor = StaffUser::query()->where('role', StaffRole::CUSTOMER_SERVICE->value)->firstOrFail();

    $this->travel(2)->minutes();
    app(AddComplaintNote::class)->execute($complaint, $actor, 'Followed up');
    $fresh = $complaint->fresh();

    expect($fresh->updated_at->gt($originalUpdatedAt))->toBeTrue()
        ->and((array) DB::table('complaint_events')->where('complaint_id', $complaint->id)->where('type', 'SUBMITTED')->first())->toBe((array) $historyBefore[0])
        ->and(DB::table('complaint_events')->where('complaint_id', $complaint->id)->where('type', 'NOTE')->value('body'))->toBe('Followed up');
});

it('requires notes for resolution and reopening, allows takeover, and rejects claims on resolved complaints', function (): void {
    $complaint = createAdminComplaint();
    complaintAdminLogin('CUSTOMER_SERVICE');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'RESOLVED'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'IN_PROGRESS'])->assertOk();
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'RESOLVED', 'note' => 'done'])->assertOk();
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/claim', [])->assertUnprocessable();
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'IN_PROGRESS'])->assertUnprocessable();
});

it('supports direct resolution from NEW and assigns the resolving staff member', function (): void {
    $complaint = createAdminComplaint();
    complaintAdminLogin('CUSTOMER_SERVICE');
    $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', ['toStatus' => 'RESOLVED', 'note' => 'Spam'])->assertOk()
        ->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.assignedStaff.name', 'CUSTOMER_SERVICE');
    expect(DB::table('complaint_events')->where('complaint_id', $complaint->id)->orderBy('id')->pluck('type')->all())->toBe(['SUBMITTED', 'STATUS_CHANGED']);
});

it('matches the allowed lifecycle transition matrix and note requirements', function (string $from, string $to, ?string $note, int $expected): void {
    $complaint = createAdminComplaint();
    DB::table('complaints')->where('id', $complaint->id)->update(['status' => $from]);
    complaintAdminLogin('CUSTOMER_SERVICE');
    $response = $this->postJson('/api/v1/admin/complaints/'.$complaint->reference.'/status', array_filter(['toStatus' => $to, 'note' => $note], static fn ($value): bool => $value !== null));
    $response->assertStatus($expected);
})->with([
    'new to progress' => ['NEW', 'IN_PROGRESS', null, 200],
    'new to resolved requires note' => ['NEW', 'RESOLVED', null, 422],
    'new to resolved' => ['NEW', 'RESOLVED', 'Resolved on call', 200],
    'progress to resolved requires note' => ['IN_PROGRESS', 'RESOLVED', null, 422],
    'progress to resolved' => ['IN_PROGRESS', 'RESOLVED', 'Resolved', 200],
    'resolved to progress requires note' => ['RESOLVED', 'IN_PROGRESS', null, 422],
    'resolved to progress' => ['RESOLVED', 'IN_PROGRESS', 'Customer returned', 200],
    'progress to progress' => ['IN_PROGRESS', 'IN_PROGRESS', null, 422],
    'resolved to resolved' => ['RESOLVED', 'RESOLVED', 'No change', 422],
    'target new rejected' => ['NEW', 'NEW', null, 422],
]);

it('rechecks a stale complaint projection before refusing a claim on a resolved complaint', function (): void {
    $stale = createAdminComplaint();
    DB::table('complaints')->where('id', $stale->id)->update(['status' => 'RESOLVED']);
    $actor = StaffUser::query()->where('role', StaffRole::CUSTOMER_SERVICE->value)->firstOrFail();
    expect(fn () => app(ClaimComplaint::class)->execute($stale, $actor))->toThrow(ValidationException::class);
    expect(DB::table('complaints')->where('id', $stale->id)->value('status'))->toBe('RESOLVED')
        ->and(DB::table('complaint_events')->where('complaint_id', $stale->id)->count())->toBe(1);
});

it('rolls back the claim projection and first event if its second event cannot be appended', function (): void {
    $complaint = createAdminComplaint();
    $actor = StaffUser::query()->where('role', StaffRole::CUSTOMER_SERVICE->value)->firstOrFail();
    DB::unprepared("CREATE TRIGGER reject_complaint_status_event BEFORE INSERT ON complaint_events WHEN NEW.type = 'STATUS_CHANGED' BEGIN SELECT RAISE(ABORT, 'append failed'); END");
    expect(fn () => app(ClaimComplaint::class)->execute($complaint, $actor))->toThrow(QueryException::class);
    DB::unprepared('DROP TRIGGER reject_complaint_status_event');
    $complaint->refresh();
    expect($complaint->status->value)->toBe('NEW')->and($complaint->assigned_staff_id)->toBeNull();
    expect(DB::table('complaint_events')->where('complaint_id', $complaint->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('target_id', (string) $complaint->id)->count())->toBe(0);
});

it('shows linked complaint summaries only to staff with view-complaints and includes dashboard counts', function (): void {
    $complaint = createAdminComplaint();
    DB::table('complaints')->where('id', $complaint->id)->update(['status' => 'NEW', 'created_at' => now()->subHours(25)]);
    complaintAdminLogin('CUSTOMER_SERVICE');
    $dashboard = $this->getJson('/api/v1/admin/dashboard')->assertOk();
    $dashboard->assertJsonPath('data.newComplaints', 1)->assertJsonPath('data.complaintsWaiting', 1);
    $customer = Customer::query()->create(['full_name' => 'Ama Mensah', 'phone_e164' => '+233241234567', 'phone_display' => '0241234567']);
    $complaint->update(['customer_id' => $customer->id]);
    $this->getJson('/api/v1/admin/customers/'.$customer->public_id)->assertJsonPath('data.complaints.0.reference', $complaint->reference);
    $orderId = DB::table('orders')->insertGetId(['order_number' => 'VH-2026-0001', 'tracking_token' => str_repeat('x', 32), 'source' => 'ONLINE', 'customer_id' => $customer->id, 'status' => 'PAID', 'subtotal_minor' => 100, 'total_paid_minor' => 100, 'currency' => 'GHS', 'payment_method' => 'MOMO', 'customer_snapshot_json' => '{}', 'delivery_snapshot_json' => '{}', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $complaint->update(['order_id' => $orderId]);
    $this->getJson('/api/v1/admin/orders/VH-2026-0001')->assertJsonPath('data.complaints.0.reference', $complaint->reference)
        ->assertJsonMissingPath('data.complaints.0.orderNumberEntered');
    $this->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    complaintAdminLogin('SALES_ORDERS');
    $this->getJson('/api/v1/admin/customers/'.$customer->public_id)->assertJsonMissingPath('data.complaints');
});
