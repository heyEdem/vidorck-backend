<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

const ADM_PASSWORD = 'Long-Staff-Password9!';

function admOrder(string $phone = '0241234567', string $name = 'Ama Mensah'): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'ao-'.Str::random(15);
    $attempt = CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => $name, 'phoneE164' => '+233'.substr($phone, 1), 'phoneDisplay' => $phone, 'email' => 'ama@example.com'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Osu', 'address' => '12 Old Lane', 'landmark' => 'Blue gate', 'ghanaPostGps' => 'GA-123-4567'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Shea Oil', 'size' => '250ml', 'productCode' => 'OIL', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);

    return app(CreateOrderFromAttempt::class)->execute($attempt);
}

function admLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => ADM_PASSWORD])->assertOk();
}

function admTransition(Order $order, string $to, array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/admin/orders/{$order->order_number}/transitions", ['toStatus' => $to] + $extra);
}

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::create(['name' => ucfirst(strtolower($role->value)), 'email' => strtolower($role->value).'@example.com', 'password' => ADM_PASSWORD, 'role' => $role]);
    }
    DeliveryArea::query()->create(['name' => 'Osu', 'aliases_json' => [], 'is_active' => true, 'display_order' => 1]);
    DeliveryArea::query()->create(['name' => 'Madina', 'aliases_json' => [], 'is_active' => true, 'display_order' => 2]);
});

it('requires authentication on every order route', function () {
    $o = admOrder();
    test()->getJson('/api/v1/admin/orders')->assertUnauthorized();
    test()->getJson("/api/v1/admin/orders/{$o->order_number}")->assertUnauthorized();
    admTransition($o, 'PROCESSING')->assertUnauthorized();
    test()->postJson("/api/v1/admin/orders/{$o->order_number}/notes", ['body' => 'x'])->assertUnauthorized();
    test()->patchJson("/api/v1/admin/orders/{$o->order_number}/delivery", ['address' => 'New address 1', 'reason' => 'Customer called'])->assertUnauthorized();
});

it('invalid_transition_is_rejected', function () {
    $o = admOrder();
    foreach (['SALES_ORDERS', 'ADMIN'] as $role) {
        admLogin($role);
        admTransition($o, 'DELIVERED')->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION')
            ->assertJsonPath('errors.allowedStatuses', ['PROCESSING']);
        admTransition($o, 'REFUNDED')->assertStatus(409);
    }
    expect($o->fresh()->status)->toBe(OrderStatus::PAID)
        ->and(OrderStatusEvent::query()->where('order_id', $o->id)->count())->toBe(1);
    admTransition($o, 'BOGUS')->assertUnprocessable();
});

it('lets both roles advance one step and records history and audit', function () {
    $o = admOrder();
    admLogin('SALES_ORDERS');
    admTransition($o, 'PROCESSING')->assertOk()
        ->assertJsonPath('data.status', 'PROCESSING')
        ->assertJsonPath('data.allowedStatuses', ['READY_FOR_DISPATCH'])
        ->assertJsonPath('data.statusEvents.1.toStatus', 'PROCESSING');
    admLogin('ADMIN');
    admTransition($o, 'READY_FOR_DISPATCH')->assertOk()->assertJsonPath('data.allowedStatuses', ['DISPATCHED', 'PROCESSING']);
    expect($o->fresh()->status)->toBe(OrderStatus::READY_FOR_DISPATCH)
        ->and(AuditLog::query()->where('action', 'order.status.changed')->count())->toBe(2);
});

it('restricts corrections to Admin with a reason', function () {
    $o = admOrder();
    admLogin('ADMIN');
    admTransition($o, 'PROCESSING')->assertOk();
    admLogin('SALES_ORDERS');
    admTransition($o, 'PAID', ['reason' => 'Mistake'])->assertForbidden();
    expect($o->fresh()->status)->toBe(OrderStatus::PROCESSING);
    admLogin('ADMIN');
    admTransition($o, 'PAID')->assertUnprocessable();
    admTransition($o, 'PAID', ['reason' => 'Marked by mistake'])->assertOk()->assertJsonPath('data.status', 'PAID');
    expect(AuditLog::query()->where('action', 'order.status.corrected')->count())->toBe(1);
});

it('appends notes for both roles and shows them', function () {
    $o = admOrder();
    foreach (['SALES_ORDERS', 'ADMIN'] as $role) {
        admLogin($role);
        test()->postJson("/api/v1/admin/orders/{$o->order_number}/notes", ['body' => " Called by {$role} "])->assertCreated()
            ->assertJsonPath('data.body', "Called by {$role}");
    }
    test()->postJson("/api/v1/admin/orders/{$o->order_number}/notes", ['body' => ''])->assertUnprocessable();
    test()->getJson("/api/v1/admin/orders/{$o->order_number}")->assertOk()
        ->assertJsonCount(2, 'data.notes')->assertJsonPath('data.notes.0.staffName', 'Sales_orders');
    expect(AuditLog::query()->where('action', 'order.note.added')->count())->toBe(2);
    $note = OrderNote::query()->first();
    expect(fn () => $note->update(['body' => 'edited']))->toThrow(LogicException::class);
});

