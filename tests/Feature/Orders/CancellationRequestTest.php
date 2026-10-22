<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\CancellationRequested;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Models\RefundNote;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function cancelOrder(string $phone = '0241234567'): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'cr-'.Str::random(15);
    $attempt = CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233'.substr($phone, 1), 'phoneDisplay' => $phone, 'email' => null],
        'delivery_snapshot_json' => ['area' => 'Osu'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Oil', 'productCode' => 'OIL', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);

    return app(CreateOrderFromAttempt::class)->execute($attempt);
}

function cancelStaff(): StaffUser
{
    return StaffUser::create(['name' => 'A', 'email' => Str::random(8).'@example.com', 'password' => 'secret-pass-123', 'role' => StaffRole::ADMIN, 'is_active' => true]);
}

function cancelPost(Order $order, array $over = []): TestResponse
{
    return test()->postJson('/api/v1/orders/cancellation-requests', $over + [
        'orderNumber' => $order->order_number, 'phone' => '+233241234567', 'reason' => 'Ordered by mistake',
    ]);
}

it('creates a WEB request without touching order status and fires the event', function () {
    Event::fake([CancellationRequested::class]);
    $order = cancelOrder();
    cancelPost($order, ['phone' => '0241234567'])->assertCreated()->assertJsonPath('data.status', 'REQUESTED');
    $r = RefundRequest::query()->sole();
    expect($r->order_id)->toBe($order->id)->and($r->channel->value)->toBe('WEB')->and($r->requester_phone_e164)->toBe('+233241234567')
        ->and($order->fresh()->status)->toBe(OrderStatus::PAID)
        ->and(AuditLog::query()->where('action', 'refund.requested')->count())->toBe(1);
    Event::assertDispatched(CancellationRequested::class);
});

it('cancellation_blocked_after_dispatch', function () {
    $order = cancelOrder();
    $sm = app(OrderStateMachine::class);
    $staff = cancelStaff();
    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED] as $to) {
        cancelPost($order)->assertCreated();
        RefundRequest::query()->update(['status' => 'REJECTED']);
        $order = $sm->transition($order->fresh(), $to, $staff);
    }
    Event::fake([CancellationRequested::class]);
    $before = RefundRequest::query()->count();
    cancelPost($order)->assertStatus(409)->assertJsonPath('code', 'CANCELLATION_NOT_ALLOWED');
    expect(RefundRequest::query()->count())->toBe($before);
    Event::assertNotDispatched(CancellationRequested::class);
});

it('rejects a duplicate open request but allows a new one after rejection', function () {
    $order = cancelOrder();
    cancelPost($order)->assertCreated();
    cancelPost($order)->assertStatus(409)->assertJsonPath('code', 'CANCELLATION_ALREADY_REQUESTED');
    expect(RefundRequest::query()->count())->toBe(1);
    RefundRequest::query()->update(['status' => 'REJECTED']);
    cancelPost($order)->assertCreated();
});

it('enforces one open request per order in the database', function () {
    $order = cancelOrder();
    $row = ['order_id' => $order->id, 'channel' => 'WEB', 'reason' => 'x', 'status' => 'PROCESSING', 'created_at' => now(), 'updated_at' => now()];
    DB::table('refund_requests')->insert($row);
    expect(fn () => DB::table('refund_requests')->insert($row))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => DB::table('refund_requests')->insert([...$row, 'status' => 'BOGUS']))->toThrow(QueryException::class);
});

it('returns an identical 404 for unknown order, wrong phone and malformed phone', function () {
    $order = cancelOrder();
    $a = cancelPost($order, ['orderNumber' => 'VHI-2026-999999'])->assertNotFound();
    $b = cancelPost($order, ['phone' => '0200000000'])->assertNotFound();
    $c = cancelPost($order, ['phone' => 'nonsense'])->assertNotFound();
    expect($b->getContent())->toBe($a->getContent())->and($c->getContent())->toBe($a->getContent())
        ->and(RefundRequest::query()->count())->toBe(0);
});

it('does not reveal dispatch state to a caller with the wrong phone', function () {
    $order = cancelOrder();
    $sm = app(OrderStateMachine::class);
    $staff = cancelStaff();
    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED] as $to) {
        $order = $sm->transition($order->fresh(), $to, $staff);
    }
    cancelPost($order, ['phone' => '0200000000'])->assertNotFound();
});

it('validates the reason', function () {
    cancelPost(cancelOrder(), ['reason' => ''])->assertUnprocessable();
});

it('rate limits to 5 per minute per IP', function () {
    $order = cancelOrder();
    for ($i = 0; $i < 5; $i++) {
        cancelPost($order, ['phone' => '0200000000'])->assertNotFound();
    }
    cancelPost($order, ['phone' => '0200000000'])->assertStatus(429);
});

it('keeps refund transactions and notes append-only in the model and database', function () {
    $order = cancelOrder();
    $staff = cancelStaff();
    $customer = Customer::query()->sole();
    $request = RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'STAFF', 'reason' => 'r', 'status' => 'REQUESTED']);
    $rows = [
        RefundTransaction::query()->create(['refund_request_id' => $request->id, 'method' => 'MANUAL', 'amount_minor' => 1000, 'status' => 'PENDING', 'created_at' => now()]),
        RefundNote::query()->create(['refund_request_id' => $request->id, 'staff_id' => $staff->id, 'body' => 'n', 'created_at' => now()]),
        OrderNote::query()->create(['order_id' => $order->id, 'staff_id' => $staff->id, 'body' => 'n', 'created_at' => now()]),
        CustomerNote::query()->create(['customer_id' => $customer->id, 'staff_id' => $staff->id, 'body' => 'n', 'created_at' => now()]),
    ];
    foreach ($rows as $row) {
        $table = $row->getTable();
        expect(fn () => $row->update(['created_at' => now()->addDay()]))->toThrow(LogicException::class)
            ->and(fn () => $row->delete())->toThrow(LogicException::class)
            ->and(fn () => DB::table($table)->where('id', $row->id)->update(['created_at' => now()]))->toThrow(QueryException::class)
            ->and(fn () => DB::table($table)->where('id', $row->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table($table)->truncate())->toThrow(QueryException::class)
            ->and(DB::table($table)->count())->toBe(1);
    }
});

it('lets refund request status change but never origin fields or deletion', function () {
    $order = cancelOrder();
    $request = RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'WEB', 'reason' => 'r', 'status' => 'REQUESTED']);
    $request->update(['status' => 'APPROVED', 'decision_reason' => 'ok']);
    expect($request->fresh()->status->isInFlight())->toBeTrue()
        ->and(fn () => DB::table('refund_requests')->where('id', $request->id)->update(['reason' => 'edited']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('refund_requests')->where('id', $request->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => $request->delete())->toThrow(LogicException::class);
});
