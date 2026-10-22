<?php

use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\DecideRefund;
use App\Modules\Orders\Actions\HandleRefundWebhook;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundNote;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

const RF_PASSWORD = 'Long-Staff-Password9!';

function rfOrder(bool $manual = false): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'rf-'.Str::random(15);
    $attempt = CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => null],
        'delivery_snapshot_json' => ['area' => 'Osu'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Oil', 'productCode' => 'OIL', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);
    $order = app(CreateOrderFromAttempt::class)->execute($attempt);
    if ($manual) {
        DB::table('orders')->where('id', $order->id)->update(['source' => 'MANUAL']);
    }

    return $order->fresh();
}

function rfRequest(Order $order, string $status = 'REQUESTED'): RefundRequest
{
    return RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'WEB', 'reason' => 'Ordered by mistake', 'status' => $status]);
}

function rfLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => RF_PASSWORD])->assertOk();
}

function rfDecide(RefundRequest $r, array $body): TestResponse
{
    return test()->postJson("/api/v1/admin/refunds/{$r->id}/decision", $body);
}

function rfStatus(Order $order): string
{
    return (string) DB::table('orders')->where('id', $order->id)->value('status');
}

function rfSetStatus(Order $order, string $status): void
{
    $path = ['PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED'];
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN->value)->first();
    foreach ($path as $step) {
        app(OrderStateMachine::class)->transition($order->fresh(), OrderStatus::from($step), $admin);
        if ($step === $status) {
            return;
        }
    }
}

function rfFakeAccepted(): void
{
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund has been queued', 'data' => [
        'id' => 7001, 'status' => 'pending', 'currency' => 'GHS', 'amount' => 1000, 'integration' => 'secret-int',
    ]], 200)]);
}

function rfWebhook(Order $order, string $event = 'refund.processed', array $data = []): PaymentEvent
{
    $body = json_encode(['event' => $event, 'data' => $data + [
        'id' => 9001, 'status' => $event === 'refund.processed' ? 'processed' : 'failed', 'transaction_reference' => $order->payment_reference,
        'refund_reference' => 'rr-1', 'amount' => '1000', 'currency' => 'GHS',
    ]]);
    test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', (string) $body, 'sk_test_refund'),
    ], (string) $body)->assertOk();
    $event = PaymentEvent::query()->orderByDesc('id')->firstOrFail();
    rfRun($event);

    return $event;
}

function rfRun(PaymentEvent $event): void
{
    (new ProcessPaystackEvent($event->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));
}

beforeEach(function () {
    config(['services.paystack.secret_key' => 'sk_test_refund', 'services.paystack.base_url' => 'https://api.paystack.co']);
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::create(['name' => ucfirst(strtolower($role->value)), 'email' => strtolower($role->value).'@example.com', 'password' => RF_PASSWORD, 'role' => $role]);
    }
    Http::preventStrayRequests();
});

it('sales_cannot_decide_refunds', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('SALES_ORDERS');
    rfDecide($r, ['decision' => 'APPROVE'])->assertForbidden();
    test()->postJson("/api/v1/admin/refunds/{$r->id}/manual-completion", ['method' => 'MOMO', 'reference' => 'MOMO-1', 'amountMinor' => 1000])->assertForbidden();
    expect($r->fresh()->status->value)->toBe('REQUESTED');
    test()->getJson('/api/v1/admin/refunds')->assertOk()->assertJsonPath('meta.total', 1);
    test()->getJson("/api/v1/admin/refunds/{$r->id}")->assertOk()->assertJsonPath('data.order.orderNumber', $order->order_number);
    test()->postJson("/api/v1/admin/refunds/{$r->id}/notes", ['body' => 'Called customer'])->assertCreated();
    rfLogin('ADMIN');
    test()->postJson("/api/v1/admin/refunds/{$r->id}/notes", ['body' => 'Second'])->assertCreated();
    expect(RefundNote::query()->count())->toBe(2);
    expect(fn () => RefundNote::query()->first()->update(['body' => 'x']))->toThrow(LogicException::class);
});

it('requires authentication', function () {
    test()->getJson('/api/v1/admin/refunds')->assertUnauthorized();
    test()->postJson('/api/v1/admin/refunds/1/decision', ['decision' => 'REJECT'])->assertUnauthorized();
});

