<?php

use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Sms\SmsMessage;
use App\Modules\Notifications\Support\RecipientIdentity;
use App\Modules\Orders\Actions\CompleteManualRefund;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\DecideRefund;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DatabaseMigrations;

require_once __DIR__.'/NotificationTestHelpers.php';

uses(DatabaseMigrations::class);

function rnOrder(bool $manual = false, ?string $email = 'ama@example.com', ?string $phone = '+233241234567'): Order
{
    // Created without an email, so only the paid SMS is logged (rnTemplates ignores it); email and phone are then set on the frozen snapshot.
    $order = app(CreateOrderFromAttempt::class)->execute(ntAttempt(null));
    $snapshot = (array) $order->customer_snapshot_json;
    $snapshot['email'] = $email;
    $snapshot['phoneE164'] = $phone;
    DB::table('orders')->where('id', $order->id)->update(['customer_snapshot_json' => json_encode($snapshot)] + ($manual ? ['source' => 'MANUAL'] : []));

    return $order->fresh();
}

function rnRequest(Order $order, string $status = 'REQUESTED'): RefundRequest
{
    return RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'WEB', 'reason' => 'Private reason', 'status' => $status]);
}

function rnWebhook(Order $order, string $event = 'refund.processed', array $data = []): void
{
    $body = json_encode(['event' => $event, 'data' => $data + [
        'id' => 9001, 'status' => $event === 'refund.processed' ? 'processed' : 'failed', 'transaction_reference' => $order->payment_reference,
        'refund_reference' => 'rr-1', 'amount' => '1000', 'currency' => 'GHS',
    ]]);
    test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', (string) $body, 'sk_test_refund'),
    ], (string) $body)->assertOk();
    rnRun(PaymentEvent::query()->orderByDesc('id')->firstOrFail());
}

function rnRun(PaymentEvent $event): void
{
    (new ProcessPaystackEvent($event->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));
}

function rnAccepted(): void
{
    Http::fake(['api.paystack.co/refund*' => Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7001, 'status' => 'pending', 'currency' => 'GHS', 'amount' => 1000]], 200)]);
}

/** @return list<string> */
function rnTemplates(): array
{
    return NotificationLog::query()->where('template', '!=', 'sms.customer_order_paid')->orderBy('id')->pluck('template')->all();
}

