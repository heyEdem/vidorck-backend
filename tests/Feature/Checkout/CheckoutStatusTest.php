<?php

use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackVerificationPending;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.paystack.secret_key' => 'sk_test_status', 'services.paystack.base_url' => 'https://api.paystack.co']);
    Http::preventStrayRequests();
});

function statusAttempt(array $overrides = []): CheckoutAttempt
{
    $createdAt = $overrides['created_at'] ?? now()->subSeconds(20);
    unset($overrides['created_at']);
    $attempt = CheckoutAttempt::query()->create(array_replace([
        'public_id' => (string) Str::ulid(), 'paystack_reference' => 'REF-STATUS-'.Str::random(12),
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'ama@example.test'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Osu', 'address' => 'Oxford Street'],
        'items_snapshot_json' => [['lineId' => 'line-1', 'lineType' => 'PRODUCT', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => CheckoutAttemptStatus::PENDING,
        'expires_at' => now()->addHour(),
    ], $overrides));
    $attempt->forceFill(['created_at' => $createdAt])->save();

    return $attempt->fresh();
}

/** @return array<string, mixed> */
function statusVerification(CheckoutAttempt $attempt, array $overrides = []): array
{
    return array_replace([
        'status' => 'success', 'reference' => $attempt->paystack_reference, 'amount' => 1000, 'currency' => 'GHS',
        'metadata' => ['attemptId' => $attempt->public_id], 'paid_at' => '2026-10-10T10:00:00Z', 'channel' => 'mobile_money',
    ], $overrides);
}

it('waits for the initial ten seconds and atomically limits successful verification to one request per ten seconds', function (): void {
    $attempt = statusAttempt(['created_at' => now()->subSeconds(5)]);
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['status' => 'PENDING']);
    Http::assertNothingSent();

    $attempt->forceFill(['created_at' => now()->subSeconds(11)])->save();
    Http::fakeSequence()->push(['status' => true, 'data' => ['status' => 'pending']])->push(['status' => true, 'data' => ['status' => 'pending']]);
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertExactJson(['status' => 'PENDING']);
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertExactJson(['status' => 'PENDING']);
    Http::assertSentCount(1);

    $attempt->forceFill(['last_verified_at' => now()->subSeconds(11)])->save();
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertExactJson(['status' => 'PENDING']);
    Http::assertSentCount(2);
});

it('creates one order from a late verified success and returns only customer-safe fields', function (): void {
    $attempt = statusAttempt();
    Http::fake(fn (Request $request) => Http::response(['status' => true, 'data' => statusVerification($attempt, [
        'authorization' => ['authorization_code' => 'AUTH_SECRET'], 'customer' => ['email' => 'private@example.test'],
    ])]));

    $response = $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference);
    $response->assertHeader('Cache-Control', 'no-store, private');
    Http::assertSentCount(1);
    $response->assertOk()->assertJsonStructure(['status', 'orderNumber', 'trackingToken'])->assertJsonPath('status', 'SUCCEEDED');
    expect($response->json())->toHaveKeys(['status', 'orderNumber', 'trackingToken'])
        ->and($response->json())->not->toHaveKeys(['reference', 'amount', 'currency', 'authorization', 'customer', 'id'])
        ->and(json_encode($response->json()))->not->toContain('AUTH_SECRET', 'private@example.test', $attempt->public_id)
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED)
        ->and(Order::query()->count())->toBe(1);
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertJsonPath('orderNumber', $response->json('orderNumber'));
    Http::assertSentCount(1);
});

it('rebuilds a missing order from retained verified evidence before calling Paystack', function (): void {
    $pending = statusAttempt();
    $pending->forceFill(['verified_at' => now(), 'verification_snapshot_json' => statusVerification($pending)])->save();
    $succeeded = statusAttempt(['status' => CheckoutAttemptStatus::SUCCEEDED]);
    $succeeded->forceFill(['verified_at' => now(), 'verification_snapshot_json' => statusVerification($succeeded)])->save();

    $this->getJson('/api/v1/checkout/'.$pending->paystack_reference)->assertOk()->assertJsonPath('status', 'SUCCEEDED');
    $this->getJson('/api/v1/checkout/'.$succeeded->paystack_reference)->assertOk()->assertJsonPath('status', 'SUCCEEDED');
    Http::assertNothingSent();
    expect(Order::query()->count())->toBe(2);
});

it('keeps provider failures and in-progress statuses pending without writing mismatch', function (): void {
    $attempt = statusAttempt();
    Http::fake(fn (Request $request) => Http::response(['status' => false, 'message' => 'private provider detail'], 503));
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertExactJson(['status' => 'PENDING']);
    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)->and(Order::query()->count())->toBe(0);

    $attempt->forceFill(['last_verified_at' => now()->subSeconds(11)])->save();
    Http::fake(fn (Request $request) => Http::response(['status' => true, 'data' => ['status' => 'processing']]));
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertExactJson(['status' => 'PENDING']);
    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)->and(AuditLog::query()->count())->toBe(0);
});

it('marks definitive provider failures failed and abandons expired attempts only after verification', function (): void {
    $failed = statusAttempt();
    Http::fakeSequence()
        ->push(['status' => true, 'data' => ['status' => 'reversed']])
        ->push(['status' => true, 'data' => ['status' => 'abandoned']])
        ->push([], 500)->push([], 500)->push([], 500);
    $this->getJson('/api/v1/checkout/'.$failed->paystack_reference)->assertExactJson(['status' => 'FAILED', 'retryable' => true]);
    Http::assertSentCount(1);
    expect($failed->fresh()->status)->toBe(CheckoutAttemptStatus::FAILED);

    $expired = statusAttempt(['expires_at' => now()->subMinute()]);
    $this->getJson('/api/v1/checkout/'.$expired->paystack_reference)->assertExactJson(['status' => 'FAILED', 'retryable' => true]);
    expect($expired->fresh()->status)->toBe(CheckoutAttemptStatus::ABANDONED);

    $uncertain = statusAttempt(['expires_at' => now()->subMinute()]);
    $this->getJson('/api/v1/checkout/'.$uncertain->paystack_reference)->assertExactJson(['status' => 'PENDING']);
    expect($uncertain->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING);
});