it('refund_marks_refunded_only_on_confirmation', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    rfFakeAccepted();
    $level = null;
    Http::fake(['api.paystack.co/refund' => function (HttpRequest $request) use (&$level) {
        $level = DB::transactionLevel();

        return Http::response(['status' => true, 'data' => ['id' => 7001, 'status' => 'pending', 'currency' => 'GHS', 'amount' => 1000, 'integration' => 'secret-int']], 200);
    }]);

    rfDecide($r, ['decision' => 'APPROVE'])->assertOk()->assertJsonPath('data.status', 'PROCESSING');
    Http::assertSent(fn (HttpRequest $q): bool => $q['transaction'] === $order->payment_reference && $q['amount'] === 1000 && $q['currency'] === 'GHS');
    expect($level)->toBe(0)
        ->and(rfStatus($order))->toBe('PAID')
        ->and(RefundTransaction::query()->sole()->status->value)->toBe('PENDING')
        ->and(RefundTransaction::query()->sole()->response_redacted_json)->not->toHaveKey('integration')
        ->and(AuditLog::query()->whereIn('action', ['refund.approved', 'refund.provider.accepted'])->count())->toBe(2);

    $event = rfWebhook($order);
    expect($r->fresh()->status->value)->toBe('COMPLETED')->and(rfStatus($order))->toBe('REFUNDED')
        ->and(RefundTransaction::query()->where('status', 'PROCESSED')->count())->toBe(1);

    // Replays: identical delivery and a re-run of the processed job change nothing.
    rfWebhook($order);
    rfRun($event);
    expect(PaymentEvent::query()->count())->toBe(1)
        ->and(RefundTransaction::query()->where('status', 'PROCESSED')->count())->toBe(1)
        ->and(DB::table('order_status_events')->where('order_id', $order->id)->where('to_status', 'REFUNDED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'refund.completed')->count())->toBe(1);
});

it('re-running an unprocessed refund event after commit is a no-op', function () {
    $order = rfOrder();
    $r = rfRequest($order, 'PROCESSING');
    $event = rfWebhook($order);
    // Simulates a crash after the handler committed but before markProcessed: the handler runs again.
    app(HandleRefundWebhook::class)->execute($event->fresh());
    expect($r->fresh()->status->value)->toBe('COMPLETED')->and(RefundTransaction::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'refund.completed')->count())->toBe(1);
});

it('marks the request FAILED on refund.failed and leaves the order untouched', function () {
    $order = rfOrder();
    $r = rfRequest($order, 'PROCESSING');
    rfWebhook($order, 'refund.failed');
    expect($r->fresh()->status->value)->toBe('FAILED')->and(rfStatus($order))->toBe('PAID')
        ->and(RefundTransaction::query()->sole()->status->value)->toBe('FAILED');
});

it('flags a provider-confirmed refund whose order can no longer be moved', function () {
    $order = rfOrder();
    $r = rfRequest($order, 'PROCESSING');
    DB::table('orders')->where('id', $order->id)->update(['status' => 'DISPATCHED']);
    rfWebhook($order);
    expect($r->fresh()->status->value)->toBe('COMPLETED')->and(rfStatus($order))->toBe('DISPATCHED')
        ->and(AuditLog::query()->where('action', 'refund.reconciliation_required')->count())->toBe(1)
        ->and(PaymentEvent::query()->sole()->processed_at)->not->toBeNull();
    rfLogin('ADMIN');
    test()->getJson('/api/v1/admin/refunds?needsReconciliation=true')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.needsReconciliation', true);
    test()->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.refundReconciliationCount', 1);
});

it('flags a refund confirmation with no in-flight request and a mismatched amount', function () {
    $order = rfOrder();
    rfWebhook($order);
    expect(AuditLog::query()->where('action', 'refund.reconciliation_required')->count())->toBe(1)->and(rfStatus($order))->toBe('PAID');

    $other = rfOrder();
    $r = rfRequest($other, 'PROCESSING');
    rfWebhook($other, 'refund.processed', ['id' => 9002, 'refund_reference' => 'rr-2', 'amount' => '500']);
    expect($r->fresh()->status->value)->toBe('COMPLETED')->and(rfStatus($other))->toBe('PAID')
        ->and(AuditLog::query()->where('action', 'refund.reconciliation_required')->count())->toBe(2);
});

it('records a FAILED transaction when Paystack definitively rejects the refund', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Refund refused (generic test stub)'], 400)]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(502)->assertJsonPath('code', 'REFUND_REJECTED_BY_PROVIDER');
    expect($r->fresh()->status->value)->toBe('FAILED')->and(rfStatus($order))->toBe('PAID')
        ->and(RefundTransaction::query()->sole()->status->value)->toBe('FAILED');
});