beforeEach(function () {
    config(['services.paystack.secret_key' => 'sk_test_refund', 'services.paystack.base_url' => 'https://api.paystack.co']);
    StaffUser::create(['name' => 'A', 'email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN, 'is_active' => true]);
    Http::preventStrayRequests();
});

function rnAdmin(): StaffUser
{
    return StaffUser::query()->where('role', StaffRole::ADMIN->value)->firstOrFail();
}

it('sends approved and rejected emails on a successful claim, without the reason', function () {
    rnAccepted();
    $order = rnOrder();
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    expect(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved']);
    $order2 = rnOrder();
    $r2 = rnRequest($order2);
    app(DecideRefund::class)->execute($r2->id, rnAdmin(), 'REJECT', 'Private rejection reason');
    expect(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved', 'refund-rejected', 'sms.customer_refund_rejected']);
    foreach (ntCommands() as $job) {
        expect(json_encode($job->payload->data))->not->toContain('Private');
    }
});

it('does not email twice on retry or a second decision', function () {
    $order = rnOrder();
    $r = rnRequest($order);
    $down = true;
    Http::fake(['api.paystack.co/refund*' => function ($q) use (&$down) {
        if ($down) {
            throw new ConnectionException('timeout');
        }

        return $q->method() === 'GET'
            ? Http::response(['status' => true, 'data' => []], 200)
            : Http::response(['status' => true, 'data' => ['id' => 7001, 'status' => 'pending', 'currency' => 'GHS', 'amount' => 1000]], 200);
    }]);
    try {
        app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(503);
    }
    expect(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved']); // unknown outcome: no failed or completed email
    $down = false;
    app(DecideRefund::class)->retry($r->id, rnAdmin());
    expect(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved']);
});

it('emails refund failure only when the conditional update set FAILED', function () {
    $order = rnOrder();
    $r = rnRequest($order);
    Http::fake(['api.paystack.co/refund*' => Http::response(['status' => false, 'message' => 'Refund not allowed'], 400)]);
    try {
        app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    } catch (HttpResponseException) {
    }
    expect($r->fresh()->status->value)->toBe('FAILED')->and(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved', 'refund-failed', 'sms.customer_refund_failed']);
});

it('sends refund-completed after a confirmed webhook, and replays send nothing', function () {
    rnAccepted();
    $order = rnOrder();
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    rnWebhook($order);
    expect($order->fresh()->status->value)->toBe('REFUNDED')->and(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved', 'refund-completed', 'sms.customer_refund_completed']);
    rnRun(PaymentEvent::query()->orderByDesc('id')->firstOrFail());
    rnWebhook($order);
    expect(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved', 'refund-completed', 'sms.customer_refund_completed']);
});

it('sends refund-failed from a failed webhook once', function () {
    rnAccepted();
    $order = rnOrder();
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    rnWebhook($order, 'refund.failed');
    rnRun(PaymentEvent::query()->orderByDesc('id')->firstOrFail());
    expect($r->fresh()->status->value)->toBe('FAILED')->and(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved', 'refund-failed', 'sms.customer_refund_failed']);
});

it('sends no completed email on an amount reconciliation conflict', function () {
    rnAccepted();
    $order = rnOrder();
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    rnWebhook($order, 'refund.processed', ['amount' => '999']);
    expect($order->fresh()->status->value)->toBe('PAID')->and(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved']);
});

it('sends completed after a manual refund and nothing on the idempotent replay', function () {
    $order = rnOrder(true);
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    app(CompleteManualRefund::class)->execute($r->id, rnAdmin(), 'MOMO', 'MR-1', 1000);
    app(CompleteManualRefund::class)->execute($r->id, rnAdmin(), 'MOMO', 'MR-1', 1000);
    expect(rnTemplates())->toBe(['refund-approved', 'sms.customer_refund_approved', 'refund-completed', 'sms.customer_refund_completed']);
});

it('skips refund email for a malformed customer email without failing the decision', function () {
    rnAccepted();
    $order = rnOrder(false, 'not-an-email');
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    expect($r->fresh()->status->value)->toBe('PROCESSING')->and(rnTemplates())->toBe(['sms.customer_refund_approved']);
});

it('leaves no refund notification when the transaction rolls back', function () {
    $order = rnOrder();
    $r = rnRequest($order);
    try {
        DB::transaction(function () use ($r) {
            app(DecideRefund::class)->execute($r->id, rnAdmin(), 'REJECT', 'no');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(rnTemplates())->toBe([])->and($r->fresh()->status->value)->toBe('REQUESTED');
});

it('queues no failed email when the request already moved on before the rejection path runs', function () {
    foreach (['PROCESSING', 'COMPLETED'] as $status) {
        $order = rnOrder();
        $r = rnRequest($order, 'APPROVED');
        Http::fake(['api.paystack.co/refund*' => function () use ($r, $status) {
            DB::table('refund_requests')->where('id', $r->id)->update(['status' => $status]); // e.g. a webhook won the race

            return Http::response(['status' => false, 'message' => 'Refund not allowed'], 400);
        }]);
        try {
            app(DecideRefund::class)->retry($r->id, rnAdmin());
        } catch (HttpResponseException) {
        }
        expect($r->fresh()->status->value)->toBe($status)->and(rnTemplates())->toBe([]);
    }
});

it('texts the customer an exact message at every refund outcome, with the amount the email shows and no reason', function () {
    $reject = false;
    Http::fake(['api.paystack.co/refund*' => function () use (&$reject) {
        return $reject
            ? Http::response(['status' => false, 'message' => 'Refund not allowed'], 400)
            : Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7001, 'status' => 'pending', 'currency' => 'GHS', 'amount' => 1000]], 200);
    }]);
    $order = rnOrder(true); // manual order: completion needs no webhook job
    $n = $order->order_number;
    app(DecideRefund::class)->execute(rnRequest($order)->id, rnAdmin(), 'APPROVE', null);
    app(CompleteManualRefund::class)->execute(RefundRequest::query()->where('order_id', $order->id)->value('id'), rnAdmin(), 'MOMO', 'MR-1', 1000);
    $rejectedOrder = rnOrder();
    app(DecideRefund::class)->execute(rnRequest($rejectedOrder)->id, rnAdmin(), 'REJECT', 'Private rejection reason');
    $failedOrder = rnOrder();
    $reject = true;
    try {
        app(DecideRefund::class)->execute(rnRequest($failedOrder)->id, rnAdmin(), 'APPROVE', null);
    } catch (HttpResponseException) {
    }
    $texts = collect(ntCommands())->filter(fn ($c) => str_starts_with($c->payload->template, 'sms.customer_refund_'))
        ->mapWithKeys(fn ($c) => [$c->payload->template.'|'.$c->payload->data['orderNumber'] => [$c->recipient, SmsMessage::forPayload($c->payload)]])->all();
    expect($texts)->toBe([
        'sms.customer_refund_approved|'.$n => ['+233241234567', "Vidorck Hair: your refund of GHS 10.00 for order {$n} is approved. We'll text you when it's sent."],
        'sms.customer_refund_completed|'.$n => ['+233241234567', "Vidorck Hair: your refund of GHS 10.00 for order {$n} is complete. It may take a few days to show."],
        'sms.customer_refund_rejected|'.$rejectedOrder->order_number => ['+233241234567', "Vidorck Hair: we couldn't approve the refund for order {$rejectedOrder->order_number}. Contact us if you have questions."],
        'sms.customer_refund_approved|'.$failedOrder->order_number => ['+233241234567', "Vidorck Hair: your refund of GHS 10.00 for order {$failedOrder->order_number} is approved. We'll text you when it's sent."],
        'sms.customer_refund_failed|'.$failedOrder->order_number => ['+233241234567', "Vidorck Hair: there was a problem sending your refund for order {$failedOrder->order_number}. We're looking into it."],
    ]);
});

it('texts a refund once per outcome per request, and only the SMS when the email is missing', function () {
    rnAccepted();
    $order = rnOrder(false, null);
    $r = rnRequest($order);
    app(DecideRefund::class)->execute($r->id, rnAdmin(), 'APPROVE', null);
    rnWebhook($order);
    rnRun(PaymentEvent::query()->orderByDesc('id')->firstOrFail());
    expect(rnTemplates())->toBe(['sms.customer_refund_approved', 'sms.customer_refund_completed']);
});

it('sends only the refund email when the customer phone is invalid', function () {
    rnAccepted();
    $order = rnOrder(false, 'ama@example.com', 'not-a-phone');
    app(DecideRefund::class)->execute(rnRequest($order)->id, rnAdmin(), 'APPROVE', null);
    expect(rnTemplates())->toBe(['refund-approved']);
});

it('pins the refund SMS idempotency keys across a failed refund and a later completed one', function () {
    $reject = true;
    Http::fake(['api.paystack.co/refund*' => function () use (&$reject) {
        return $reject
            ? Http::response(['status' => false, 'message' => 'Refund not allowed'], 400)
            : Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 7001, 'status' => 'pending', 'currency' => 'GHS', 'amount' => 1000]], 200);
    }]);
    $order = rnOrder();
    $failed = rnRequest($order);
    try {
        app(DecideRefund::class)->execute($failed->id, rnAdmin(), 'APPROVE', null);
    } catch (HttpResponseException) {
    }
    expect($failed->fresh()->status->value)->toBe('FAILED');

    // A FAILED request is terminal: retry is refused and queues nothing, so the next attempt is a new request.
    expect(fn () => app(DecideRefund::class)->retry($failed->id, rnAdmin()))->toThrow(ApiException::class);
    $reject = false;
    $second = rnRequest($order);
    app(DecideRefund::class)->execute($second->id, rnAdmin(), 'APPROVE', null);
    rnWebhook($order);
    expect($second->fresh()->status->value)->toBe('COMPLETED');

    $hash = RecipientIdentity::for(NotificationChannel::SMS, '+233241234567')->hash;
    $logs = NotificationLog::query()->whereIn('template', ['sms.customer_refund_failed', 'sms.customer_refund_completed'])->orderBy('id')->get();
    expect($logs->pluck('idempotency_key')->all())->toBe([
        "refund-failed:{$failed->id}:SMS:{$hash}",
        "refund-completed:{$second->id}:SMS:{$hash}",
    ])->and($logs->pluck('channel')->map(fn ($c) => $c->value)->unique()->all())->toBe(['SMS'])
        ->and(NotificationLog::query()->where('idempotency_key', 'like', 'refund-failed:%')->count())->toBe(2); // email + SMS
});
