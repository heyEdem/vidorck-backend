<?php

use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackUnavailable;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.paystack.secret_key' => 'sk_test_webhook', 'services.paystack.base_url' => 'https://api.paystack.co', 'queue.default' => 'sync']);
    Http::preventStrayRequests();
});

function webhookAttempt(array $overrides = []): CheckoutAttempt
{
    return CheckoutAttempt::query()->create(array_replace([
        'public_id' => '01J9Z3N4BR1AK7M4FJQ5ED6CXY', 'paystack_reference' => 'REF-WEBHOOK-1',
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'ama@example.com'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Osu', 'address' => 'Oxford Street'],
        'items_snapshot_json' => [['lineId' => 'line-1', 'lineType' => 'PRODUCT', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => CheckoutAttemptStatus::PENDING,
        'expires_at' => now()->addHour(),
    ], $overrides));
}

/** Deliver fixture bytes exactly as received, preserving its whitespace. */
function deliverPaystack(string $body, ?string $signature = null, array $headers = []): TestResponse
{
    $signature ??= hash_hmac('sha512', $body, 'sk_test_webhook');

    return test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
    ], $headers), $body);
}

function paystackFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/paystack/charge-success.json')) ?: '';
}

function successfulPaystackVerification(array $overrides = []): array
{
    return array_replace([
        'id' => 812345, 'status' => 'success', 'reference' => 'REF-WEBHOOK-1', 'amount' => 1000, 'currency' => 'GHS',
        'channel' => 'mobile_money', 'paid_at' => '2026-10-10T10:00:00.000Z',
        'metadata' => ['attemptId' => '01J9Z3N4BR1AK7M4FJQ5ED6CXY'],
        'authorization' => ['authorization_code' => 'AUTH_SECRET', 'bin' => '411111', 'last4' => '1111'],
    ], $overrides);
}

it('rejects invalid signatures before writing or queueing and keeps log context free of secrets', function (): void {
    Log::spy();
    $body = paystackFixture();

    deliverPaystack($body, 'bad-signature')->assertUnauthorized()->assertJsonPath('code', 'INVALID_SIGNATURE');
    expect(PaymentEvent::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context): bool => ! str_contains(json_encode($context), 'AUTH_SECRET') && ! str_contains(json_encode($context), $body));
});

it('authenticates the raw body, bypasses stateful CSRF, and queues database work without provider HTTP', function (): void {
    $attempt = webhookAttempt();
    $body = paystackFixture();

    $this->app->detectEnvironment(fn () => 'local');
    config(['sanctum.stateful' => ['admin.example.test']]);
    $this->app->bind(ValidateCsrfToken::class, static fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    deliverPaystack($body, headers: ['HTTP_ORIGIN' => 'https://admin.example.test'])->assertOk()->assertJsonPath('received', true);

    $receipt = PaymentEvent::query()->sole();
    expect($receipt->reference)->toBe($attempt->paystack_reference)
        ->and($receipt->processed_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(json_encode($receipt->payload_redacted_json))->not->toContain('AUTH_SECRET', 'last4', 'private', '411111');
    Http::assertNothingSent();
});

it('deduplicates signed charge receipts and processes one event into one order', function (): void {
    webhookAttempt();
    $body = paystackFixture();
    deliverPaystack($body)->assertOk();
    $receipt = PaymentEvent::query()->sole();
    deliverPaystack($body)->assertOk();
    deliverPaystack($body)->assertOk();
    expect(DB::table('jobs')->count())->toBe(3)->and(PaymentEvent::query()->count())->toBe(1);

    Http::fake(['https://api.paystack.co/transaction/verify/REF-WEBHOOK-1' => Http::response(['status' => true, 'data' => successfulPaystackVerification()])]);
    (new ProcessPaystackEvent((int) $receipt->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));
    (new ProcessPaystackEvent((int) $receipt->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));

    expect(Order::query()->count())->toBe(1)->and($receipt->fresh()->processed_at)->not->toBeNull()
        ->and(CheckoutAttempt::query()->sole()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED);
    $snapshot = CheckoutAttempt::query()->sole()->verification_snapshot_json;
    expect($snapshot)->toMatchArray([
        'id' => 812345, 'status' => 'success', 'reference' => 'REF-WEBHOOK-1', 'amount' => 1000,
        'currency' => 'GHS', 'channel' => 'mobile_money', 'metadata' => ['attemptId' => '01J9Z3N4BR1AK7M4FJQ5ED6CXY'],
    ])->not->toHaveKey('authorization');
    deliverPaystack($body)->assertOk();
    deliverPaystack($body)->assertOk();
    deliverPaystack($body)->assertOk();
    // Exclude the order-confirmation SendNotification job queued inside the order transaction.
    expect(DB::table('jobs')->where('payload', 'not like', '%SendNotification%')->count())->toBe(3);
    Http::assertSentCount(1);
});

it('marks strict amount currency reference metadata status and numeric-string mismatches without creating orders', function (): void {
    foreach ([
        ['amount' => 999], ['amount' => '1000'], ['amount' => 1000.0], ['currency' => 'USD'], ['reference' => 'OTHER'],
        ['metadata' => ['attemptId' => 'wrong']], ['status' => 'failed'],
    ] as $index => $mismatch) {
        $attempt = webhookAttempt(['public_id' => sprintf('01J9Z3N4BR1AK7M4FJQ5ED6C%02d', $index), 'paystack_reference' => 'REF-MISMATCH-'.$index]);
        $verification = successfulPaystackVerification(array_replace(['reference' => $attempt->paystack_reference, 'metadata' => ['attemptId' => $attempt->public_id]], $mismatch));
        app(HandleChargeSuccess::class)->execute($attempt, $verification);
        expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::MISMATCH)->and($attempt->fresh()->verified_at)->toBeNull();
    }
    expect(Order::query()->count())->toBe(0);
});

it('does not replace paid attempt verification or downgrade an existing order after a late mismatch', function (): void {
    $attempt = webhookAttempt();
    $handler = app(HandleChargeSuccess::class);
    $order = $handler->execute($attempt, successfulPaystackVerification());
    $savedSnapshot = $attempt->fresh()->verification_snapshot_json;
    $handler->execute($attempt->fresh(), successfulPaystackVerification(['amount' => 900]));

    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED)
        ->and($attempt->fresh()->verification_snapshot_json)->toBe($savedSnapshot)
        ->and(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->id)->toBe($order?->id);
});