it('shows an order with staff-only fields and no tracking token', function () {
    $o = admOrder();
    admLogin('SALES_ORDERS');
    $r = test()->getJson('/api/v1/admin/orders/'.strtolower($o->order_number))->assertOk()
        ->assertJsonPath('data.orderNumber', $o->order_number)
        ->assertJsonPath('data.paymentReference', $o->payment_reference)
        ->assertJsonPath('data.delivery.address', '12 Old Lane')
        ->assertJsonPath('data.items.0.name', 'Shea Oil')
        ->assertJsonPath('data.customer.phone', '0241234567');
    expect($r->getContent())->not->toContain($o->tracking_token);
    test()->getJson('/api/v1/admin/orders/VHI-2026-999999')->assertNotFound();
});

it('lets only Admin edit delivery, with a reason, audited, without touching status', function () {
    $o = admOrder();
    $path = "/api/v1/admin/orders/{$o->order_number}/delivery";
    admLogin('SALES_ORDERS');
    test()->patchJson($path, ['address' => 'New address 1', 'reason' => 'Customer called'])->assertForbidden();
    expect($o->fresh()->delivery_snapshot_json['address'])->toBe('12 Old Lane');

    admLogin('ADMIN');
    test()->patchJson($path, ['address' => 'New address 1'])->assertUnprocessable();
    test()->patchJson($path, ['reason' => 'Customer called'])->assertUnprocessable();
    test()->patchJson($path, ['area' => str_repeat('x', 121), 'reason' => 'Customer called'])->assertUnprocessable();
    test()->patchJson($path, ['address' => 'New address 1', 'area' => 'Madina', 'ghanaPostGps' => 'gb-111-2222', 'reason' => 'Customer called'])->assertOk()
        ->assertJsonPath('data.delivery.address', 'New address 1')
        ->assertJsonPath('data.delivery.area', 'Madina')
        ->assertJsonPath('data.delivery.landmark', 'Blue gate')
        ->assertJsonPath('data.status', 'PAID');

    $fresh = $o->fresh();
    expect($fresh->status)->toBe(OrderStatus::PAID)
        ->and($fresh->delivery_snapshot_json['ghanaPostGps'])->toBe('GB-111-2222')
        ->and(OrderStatusEvent::query()->where('order_id', $o->id)->count())->toBe(1);
    $audit = AuditLog::query()->where('action', 'order.delivery.updated')->sole();
    expect($audit->before)->toMatchArray(['address' => '12 Old Lane', 'area' => 'Osu', 'ghanaPostGps' => 'GA-123-4567'])
        ->and($audit->after)->toMatchArray(['address' => 'New address 1', 'area' => 'Madina', 'reason' => 'Customer called']);
});

it('canonicalises a matching delivery area and accepts free-text areas as typed', function () {
    $o = admOrder();
    $path = "/api/v1/admin/orders/{$o->order_number}/delivery";
    admLogin('ADMIN');
    test()->patchJson($path, ['area' => '  mADINA ', 'reason' => 'Customer called'])->assertOk()->assertJsonPath('data.delivery.area', 'Madina');
    test()->patchJson($path, ['area' => ' Aburi Hills ', 'reason' => 'Customer called'])->assertOk()->assertJsonPath('data.delivery.area', 'Aburi Hills');
    expect($o->fresh()->delivery_snapshot_json['area'])->toBe('Aburi Hills');
});

it('filters, searches and paginates the order list for both roles', function () {
    $a = admOrder('0241234567', 'Ama Mensah');
    $b = admOrder('0201111111', 'Kwame Boateng');
    Order::query()->whereKey($b->id)->update(['source' => 'MANUAL', 'created_at' => '2026-01-05 10:00:00']);
    admLogin('SALES_ORDERS');
    admTransition($a, 'PROCESSING')->assertOk();

    $numbers = fn (string $q): array => collect(test()->getJson('/api/v1/admin/orders'.$q)->assertOk()->json('data'))->pluck('orderNumber')->all();
    expect($numbers(''))->toHaveCount(2)
        ->and($numbers('?status=PROCESSING'))->toBe([$a->order_number])
        ->and($numbers('?source=MANUAL'))->toBe([$b->order_number])
        ->and($numbers('?to=2026-01-05'))->toBe([$b->order_number])
        ->and($numbers('?from=2026-01-06&to=2026-01-07'))->toBe([])
        ->and($numbers('?search=boateng'))->toBe([$b->order_number])
        ->and($numbers('?search='.urlencode('+233201111111')))->toBe([$b->order_number])
        ->and($numbers('?search=0201111111'))->toBe([$b->order_number])
        ->and($numbers('?search='.urlencode(substr($a->order_number, -6))))->toBe([$a->order_number])
        ->and($numbers('?search=%25'))->toBe([]);
    test()->getJson('/api/v1/admin/orders?perPage=1&page=2')->assertOk()->assertJsonPath('meta', ['page' => 2, 'perPage' => 1, 'total' => 2]);
    test()->getJson('/api/v1/admin/orders?status=BOGUS')->assertUnprocessable();
    test()->getJson('/api/v1/admin/orders?perPage=500')->assertUnprocessable();
});
