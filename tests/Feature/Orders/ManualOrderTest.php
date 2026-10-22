<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Actions\ListReconciliationAttempts;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

const MO_PASSWORD = 'Long-Staff-Password9!';

function moLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => MO_PASSWORD])->assertOk();
}

/** @param array<string, mixed> $overrides */
function moPayload(array $overrides = []): array
{
    return array_replace([
        'confirm' => true, 'fullName' => 'Ama Mensah', 'phone' => '0244000000', 'email' => 'ama@example.com',
        'region' => 'Greater Accra', 'area' => 'Tema', 'address' => '12 Oxford Street', 'specialArrangement' => true,
        'paymentMethod' => 'MOMO', 'paymentReference' => 'MOMO-123', 'amountPaidMinor' => 2600, 'note' => 'Ordered on WhatsApp',
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2, 'lineId' => 'l1']],
    ], $overrides);
}

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::create(['name' => ucfirst(strtolower($role->value)), 'email' => strtolower($role->value).'@example.com', 'password' => MO_PASSWORD, 'role' => $role]);
    }
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1400, 'price_minor' => 1300, 'is_active' => true]);
});

it('requires authentication', function () {
    $this->postJson('/api/v1/admin/orders/manual', moPayload())->assertUnauthorized();
});

it('creates a PAID MANUAL order for both roles with snapshots, history, audit, note, customer and OrderPaid', function () {
    Event::fake([OrderPaid::class]);
    moLogin('SALES_ORDERS');
    $staff = StaffUser::query()->where('role', 'SALES_ORDERS')->sole();

    $res = $this->postJson('/api/v1/admin/orders/manual', moPayload())->assertCreated()
        ->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.source', 'MANUAL')
        ->assertJsonPath('data.totalPaidMinor', 2600)->assertJsonPath('data.createdByStaffId', $staff->id)
        ->assertJsonPath('data.paymentMethod', 'MOMO')->assertJsonPath('data.items.0.unitPriceMinor', 1300)
        ->assertJsonPath('data.items.0.name', 'Shampoo')->assertJsonPath('data.delivery.specialArrangement', true);

    $order = Order::query()->where('order_number', $res->json('data.orderNumber'))->sole();
    expect($order->status)->toBe(OrderStatus::PAID)->and($order->checkout_attempt_id)->toBeNull()
        ->and($order->payment_reference)->toBe('MOMO-123')->and($order->paid_at)->not->toBeNull()
        ->and(OrderStatusEvent::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(OrderStatusEvent::query()->first()->actor_id)->toBe($staff->id)
        ->and(OrderNote::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(Customer::query()->sole()->orders_count)->toBe(1);
    $audit = AuditLog::query()->where('action', 'order.manual.created')->sole();
    expect($audit->actor_id)->toBe($staff->id)->and($audit->after['reference'])->toBe('MOMO-123')->and($audit->after['method'])->toBe('MOMO');
    Event::assertDispatched(OrderPaid::class, 1);

    moLogin('ADMIN');
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['paymentReference' => 'CASH-9', 'paymentMethod' => 'CASH_OR_OTHER']))->assertCreated();
});

it('manual_order_duplicate_reference_rejected', function () {
    moLogin('SALES_ORDERS');
    $this->postJson('/api/v1/admin/orders/manual', moPayload())->assertCreated();
    foreach (['MOMO-123', ' momo-123 '] as $ref) {
        $this->postJson('/api/v1/admin/orders/manual', moPayload(['paymentReference' => $ref]))->assertStatus(409)
            ->assertJsonPath('code', 'DUPLICATE_PAYMENT_REFERENCE');
    }
    expect(Order::query()->count())->toBe(1)->and(Customer::query()->sole()->orders_count)->toBe(1)
        ->and(AuditLog::query()->where('action', 'order.manual.created')->count())->toBe(1);
});

it('never creates an order in a status other than PAID', function () {
    moLogin('ADMIN');
    foreach (['PENDING', 'PROCESSING', 'DELIVERED', 'CANCELLED'] as $status) {
        $this->postJson('/api/v1/admin/orders/manual', moPayload(['status' => $status]))->assertUnprocessable();
    }
    expect(Order::query()->count())->toBe(0);
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['status' => 'PAID']))->assertCreated();
    expect(fn () => Order::query()->create(['status' => OrderStatus::DELIVERED]))->toThrow(LogicException::class);
});

it('requires explicit confirm true and valid payment fields', function () {
    moLogin('ADMIN');
    foreach ([false, 'true', 1, null] as $confirm) {
        $this->postJson('/api/v1/admin/orders/manual', moPayload(['confirm' => $confirm]))->assertUnprocessable();
    }
    $bad = ['paymentMethod' => 'BITCOIN', 'paymentReference' => '', 'amountPaidMinor' => 0, 'phone' => '12345', 'items' => []];
    foreach ($bad as $field => $value) {
        $this->postJson('/api/v1/admin/orders/manual', moPayload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    expect(Order::query()->count())->toBe(0);
});

it('rejects an amount that differs from the current catalogue total', function () {
    moLogin('ADMIN');
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['amountPaidMinor' => 2599]))->assertUnprocessable()
        ->assertJsonPath('code', 'AMOUNT_MISMATCH');
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['amountPaidMinor' => '2600']))->assertUnprocessable();
    expect(Order::query()->count())->toBe(0)->and(Customer::query()->count())->toBe(0);
});

