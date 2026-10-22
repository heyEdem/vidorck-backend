<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Str;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function rfxOrder(): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'rfx-'.Str::random(15);
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

function rfxRequest(Order $order, string $status): RefundRequest
{
    return RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'WEB', 'reason' => 'Ordered by mistake', 'status' => $status]);
}

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    StaffUser::create(['name' => 'Sales', 'email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::SALES_ORDERS]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

it('lists only open requests with open=1, matching the dashboard definition', function () {
    $requested = rfxRequest(rfxOrder(), 'REQUESTED');
    $underReview = rfxRequest(rfxOrder(), 'UNDER_REVIEW');
    $approved = rfxRequest(rfxOrder(), 'APPROVED');
    $processing = rfxRequest(rfxOrder(), 'PROCESSING');
    rfxRequest(rfxOrder(), 'REJECTED');
    rfxRequest(rfxOrder(), 'COMPLETED');
    rfxRequest(rfxOrder(), 'FAILED');

    $ids = collect($this->getJson('/api/v1/admin/refunds?open=1')->assertOk()->assertJsonPath('meta.total', 4)->json('data'))->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$requested->id, $underReview->id, $approved->id, $processing->id])->sort()->values()->all());
    $this->getJson('/api/v1/admin/refunds?open=0')->assertOk()->assertJsonPath('meta.total', 7);
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.openRefundRequests', 4);
});

it('filters by order number, ignoring case and surrounding spaces', function () {
    $order = rfxOrder();
    $mine = rfxRequest($order, 'REJECTED');
    rfxRequest(rfxOrder(), 'REQUESTED');

    $this->getJson('/api/v1/admin/refunds?orderNumber='.urlencode(' '.strtolower($order->order_number).' '))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
    $this->getJson('/api/v1/admin/refunds?orderNumber=VHI-1999-000001')->assertOk()->assertJsonPath('meta.total', 0);
});

it('combines filters', function () {
    $order = rfxOrder();
    rfxRequest($order, 'REJECTED');
    $open = rfxRequest($order, 'REQUESTED');
    $this->getJson('/api/v1/admin/refunds?open=1&orderNumber='.$order->order_number)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $open->id);
});

it('rejects malformed filters', function () {
    $this->getJson('/api/v1/admin/refunds?open=maybe')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?orderNumber='.str_repeat('X', 41))->assertUnprocessable();
});
