<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function smOrder(): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'sm-'.Str::random(15);
    $attempt = CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => null],
        'delivery_snapshot_json' => ['area' => 'Osu'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Oil', 'productCode' => 'OIL', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);

    return app(CreateOrderFromAttempt::class)->execute($attempt);
}

function smStaff(StaffRole $role): StaffUser
{
    return StaffUser::create(['name' => $role->value, 'email' => Str::random(8).'@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => $role]);
}

function dbStatus(Order $order): string
{
    return (string) DB::table('orders')->where('id', $order->id)->value('status');
}

it('walks the forward path and records events and audit rows', function () {
    $order = smOrder();
    $staff = smStaff(StaffRole::SALES_ORDERS);
    $machine = app(OrderStateMachine::class);

    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED, OrderStatus::DELIVERED] as $to) {
        expect($machine->transition($order, $to, $staff)->status)->toBe($to);
    }

    expect(dbStatus($order))->toBe('DELIVERED')
        ->and($order->statusEvents()->orderBy('id')->pluck('to_status')->map->value->all())
        ->toBe(['PAID', 'PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED', 'DELIVERED'])
        ->and($order->statusEvents()->latest('id')->first()->actor_id)->toBe($staff->id)
        ->and(AuditLog::query()->where('action', 'order.status.changed')->count())->toBe(4);
    $audit = AuditLog::query()->where('action', 'order.status.changed')->orderBy('id')->first();
    expect($audit->before)->toBe(['status' => 'PAID'])->and($audit->actor_id)->toBe($staff->id);
});

it('rejects invalid transitions with 409 and leaves status unchanged', function () {
    $order = smOrder();
    $staff = smStaff(StaffRole::ADMIN);
    $machine = app(OrderStateMachine::class);
    $before = $order->statusEvents()->count();

    foreach ([OrderStatus::DELIVERED, OrderStatus::PAID, OrderStatus::CANCELLED, OrderStatus::REFUNDED] as $to) {
        try {
            $machine->transition($order, $to, $staff);
            $this->fail('Expected rejection.');
        } catch (ApiException $e) {
            expect($e->getStatusCode())->toBe(409)->and($e->errorCode)->toBe('INVALID_ORDER_TRANSITION')
                ->and($e->errors['allowedStatuses'])->toBe(['PROCESSING']);
        }
    }

    expect(dbStatus($order))->toBe('PAID')->and($order->statusEvents()->count())->toBe($before)
        ->and(AuditLog::query()->where('action', 'like', 'order.status.%')->count())->toBe(0);
});

it('lets only an Admin correct one step back and requires a reason', function () {
    $order = smOrder();
    $admin = smStaff(StaffRole::ADMIN);
    $sales = smStaff(StaffRole::SALES_ORDERS);
    $machine = app(OrderStateMachine::class);
    $machine->transition($order, OrderStatus::PROCESSING, $sales);
    $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, $sales);

    expect(fn () => $machine->transition($order, OrderStatus::PROCESSING, $sales, 'oops'))->toThrow(AuthorizationException::class);
    expect(fn () => $machine->transition($order, OrderStatus::PROCESSING, $admin))->toThrow(ApiException::class);
    expect(fn () => $machine->transition($order, OrderStatus::PROCESSING, $admin, '   '))->toThrow(ApiException::class);
    expect(fn () => $machine->transition($order, OrderStatus::PAID, $admin, 'two steps'))->toThrow(ApiException::class);
    expect(dbStatus($order))->toBe('READY_FOR_DISPATCH');

    $machine->transition($order, OrderStatus::PROCESSING, $admin, 'Packed wrong item');

    expect(dbStatus($order))->toBe('PROCESSING')
        ->and($order->statusEvents()->latest('id')->first()->reason)->toBe('Packed wrong item')
        ->and(AuditLog::query()->where('action', 'order.status.corrected')->count())->toBe(1);
});

it('reaches CANCELLED and REFUNDED only through the refund entry point and only before dispatch', function () {
    $machine = app(OrderStateMachine::class);
    $order = smOrder();
    $machine->transitionForRefund($order, OrderStatus::REFUNDED);
    expect(dbStatus($order))->toBe('REFUNDED')->and($order->statusEvents()->latest('id')->first()->actor_type->value)->toBe('SYSTEM');
    expect(fn () => $machine->transition($order, OrderStatus::PROCESSING, smStaff(StaffRole::ADMIN), 'x'))->toThrow(ApiException::class);

    $dispatched = smOrder();
    $staff = smStaff(StaffRole::ADMIN);
    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED] as $to) {
        $machine->transition($dispatched, $to, $staff);
    }
    expect(fn () => $machine->transitionForRefund($dispatched, OrderStatus::CANCELLED, $staff))->toThrow(ApiException::class)
        ->and(dbStatus($dispatched))->toBe('DISPATCHED');
});

it('rejects a stale instance with 409 without a second write', function () {
    $first = smOrder();
    $stale = Order::query()->findOrFail($first->id);
    $staff = smStaff(StaffRole::SALES_ORDERS);
    app(OrderStateMachine::class)->transition($first, OrderStatus::PROCESSING, $staff);
    $events = $first->statusEvents()->count();
    $audits = AuditLog::query()->where('action', 'like', 'order.status.%')->count();

    try {
        app(OrderStateMachine::class)->transition($stale, OrderStatus::PROCESSING, $staff);
        $this->fail('Expected rejection.');
    } catch (ApiException $e) {
        expect($e->getStatusCode())->toBe(409)->and($e->errors['allowedStatuses'])->toBe(['READY_FOR_DISPATCH']);
    }

    expect(dbStatus($first))->toBe('PROCESSING')->and($first->statusEvents()->count())->toBe($events)
        ->and($events)->toBe(2)
        ->and(AuditLog::query()->where('action', 'like', 'order.status.%')->count())->toBe($audits);
});

it('dispatches OrderStatusChanged only after the outer commit', function () {
    $order = smOrder();
    $staff = smStaff(StaffRole::SALES_ORDERS);
    $seen = [];
    Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $e) use (&$seen): void {
        $seen[] = [$e->from, $e->to];
    });

    DB::beginTransaction();
    app(OrderStateMachine::class)->transition($order, OrderStatus::PROCESSING, $staff);
    expect($seen)->toBe([]);
    DB::commit();
    expect($seen)->toBe([[OrderStatus::PAID, OrderStatus::PROCESSING]]);

    DB::beginTransaction();
    app(OrderStateMachine::class)->transition($order, OrderStatus::READY_FOR_DISPATCH, $staff);
    DB::rollBack();
    expect($seen)->toHaveCount(1)->and(dbStatus($order))->toBe('PROCESSING')
        ->and($order->statusEvents()->count())->toBe(2);
});

it('blocks status writes through the model', function () {
    $order = smOrder();
    expect(fn () => $order->forceFill(['status' => OrderStatus::DELIVERED])->save())->toThrow(LogicException::class);
    expect(fn () => Order::query()->create([
        'order_number' => 'X-1', 'tracking_token' => Str::random(32), 'source' => 'ONLINE',
        'customer_id' => Customer::query()->value('id'), 'status' => OrderStatus::DELIVERED, 'subtotal_minor' => 1,
        'total_paid_minor' => 1, 'currency' => 'GHS', 'payment_method' => 'CARD',
        'customer_snapshot_json' => [], 'delivery_snapshot_json' => [], 'paid_at' => now(),
    ]))->toThrow(LogicException::class);
    expect(dbStatus($order))->toBe('PAID');
});