it('keeps an approval in flight when the provider outcome is unknown and never retries', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    $calls = 0;
    Http::fake(['api.paystack.co/refund' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('timeout');
    }]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(503)->assertJsonPath('code', 'REFUND_PROVIDER_UNAVAILABLE');
    expect($r->fresh()->status->value)->toBe('APPROVED')->and(rfStatus($order))->toBe('PAID')->and(RefundTransaction::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'refund.provider.unavailable')->count())->toBe(1);
    expect($calls)->toBe(1);
    admDispatchBlocked($order);
    // A later webhook resolves it.
    rfWebhook($order);
    expect($r->fresh()->status->value)->toBe('COMPLETED')->and(rfStatus($order))->toBe('REFUNDED');

    Http::fake(['api.paystack.co/refund' => Http::response('boom', 500)]);
    $second = rfOrder();
    $r2 = rfRequest($second);
    rfDecide($r2, ['decision' => 'APPROVE'])->assertStatus(503);
    expect($r2->fresh()->status->value)->toBe('APPROVED');
});

function admDispatchBlocked(Order $order): void
{
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN->value)->first();
    $sm = app(OrderStateMachine::class);
    $sm->transition($order->fresh(), OrderStatus::PROCESSING, $admin);
    $sm->transition($order->fresh(), OrderStatus::READY_FOR_DISPATCH, $admin);
    expect(fn () => $sm->transition($order->fresh(), OrderStatus::DISPATCHED, $admin))->toThrow(
        fn (ApiException $e): bool => $e->getStatusCode() === 409,
    );
    expect(rfStatus($order))->toBe('READY_FOR_DISPATCH');
}

it('blocks dispatch only while a refund is APPROVED or PROCESSING', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN->value)->first();
    $sm = app(OrderStateMachine::class);
    $sm->transition($order->fresh(), OrderStatus::PROCESSING, $admin);
    $sm->transition($order->fresh(), OrderStatus::READY_FOR_DISPATCH, $admin);
    foreach (['REQUESTED', 'UNDER_REVIEW'] as $open) {
        DB::table('refund_requests')->where('id', $r->id)->update(['status' => $open]);
        $copy = $sm->transition($order->fresh(), OrderStatus::DISPATCHED, $admin);
        expect($copy->status)->toBe(OrderStatus::DISPATCHED);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'READY_FOR_DISPATCH']);
    }
    rfLogin('ADMIN');
    foreach (['APPROVED', 'PROCESSING'] as $inFlight) {
        DB::table('refund_requests')->where('id', $r->id)->update(['status' => $inFlight]);
        test()->postJson("/api/v1/admin/orders/{$order->order_number}/transitions", ['toStatus' => 'DISPATCHED'])
            ->assertStatus(409)->assertJsonPath('code', 'REFUND_IN_PROGRESS');
        expect(rfStatus($order))->toBe('READY_FOR_DISPATCH');
    }
    DB::table('refund_requests')->where('id', $r->id)->update(['status' => 'REJECTED']);
    test()->postJson("/api/v1/admin/orders/{$order->order_number}/transitions", ['toStatus' => 'DISPATCHED'])->assertOk();
});

it('refuses dispatch that races the provider call after approval commits, and approval after dispatch', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN->value)->first();
    $sm = app(OrderStateMachine::class);
    $sm->transition($order->fresh(), OrderStatus::PROCESSING, $admin);
    $sm->transition($order->fresh(), OrderStatus::READY_FOR_DISPATCH, $admin);
    $raced = null;
    Http::fake(['api.paystack.co/refund' => function () use ($order, $sm, $admin, &$raced) {
        try {
            $sm->transition($order->fresh(), OrderStatus::DISPATCHED, $admin);
            $raced = 'dispatched';
        } catch (ApiException $e) {
            $raced = $e->errorCode;
        }

        return Http::response(['status' => true, 'data' => ['id' => 1, 'status' => 'pending']], 200);
    }]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertOk();
    expect($raced)->toBe('REFUND_IN_PROGRESS')->and(rfStatus($order))->toBe('READY_FOR_DISPATCH');

    // Dispatch first, then approve.
    $late = rfOrder();
    $lr = rfRequest($late);
    rfSetStatus($late, 'DISPATCHED');
    $calls = count(Http::recorded());
    rfDecide($lr, ['decision' => 'APPROVE'])->assertStatus(409)->assertJsonPath('code', 'REFUND_ORDER_NOT_REFUNDABLE');
    expect($lr->fresh()->status->value)->toBe('REQUESTED')->and(count(Http::recorded()))->toBe($calls);
    // Rejection after dispatch is still allowed so the request does not stay open forever.
    rfDecide($lr, ['decision' => 'REJECT', 'reason' => 'Already dispatched'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
});

it('requires a reason to reject, leaves the order alone, and rejects double decisions', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    rfDecide($r, ['decision' => 'REJECT'])->assertStatus(422);
    rfDecide($r, ['decision' => 'MAYBE'])->assertStatus(422);
    rfDecide($r, ['decision' => 'REJECT', 'reason' => 'Outside policy'])->assertOk()->assertJsonPath('data.decisionReason', 'Outside policy');
    expect(rfStatus($order))->toBe('PAID')->and(AuditLog::query()->where('action', 'refund.rejected')->count())->toBe(1);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(409)->assertJsonPath('code', 'REFUND_NOT_DECIDABLE');
    Http::assertNothingSent();
});