it('keeps expired attempts pending while Paystack reports an in-progress transaction', function (): void {
    $attempts = [statusAttempt(['expires_at' => now()->subMinute()]), statusAttempt(['expires_at' => now()->subMinute()]), statusAttempt(['expires_at' => now()->subMinute()]), statusAttempt(['expires_at' => now()->subMinute()])];
    $statuses = ['pending', 'ongoing', 'processing', 'queued'];
    Http::fake(function (Request $request) use ($attempts, $statuses) {
        foreach ($attempts as $index => $attempt) {
            if (str_ends_with($request->url(), '/transaction/verify/'.$attempt->paystack_reference)) {
                return Http::response(['status' => true, 'data' => ['status' => $statuses[$index]]]);
            }
        }

        return Http::response(['status' => false, 'message' => 'unexpected'], 500);
    });

    foreach ($attempts as $attempt) {
        $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertExactJson(['status' => 'PENDING']);
        expect($attempt->fresh()->getRawOriginal('status'))->toBe(CheckoutAttemptStatus::PENDING->value);
    }
    Http::assertSentCount(4);
});

it('hides unknown references and reports previously succeeded orders without provider calls', function (): void {
    $this->getJson('/api/v1/checkout/unguessable-reference')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('message', 'Not found.')->assertJsonPath('code', 'NOT_FOUND');
    $attempt = statusAttempt(['status' => CheckoutAttemptStatus::FAILED]);
    $order = app(HandleChargeSuccess::class)->execute($attempt, statusVerification($attempt));
    Http::fake();
    $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)->assertOk()->assertJsonPath('status', 'SUCCEEDED')
        ->assertJsonPath('orderNumber', $order->order_number)->assertJsonPath('trackingToken', $order->tracking_token);
    Http::assertNothingSent();
});

it('applies separate per-IP limits to checkout creation and status polling', function (): void {
    for ($index = 0; $index < 30; $index++) {
        $this->getJson('/api/v1/checkout/unknown-'.$index)->assertNotFound();
    }
    $this->getJson('/api/v1/checkout/unknown-30')->assertTooManyRequests();

    for ($index = 0; $index < 10; $index++) {
        $this->postJson('/api/v1/checkout', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/checkout', [])->assertTooManyRequests();
});

it('leaves webhook receipts unprocessed when Paystack still reports an in-progress transaction', function (): void {
    $attempt = statusAttempt();
    $event = PaymentEvent::query()->create([
        'provider' => 'paystack', 'provider_event_id' => 'charge.success:'.$attempt->paystack_reference,
        'event_type' => 'charge.success', 'reference' => $attempt->paystack_reference, 'signature_valid' => true,
        'payload_redacted_json' => ['reference' => $attempt->paystack_reference],
    ]);
    Http::fake(fn (Request $request) => Http::response(['status' => true, 'data' => ['status' => 'queued']]));

    expect(fn () => (new ProcessPaystackEvent((int) $event->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class)))
        ->toThrow(PaystackVerificationPending::class);
    expect($event->fresh()->processed_at)->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)
        ->and(Order::query()->count())->toBe(0);
});

it('appends mismatch detection and resolution audit records exactly once when a verified payment recovers', function (): void {
    $attempt = statusAttempt();
    $handler = app(HandleChargeSuccess::class);
    $handler->execute($attempt, statusVerification($attempt, ['amount' => 999]));
    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::MISMATCH)
        ->and(AuditLog::query()->count())->toBe(1)
        ->and(AuditLog::query()->sole()->action)->toBe('checkout.mismatch.detected');

    $order = $handler->execute($attempt->fresh(), statusVerification($attempt));
    $handler->execute($attempt->fresh(), statusVerification($attempt));
    expect($order)->toBeInstanceOf(Order::class)
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED)
        ->and(Order::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(3)
        ->and(AuditLog::query()->pluck('action')->all())->toBe(['checkout.mismatch.detected', 'checkout.mismatch.resolved', 'order.paid'])
        ->and(AuditLog::query()->where('action', 'checkout.mismatch.resolved')->sole()->after['originalAuditEventId'])
        ->toBe(AuditLog::query()->where('action', 'checkout.mismatch.detected')->sole()->id);
});

it('audits provider success responses with mismatched amount, currency, reference, or metadata identity', function (): void {
    $attempts = [statusAttempt(), statusAttempt(), statusAttempt(), statusAttempt()];
    Http::fakeSequence()
        ->push(['status' => true, 'data' => statusVerification($attempts[0], ['amount' => 999])])
        ->push(['status' => true, 'data' => statusVerification($attempts[1], ['currency' => 'USD'])])
        ->push(['status' => true, 'data' => statusVerification($attempts[2], ['reference' => 'OTHER-REFERENCE'])])
        ->push(['status' => true, 'data' => statusVerification($attempts[3], ['metadata' => ['attemptId' => 'wrong-attempt']])]);

    foreach ($attempts as $attempt) {
        $this->getJson('/api/v1/checkout/'.$attempt->paystack_reference)
            ->assertExactJson(['status' => 'FAILED', 'retryable' => false]);
    }

    expect(CheckoutAttempt::query()->where('status', CheckoutAttemptStatus::MISMATCH)->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'checkout.mismatch.detected')->count())->toBe(4)
        ->and(Order::query()->count())->toBe(0);
});