it('rejects unavailable items and stale expected prices without creating anything', function () {
    moLogin('ADMIN');
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['items' => [['slug' => 'nope', 'productCode' => 'X', 'quantity' => 1]]]))->assertUnprocessable();
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2, 'expectedUnitPriceMinor' => 999]]]))->assertUnprocessable();
    expect(Order::query()->count())->toBe(0);
});

/** @param array<string, mixed> $overrides */
function moAttempt(array $overrides = []): CheckoutAttempt
{
    return CheckoutAttempt::query()->create(array_replace([
        'paystack_reference' => 'PSK-COLLIDE-1',
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233244000000', 'phoneDisplay' => '0244000000'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Tema', 'address' => 'X'],
        'items_snapshot_json' => [['lineId' => 'l1', 'lineType' => 'PRODUCT', 'quantity' => 2, 'unitPriceMinor' => 1300, 'lineTotalMinor' => 2600, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        'amount_minor' => 2600, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->addHour(),
        'verified_at' => now(), 'verification_snapshot_json' => ['status' => 'success', 'amount' => 2600],
    ], $overrides));
}

it('links an orderless attempt to a manual order with the same reference and makes late events a no-op', function () {
    moLogin('SALES_ORDERS');
    $attempt = moAttempt();
    expect(app(ListReconciliationAttempts::class)->query()->count())->toBe(1);

    $res = $this->postJson('/api/v1/admin/orders/manual', moPayload(['paymentReference' => 'psk-collide-1']))->assertCreated();
    $order = Order::query()->where('order_number', $res->json('data.orderNumber'))->sole();
    expect($order->checkout_attempt_id)->toBe($attempt->id)->and($order->source->value)->toBe('MANUAL')
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED)
        ->and(AuditLog::query()->where('action', 'checkout.attempt.linked')->count())->toBe(1)
        ->and(app(ListReconciliationAttempts::class)->query()->count())->toBe(0);

    // Late poll: fulfilled, no provider call.
    Http::preventStrayRequests();
    $this->getJson('/api/v1/checkout/PSK-COLLIDE-1')->assertOk()
        ->assertJsonPath('status', 'SUCCEEDED')->assertJsonPath('orderNumber', $order->order_number);

    // Late webhook + direct action calls: no exception, same order, no extra orders.
    $event = PaymentEvent::query()->create(['provider' => 'paystack', 'provider_event_id' => 'e1', 'event_type' => 'charge.success',
        'reference' => 'PSK-COLLIDE-1', 'signature_valid' => true, 'payload_redacted_json' => []]);
    (new ProcessPaystackEvent((int) $event->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));
    expect($event->fresh()->processed_at)->not->toBeNull()
        ->and(app(HandleChargeSuccess::class)->execute($attempt, ['status' => 'success'])?->id)->toBe($order->id)
        ->and(app(CreateOrderFromAttempt::class)->execute($attempt->fresh())->id)->toBe($order->id)
        ->and(Order::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('settles an unlinked attempt whose reference is already on an order without throwing', function () {
    moLogin('SALES_ORDERS');
    $res = $this->postJson('/api/v1/admin/orders/manual', moPayload(['paymentReference' => 'PSK-LATE-1']))->assertCreated();
    $attempt = moAttempt(['paystack_reference' => 'PSK-LATE-1']); // attempt created after the manual order
    $this->getJson('/api/v1/checkout/PSK-LATE-1')->assertOk()->assertJsonPath('status', 'SUCCEEDED')
        ->assertJsonPath('orderNumber', $res->json('data.orderNumber'));
    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED)
        ->and(Order::query()->sole()->checkout_attempt_id)->toBe($attempt->id);
});

it('flags an amount mismatch between the Paystack amount and the manual order and does not link', function () {
    moLogin('SALES_ORDERS');
    $attempt = moAttempt(['amount_minor' => 5000, 'verification_snapshot_json' => ['status' => 'success', 'amount' => 5000]]);
    $this->postJson('/api/v1/admin/orders/manual', moPayload(['paymentReference' => 'PSK-COLLIDE-1']))->assertCreated();
    expect(Order::query()->sole()->checkout_attempt_id)->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::MISMATCH)
        ->and(AuditLog::query()->where('action', 'checkout.mismatch.detected')->count())->toBe(1)
        ->and(app(ListReconciliationAttempts::class)->query()->count())->toBe(1);
    // A late event stays clean (no unique violation) and leaves the flag in place.
    expect(app(CreateOrderFromAttempt::class)->execute($attempt->fresh())->id)->toBe(Order::query()->sole()->id)
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::MISMATCH);
});

it('uses the canonical delivery area name for manual orders and keeps free text as typed', function () {
    DeliveryArea::query()->create(['name' => 'Tema', 'aliases_json' => [], 'is_active' => true, 'display_order' => 1]);
    moLogin('SALES_ORDERS');
    $a = $this->postJson('/api/v1/admin/orders/manual', moPayload(['area' => ' tEMA ', 'paymentReference' => 'A-1']))->assertCreated();
    $b = $this->postJson('/api/v1/admin/orders/manual', moPayload(['area' => 'Village near Ho', 'paymentReference' => 'A-2']))->assertCreated();
    expect($a->json('data.delivery.area'))->toBe('Tema')->and($b->json('data.delivery.area'))->toBe('Village near Ho');
});
