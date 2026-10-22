<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function trkOrder(string $phone = '0241234567'): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'tr-'.Str::random(15);
    $attempt = CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233'.substr($phone, 1), 'phoneDisplay' => $phone, 'email' => 'ama@example.com'],
        'delivery_snapshot_json' => ['area' => 'Osu', 'address' => '12 Secret Lane'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Shea Oil', 'size' => '250ml', 'productCode' => 'OIL', 'quantity' => 2, 'unitPriceMinor' => 500, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);

    return app(CreateOrderFromAttempt::class)->execute($attempt);
}

function trkPost(array $payload): TestResponse
{
    return test()->postJson('/api/v1/orders/track', $payload);
}

it('tracks with normalised order number and phone and returns only customer-safe fields', function () {
    $order = trkOrder();
    $staff = StaffUser::create(['name' => 'Kofi Staff', 'email' => 'k@example.com', 'password' => 'secret-pass-123', 'role' => StaffRole::ADMIN, 'is_active' => true]);
    $order->load('customer');
    OrderNote::query()->create(['order_id' => $order->id, 'staff_id' => $staff->id, 'body' => 'INTERNAL-NOTE', 'created_at' => now()]);
    app(OrderStateMachine::class)->transition($order, OrderStatus::PROCESSING, $staff, 'secret internal reason');

    $response = trkPost(['orderNumber' => '  '.strtolower($order->order_number).' ', 'phone' => '+233241234567'])->assertOk()
        ->assertJsonPath('data.orderNumber', $order->order_number)
        ->assertJsonPath('data.status', 'PROCESSING')
        ->assertJsonPath('data.totalPaidMinor', 1000)
        ->assertJsonPath('data.items.0.name', 'Shea Oil')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.timeline.1.status', 'PROCESSING');
    trkPost(['orderNumber' => $order->order_number, 'phone' => '0241234567'])->assertOk();

    $body = $response->getContent();
    foreach (['INTERNAL-NOTE', 'Kofi', 'secret internal reason', $order->payment_reference, $order->tracking_token, 'Secret Lane', 'ama@example.com'] as $leak) {
        expect($body)->not->toContain($leak);
    }
    expect(array_keys($response->json('data')))->toBe(['orderNumber', 'status', 'createdAt', 'paidAt', 'totalPaidMinor', 'currency', 'items', 'timeline']);
});

it('tracking_does_not_enumerate', function () {
    $order = trkOrder();
    $wrongPhone = trkPost(['orderNumber' => $order->order_number, 'phone' => '0201111111']);
    $unknown = trkPost(['orderNumber' => 'VHI-2026-999999', 'phone' => '0241234567']);
    $malformed = trkPost(['orderNumber' => $order->order_number, 'phone' => 'not-a-phone']);

    $wrongPhone->assertNotFound();
    expect($unknown->status())->toBe(404)->and($malformed->status())->toBe(404)
        ->and($unknown->getContent())->toBe($wrongPhone->getContent())
        ->and($malformed->getContent())->toBe($wrongPhone->getContent())
        ->and($wrongPhone->json('code'))->toBe('ORDER_NOT_FOUND');
    expect($wrongPhone->headers->get('X-Request-Id'))->not->toBe($unknown->headers->get('X-Request-Id'));
});

it('requires order number and phone', function () {
    trkPost([])->assertUnprocessable();
});

it('rate limits tracking at 5 per minute per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        trkPost(['orderNumber' => 'VHI-2026-00000'.$i, 'phone' => '0241234567'])->assertNotFound();
    }
    trkPost(['orderNumber' => 'VHI-2026-000009', 'phone' => '0241234567'])->assertStatus(429);
});

it('rate limits tracking at 10 per hour per order number across IPs', function () {
    for ($i = 0; $i < 10; $i++) {
        test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])
            ->postJson('/api/v1/orders/track', ['orderNumber' => 'VHI-2026-000001', 'phone' => '0241234567'])->assertNotFound();
    }
    test()->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->postJson('/api/v1/orders/track', ['orderNumber' => 'vhi-2026-000001', 'phone' => '0241234567'])->assertStatus(429);
});

it('returns the compact confirmation payload for a tracking token', function () {
    $order = trkOrder();
    $response = test()->getJson('/api/v1/orders/confirmation/'.$order->tracking_token)->assertOk()
        ->assertJsonPath('data.orderNumber', $order->order_number)
        ->assertJsonPath('data.totalPaidMinor', 1000)
        ->assertJsonPath('data.deliveryFeeNote', 'Delivery fee is arranged separately.');
    expect($response->getContent())->not->toContain($order->payment_reference)->not->toContain($order->tracking_token)
        ->not->toContain('Secret Lane');
    test()->getJson('/api/v1/orders/confirmation/'.Str::random(32))->assertNotFound()->assertJsonPath('code', 'ORDER_NOT_FOUND');
    test()->getJson('/api/v1/orders/confirmation/short')->assertNotFound();
});

it('collapses corrections so the customer sees only the effective path while admin history stays full', function () {
    $order = trkOrder();
    $admin = StaffUser::create(['name' => 'Kofi Staff', 'email' => 'k2@example.com', 'password' => 'secret-pass-123', 'role' => StaffRole::ADMIN, 'is_active' => true]);
    $machine = app(OrderStateMachine::class);
    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED] as $i => $to) {
        $this->travel($i + 1)->minutes();
        $machine->transition($order, $to, $admin);
    }
    $this->travel(10)->minutes();
    $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, $admin, 'SECRET-WRONG-CLICK');
    $this->travel(5)->minutes();
    $machine->transition($order, OrderStatus::DISPATCHED, $admin);

    $timeline = trkPost(['orderNumber' => $order->order_number, 'phone' => '0241234567'])->assertOk()->json('data.timeline');
    expect(array_column($timeline, 'status'))->toBe(['PAID', 'PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED'])
        ->and(array_keys($timeline[3]))->toBe(['status', 'at']);
    $events = $order->statusEvents()->orderBy('id')->get();
    expect($events)->toHaveCount(6)->and($timeline[3]['at'])->toBe($events[5]->created_at->toIso8601String())
        ->and($timeline[2]['at'])->toBe($events[4]->created_at->toIso8601String());

    $short = trkPost(['orderNumber' => $order->order_number, 'phone' => '0241234567']);
    expect($short->getContent())->not->toContain('SECRET-WRONG-CLICK')->not->toContain('Kofi');

    // Correction without a re-advance: reversed status is dropped.
    $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, $admin, 'Dispatched by mistake');
    $after = trkPost(['orderNumber' => $order->order_number, 'phone' => '0241234567'])->json('data.timeline');
    expect(array_column($after, 'status'))->toBe(['PAID', 'PROCESSING', 'READY_FOR_DISPATCH'])
        ->and($order->statusEvents()->count())->toBe(7);
});