it('leaves an approved manual-order refund awaiting manual completion', function () {
    $order = rfOrder(manual: true);
    $r = rfRequest($order);
    rfLogin('ADMIN');
    rfDecide($r, ['decision' => 'APPROVE'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
    Http::assertNothingSent();
    expect(rfStatus($order))->toBe('PAID');

    $url = "/api/v1/admin/refunds/{$r->id}/manual-completion";
    test()->postJson($url, ['method' => 'MOMO', 'reference' => 'MOMO-R-1', 'amountMinor' => 500])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_MISMATCH');
    test()->postJson($url, ['method' => 'BITCOIN', 'reference' => 'MOMO-R-1', 'amountMinor' => 1000])->assertStatus(422);
    expect(rfStatus($order))->toBe('PAID');
    test()->postJson($url, ['method' => 'MOMO', 'reference' => 'MOMO-R-1', 'amountMinor' => 1000])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    expect(rfStatus($order))->toBe('REFUNDED')->and(RefundTransaction::query()->sole()->method->value)->toBe('MANUAL');
    // Idempotent replay; a different reference is a conflict.
    test()->postJson($url, ['method' => 'MOMO', 'reference' => 'momo-r-1', 'amountMinor' => 1000])->assertOk();
    test()->postJson($url, ['method' => 'MOMO', 'reference' => 'MOMO-R-2', 'amountMinor' => 1000])->assertStatus(409);
    expect(RefundTransaction::query()->count())->toBe(1)
        ->and(DB::table('order_status_events')->where('order_id', $order->id)->where('to_status', 'REFUNDED')->count())->toBe(1);
});

it('refuses manual completion for online orders, unapproved requests and duplicate references', function () {
    rfLogin('ADMIN');
    $online = rfRequest(rfOrder());
    DB::table('refund_requests')->where('id', $online->id)->update(['status' => 'APPROVED']);
    test()->postJson("/api/v1/admin/refunds/{$online->id}/manual-completion", ['method' => 'MOMO', 'reference' => 'MOMO-X', 'amountMinor' => 1000])
        ->assertStatus(409)->assertJsonPath('code', 'REFUND_NOT_MANUAL');

    $manual = rfRequest(rfOrder(manual: true));
    test()->postJson("/api/v1/admin/refunds/{$manual->id}/manual-completion", ['method' => 'MOMO', 'reference' => 'MOMO-X', 'amountMinor' => 1000])
        ->assertStatus(409)->assertJsonPath('code', 'REFUND_NOT_COMPLETABLE');

    $a = rfRequest(rfOrder(manual: true), 'APPROVED');
    $b = rfRequest(rfOrder(manual: true), 'APPROVED');
    test()->postJson("/api/v1/admin/refunds/{$a->id}/manual-completion", ['method' => 'CARD', 'reference' => 'DUP-1', 'amountMinor' => 1000])->assertOk();
    test()->postJson("/api/v1/admin/refunds/{$b->id}/manual-completion", ['method' => 'CARD', 'reference' => 'DUP-1', 'amountMinor' => 1000])
        ->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REFUND_REFERENCE');
});

it('dedupes refund events by refund_reference so a failed refund A and a processed refund B on one charge both apply', function () {
    $order = rfOrder();
    $a = rfRequest($order, 'PROCESSING');
    $failed = rfWebhook($order, 'refund.failed', ['id' => 1, 'refund_reference' => 'rr-A']);
    expect($failed->provider_event_id)->toBe('refund.failed:rr-A')
        ->and($a->fresh()->status->value)->toBe('FAILED')->and(rfStatus($order))->toBe('PAID');

    $b = rfRequest($order, 'PROCESSING');
    $processed = rfWebhook($order, 'refund.processed', ['id' => 2, 'refund_reference' => 'rr-B']);
    expect($processed->provider_event_id)->toBe('refund.processed:rr-B')
        ->and($b->fresh()->status->value)->toBe('COMPLETED')->and(rfStatus($order))->toBe('REFUNDED')
        ->and(PaymentEvent::query()->count())->toBe(2);

    // Same refund_reference replayed with a different provider id is still one receipt; never collides with charge.success.
    rfWebhook($order, 'refund.processed', ['id' => 99, 'refund_reference' => 'rr-B']);
    expect(PaymentEvent::query()->count())->toBe(2)
        ->and(PaymentEvent::query()->where('provider_event_id', 'like', 'charge.success:%')->count())->toBe(0);
});

it('retries an unconfirmed APPROVED online refund (admin only, one claim at a time)', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    $url = "/api/v1/admin/refunds/{$r->id}/retry";
    $nested = null;
    $handler = fn () => throw new ConnectionException('timeout');
    $lookup = fn () => Http::response(['status' => true, 'data' => []], 200);
    $posts = 0;
    Http::fake(['api.paystack.co/refund*' => function (HttpRequest $q) use (&$handler, &$lookup, &$posts) {
        if ($q->method() === 'GET') {
            return $lookup($q);
        }
        $posts++;

        return $handler($q);
    }]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(503);

    rfLogin('SALES_ORDERS');
    test()->postJson($url)->assertForbidden();
    rfLogin('ADMIN');

    // A retry made while another call is outstanding is refused (claim window) and sends nothing.
    $handler = function () use ($url, &$nested) {
        $nested = test()->postJson($url)->getStatusCode();

        return Http::response(['status' => true, 'data' => ['id' => 7002, 'status' => 'pending', 'amount' => 1000, 'currency' => 'GHS']], 200);
    };
    test()->postJson($url)->assertOk()->assertJsonPath('data.status', 'PROCESSING');
    expect($nested)->toBe(409)->and(rfStatus($order))->toBe('PAID')->and(RefundTransaction::query()->sole()->status->value)->toBe('PENDING')
        ->and(AuditLog::query()->where('action', 'refund.retry.requested')->count())->toBe(1);

    // Not retryable once a transaction row exists or the request is not APPROVED.
    test()->postJson($url)->assertStatus(409)->assertJsonPath('code', 'REFUND_NOT_RETRYABLE');
    rfWebhook($order);
    test()->postJson($url)->assertStatus(409)->assertJsonPath('code', 'REFUND_NOT_RETRYABLE');
});

it('allows a retry once a crashed claim has expired, refuses manual orders, and handles a retry rejection', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    $handler = fn () => throw new ConnectionException('timeout');
    $lookup = fn () => Http::response(['status' => true, 'data' => []], 200);
    $posts = 0;
    Http::fake(['api.paystack.co/refund*' => function (HttpRequest $q) use (&$handler, &$lookup, &$posts) {
        if ($q->method() === 'GET') {
            return $lookup($q);
        }
        $posts++;

        return $handler($q);
    }]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(503);
    $url = "/api/v1/admin/refunds/{$r->id}/retry";
    DB::table('refund_requests')->where('id', $r->id)->update(['provider_call_started_at' => now()->subMinutes(5)]);
    $handler = fn () => Http::response(['status' => false, 'message' => 'Refund refused (generic test stub)'], 400);
    test()->postJson($url)->assertStatus(502);
    expect($r->fresh()->status->value)->toBe('FAILED')->and(rfStatus($order))->toBe('PAID')
        ->and(RefundTransaction::query()->sole()->status->value)->toBe('FAILED');

    $manual = rfRequest(rfOrder(manual: true), 'APPROVED');
    test()->postJson("/api/v1/admin/refunds/{$manual->id}/retry")->assertStatus(409);
    $requested = rfRequest(rfOrder());
    test()->postJson("/api/v1/admin/refunds/{$requested->id}/retry")->assertStatus(409);
});

it('only ever asks Paystack for the order total, never more than was charged', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    rfFakeAccepted();
    rfDecide($r, ['decision' => 'APPROVE', 'amountMinor' => 999999])->assertOk();
    Http::assertSent(fn (HttpRequest $q): bool => $q['amount'] === (int) $order->total_paid_minor);
    // Retrying cannot add a second refund once Paystack accepted the first.
    test()->postJson("/api/v1/admin/refunds/{$r->id}/retry")->assertStatus(409);
    Http::assertSentCount(1);
});

it('counts refund.reconciliation_required audit rows without a request in the dashboard', function () {
    rfWebhook(rfOrder());
    rfLogin('ADMIN');
    expect(RefundRequest::query()->count())->toBe(0);
    test()->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.refundReconciliationCount', 1);
});

it('adopts a refund Paystack already created instead of POSTing again on retry', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    $handler = fn () => throw new ConnectionException('timeout');
    $lookup = fn () => Http::response(['status' => true, 'data' => []], 200);
    $posts = 0;
    $lookups = 0;
    Http::fake(['api.paystack.co/refund*' => function (HttpRequest $q) use (&$handler, &$lookup, &$posts, &$lookups) {
        if ($q->method() === 'GET') {
            $lookups++;

            return $lookup($q);
        }
        $posts++;

        return $handler($q);
    }]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(503);
    expect($posts)->toBe(1)->and($lookups)->toBe(0);
    $url = "/api/v1/admin/refunds/{$r->id}/retry";

    // Lookup fails: no POST, still APPROVED.
    $lookup = fn () => Http::response('down', 500);
    test()->postJson($url)->assertStatus(503);
    expect($posts)->toBe(1)->and($r->fresh()->status->value)->toBe('APPROVED');

    // A listed refund that does not identify its charge cannot be matched: no POST.
    DB::table('refund_requests')->where('id', $r->id)->update(['provider_call_started_at' => null]);
    $lookup = fn () => Http::response(['status' => true, 'data' => [['id' => 1, 'status' => 'pending']]], 200);
    test()->postJson($url)->assertStatus(503);
    expect($posts)->toBe(1);

    // Another charge's refund and a failed refund for this charge are ignored: the POST proceeds (lookup empty-equivalent).
    $lookup = fn () => Http::response(['status' => true, 'data' => [
        ['id' => 2, 'status' => 'pending', 'transaction' => ['reference' => 'other-charge']],
        ['id' => 3, 'status' => 'failed', 'transaction' => ['reference' => $order->payment_reference]],
    ]], 200);
    $handler = fn () => Http::response(['status' => true, 'data' => ['id' => 7003, 'status' => 'pending']], 200);
    test()->postJson($url)->assertOk()->assertJsonPath('data.status', 'PROCESSING');
    expect($posts)->toBe(2)->and(RefundTransaction::query()->sole()->provider_reference)->toBe('7003');
});

it('takes over an existing Paystack refund with no second POST', function () {
    $order = rfOrder();
    $r = rfRequest($order);
    rfLogin('ADMIN');
    $posts = 0;
    $lookup = null;
    Http::fake(['api.paystack.co/refund*' => function (HttpRequest $q) use (&$posts, &$lookup) {
        if ($q->method() === 'GET') {
            return $lookup($q);
        }
        $posts++;
        throw new ConnectionException('timeout');
    }]);
    rfDecide($r, ['decision' => 'APPROVE'])->assertStatus(503);
    $lookup = fn () => Http::response(['status' => true, 'data' => [
        ['id' => 8123, 'status' => 'processing', 'amount' => 1000, 'currency' => 'GHS', 'transaction' => ['reference' => $order->payment_reference]],
    ]], 200);
    test()->postJson("/api/v1/admin/refunds/{$r->id}/retry")->assertOk()->assertJsonPath('data.status', 'PROCESSING');
    expect($posts)->toBe(1)->and(RefundTransaction::query()->count())->toBe(1)
        ->and(RefundTransaction::query()->sole()->status->value)->toBe('PENDING')
        ->and(RefundTransaction::query()->sole()->provider_reference)->toBe('8123')
        ->and(rfStatus($order))->toBe('PAID')
        ->and(AuditLog::query()->where('action', 'refund.provider.adopted')->count())->toBe(1);
    // The webhook then completes it as usual.
    rfWebhook($order);
    expect($r->fresh()->status->value)->toBe('COMPLETED')->and(rfStatus($order))->toBe('REFUNDED');
});

it('keeps monetary provider timeouts well inside the retry claim window', function () {
    expect(PaystackClient::MONETARY_TIMEOUT_SECONDS)->toBeGreaterThan(0)
        ->and(2 * (PaystackClient::MONETARY_TIMEOUT_SECONDS + 3))->toBeLessThan(DecideRefund::CLAIM_WINDOW_SECONDS);
});