it('ignores unknown references and event types and marks their receipts processed', function (): void {
    $unknownCharge = '{"event":"charge.success","data":{"reference":"NO-SUCH-ATTEMPT"}}';
    $unknownEvent = '{"event":"customer.created","data":{"id":456,"email":"secret@example.com"}}';
    deliverPaystack($unknownCharge)->assertOk();
    deliverPaystack($unknownEvent)->assertOk();
    $receipts = PaymentEvent::query()->orderBy('id')->get();
    foreach ($receipts as $receipt) {
        (new ProcessPaystackEvent((int) $receipt->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));
    }
    expect($receipts->every(fn (PaymentEvent $event): bool => $event->fresh()->processed_at !== null))
        ->toBeTrue()->and(Order::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('returns safe errors for signed malformed JSON and missing charge references', function (): void {
    deliverPaystack('{broken')->assertBadRequest();
    deliverPaystack('{"event":"charge.success","data":{}}')->assertUnprocessable();
    expect(PaymentEvent::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('keeps a failed verification retryable and does not mark its receipt processed', function (): void {
    webhookAttempt();
    deliverPaystack(paystackFixture())->assertOk();
    $receipt = PaymentEvent::query()->sole();
    Http::fake(['https://api.paystack.co/transaction/verify/REF-WEBHOOK-1' => Http::response(['status' => false], 503)]);
    expect(fn () => (new ProcessPaystackEvent((int) $receipt->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class)))
        ->toThrow(PaystackUnavailable::class);
    expect($receipt->fresh()->processed_at)->toBeNull()->and(CheckoutAttempt::query()->sole()->status)->toBe(CheckoutAttemptStatus::PENDING);
});

it('keeps receipt facts append-only while allowing one processed timestamp transition', function (): void {
    deliverPaystack('{"event":"other","data":{"id":1}}')->assertOk();
    $event = PaymentEvent::query()->sole();
    $facts = DB::table('payment_events')->where('id', $event->id)->first();
    expect(fn () => $event->forceFill(['reference' => 'rewritten'])->save())->toThrow('LogicException')
        ->and(fn () => $event->delete())->toThrow('LogicException')
        ->and(fn () => DB::table('payment_events')->where('id', $event->id)->update(['payload_redacted_json' => '{}']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payment_events')->where('id', $event->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => PaymentEvent::truncate())->toThrow(QueryException::class);
    expect(DB::table('payment_events')->where('id', $event->id)->first()->reference)->toBe($facts->reference)
        ->and(PaymentEvent::query()->count())->toBe(1)
        ->and($event->markProcessed())->toBeTrue();
    $processedAt = $event->fresh()->processed_at?->toIso8601String();
    expect($event->markProcessed())->toBeFalse()->and($event->fresh()->processed_at?->toIso8601String())->toBe($processedAt)
        ->and(fn () => DB::table('payment_events')->where('id', $event->id)->update(['processed_at' => null]))->toThrow(QueryException::class);
});

it('rolls back receipt insertion when the database queue push fails', function (): void {
    DB::unprepared("CREATE TRIGGER fail_job_insert BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'forced enqueue failure'); END;");
    deliverPaystack(paystackFixture())->assertServerError();
    expect(PaymentEvent::query()->count())->toBe(0);
});
