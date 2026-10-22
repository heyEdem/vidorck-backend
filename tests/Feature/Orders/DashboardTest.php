<?php

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Enums\NotificationAttemptEvent;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Repositories\NotificationAttemptRepository;
use App\Modules\Orders\Actions\BuildDashboard;
use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Enums\RefundTransactionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

const DB_PASSWORD = 'Long-Staff-Password9!';

function dbLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => DB_PASSWORD])->assertOk();
}

function dbOrder(string $paidAt, int $total = 1000, OrderStatus $status = OrderStatus::PAID): Order
{
    static $n = 0;
    $n++;
    $customer = Customer::query()->firstOrCreate(['phone_e164' => '+233241234567'], ['full_name' => 'Ama', 'phone_display' => '0241234567']);
    $order = Order::query()->create([
        'order_number' => sprintf('VHI-2026-%06d', $n), 'tracking_token' => Str::random(32), 'source' => OrderSource::ONLINE,
        'customer_id' => $customer->id, 'status' => OrderStatus::PAID, 'subtotal_minor' => $total, 'total_paid_minor' => $total,
        'currency' => 'GHS', 'payment_method' => 'MOMO', 'payment_reference' => 'dash-'.Str::random(10),
        'customer_snapshot_json' => [], 'delivery_snapshot_json' => [], 'paid_at' => CarbonImmutable::parse($paidAt, 'UTC'),
    ]);
    $steps = [OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED, OrderStatus::DELIVERED];
    $staff = StaffUser::query()->where('role', 'ADMIN')->firstOrFail();
    $machine = app(OrderStateMachine::class);
    foreach ($steps as $step) {
        if ($status === OrderStatus::PAID || $order->status === $status) {
            break;
        }
        $order = $machine->transition($order, $step, $staff);
    }

    return $order;
}

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::create(['name' => ucfirst(strtolower($role->value)), 'email' => strtolower($role->value).'@example.com', 'password' => DB_PASSWORD, 'role' => $role]);
    }
});

afterEach(fn () => Carbon::setTestNow());

it('requires authentication', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
});

it('includes only dashboard groups permitted to each role', function () {
    $groups = [
        'orders' => ['paidOrders', 'paidOrdersToday', 'processingCount', 'readyForDispatchCount', 'dispatchedCount'],
        'sales' => ['salesMinor', 'salesTodayMinor', 'currency'],
        'refunds' => ['openRefundRequests'],
        'reconciliation' => ['reconciliationCount', 'refundReconciliationCount', 'failedNotificationCount'],
        'complaints' => ['newComplaints', 'complaintsWaiting'],
    ];

    foreach (['ADMIN', 'SALES_ORDERS', 'CUSTOMER_SERVICE'] as $role) {
        dbLogin($role);
        $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $expectedGroups = match ($role) {
            'ADMIN' => ['orders', 'sales', 'refunds', 'reconciliation', 'complaints'],
            'SALES_ORDERS' => ['orders', 'refunds'],
            'CUSTOMER_SERVICE' => ['orders', 'refunds', 'complaints'],
        };

        foreach ($groups as $group => $keys) {
            foreach ($keys as $key) {
                expect(array_key_exists($key, $data))->toBe(in_array($group, $expectedGroups, true), $role.' '.$key);
            }
        }

        expect($data)->toHaveKeys(['date', 'timezone', 'period', 'startDate', 'endDate']);
        expect(array_key_exists('newComplaints', $data))->toBe(in_array('complaints', $expectedGroups, true))
            ->and(array_key_exists('complaintsWaiting', $data))->toBe(in_array('complaints', $expectedGroups, true));
    }
});

it('uses the Africa/Accra day boundary for today, including both edges', function () {
    Carbon::setTestNow('2026-03-10 23:59:59');
    dbOrder('2026-03-09 23:59:59', 100);  // yesterday, last second
    dbOrder('2026-03-10 00:00:00', 200);  // today, first second
    dbOrder('2026-03-10 23:59:59', 400);  // today, last second
    dbOrder('2026-03-11 00:00:00', 800);  // tomorrow, first second
    dbLogin('SALES_ORDERS');
    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.date', '2026-03-10')->assertJsonPath('data.timezone', 'Africa/Accra')
        ->assertJsonPath('data.paidOrdersToday', 2)->assertJsonMissingPath('data.salesTodayMinor')->assertJsonMissingPath('data.currency');

    Carbon::setTestNow('2026-03-11 00:00:00');
    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.date', '2026-03-11')->assertJsonPath('data.paidOrdersToday', 1)->assertJsonMissingPath('data.salesTodayMinor');
});

it('derives the business day from Accra regardless of the caller timezone', function () {
    dbOrder('2026-03-11 02:00:00', 500);
    dbOrder('2026-03-10 23:00:00', 300);
    // 23:30 on 10 March in New York is 04:30 UTC on 11 March, so Accra is already on the 11th.
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN)->firstOrFail();
    $summary = app(BuildDashboard::class)->execute(CarbonImmutable::parse('2026-03-10 23:30:00', 'America/New_York'), staff: $admin);
    expect($summary['date'])->toBe('2026-03-11')->and($summary['paidOrdersToday'])->toBe(1)->and($summary['salesTodayMinor'])->toBe(500);
});

it('counts fulfilment stages, open refunds, reconciliation and defers failed notifications', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    dbOrder('2026-03-01 10:00:00', 100, OrderStatus::PROCESSING);
    dbOrder('2026-03-01 10:00:00', 100, OrderStatus::PROCESSING);
    dbOrder('2026-03-01 10:00:00', 100, OrderStatus::READY_FOR_DISPATCH);
    dbOrder('2026-03-01 10:00:00', 100, OrderStatus::DISPATCHED);
    dbOrder('2026-03-01 10:00:00', 100, OrderStatus::DELIVERED);
    dbOrder('2026-03-01 10:00:00', 100);
    $orders = Order::query()->orderBy('id')->get();
    foreach (['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'PROCESSING', 'COMPLETED', 'REJECTED'] as $i => $status) {
        RefundRequest::query()->create(['order_id' => $orders[$i]->id, 'channel' => 'STAFF', 'reason' => 'x', 'status' => $status]);
    }
    CheckoutAttempt::query()->create([
        'paystack_reference' => 'mm-1', 'customer_snapshot_json' => [], 'delivery_snapshot_json' => [], 'items_snapshot_json' => [],
        'amount_minor' => 100, 'currency' => 'GHS', 'status' => 'MISMATCH', 'expires_at' => now()->addHour(),
    ]);
    foreach (['SALES_ORDERS', 'ADMIN'] as $role) {
        dbLogin($role);
        $dashboard = $this->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.processingCount', 2)->assertJsonPath('data.readyForDispatchCount', 1)
            ->assertJsonPath('data.dispatchedCount', 1)->assertJsonPath('data.openRefundRequests', 4)
            ->assertJsonPath('data.paidOrdersToday', 0);
        if ($role === 'ADMIN') {
            $dashboard->assertJsonPath('data.salesTodayMinor', 0)->assertJsonPath('data.reconciliationCount', 1)
                ->assertJsonPath('data.failedNotificationCount', 0);
        } else {
            $dashboard->assertJsonMissingPath('data.salesTodayMinor')->assertJsonMissingPath('data.currency')
                ->assertJsonMissingPath('data.reconciliationCount')->assertJsonMissingPath('data.failedNotificationCount');
        }
    }
});

it('excludes cancelled and refunded orders from today sales', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    dbOrder('2026-03-10 09:00:00', 300);
    $machine = app(OrderStateMachine::class);
    $machine->transitionForRefund(dbOrder('2026-03-10 09:30:00', 900), OrderStatus::CANCELLED);
    $machine->transitionForRefund(dbOrder('2026-03-10 09:40:00', 700), OrderStatus::REFUNDED);
    dbLogin('ADMIN');
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.paidOrdersToday', 1)->assertJsonPath('data.salesTodayMinor', 300);
});

it('counts notifications whose latest attempt is failed or exhausted', function () {
    $repo = app(NotificationAttemptRepository::class);
    foreach (['a', 'b', 'c'] as $k) {
        NotificationLog::query()->create([
            'idempotency_key' => $k, 'channel' => 'EMAIL', 'template' => 't', 'recipient_masked' => 'a***@x.com',
            'recipient_hash' => str_repeat('a', 64), 'created_at' => now(),
        ]);
    }
    $ids = NotificationLog::query()->orderBy('id')->pluck('id');
    $repo->append($ids[0], 1, NotificationAttemptEvent::FAILED);
    $repo->append($ids[1], 1, NotificationAttemptEvent::EXHAUSTED);
    $repo->append($ids[2], 1, NotificationAttemptEvent::FAILED);
    $repo->append($ids[2], 2, NotificationAttemptEvent::SENT);
    dbLogin('ADMIN');
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.failedNotificationCount', 2);
});

it('filters sales by calendar period with inclusive dates and exclusive next midnight', function (string $period, string $from, string $to) {
    Carbon::setTestNow('2026-03-11 12:00:00');
    dbOrder(CarbonImmutable::parse($from)->subSecond()->toDateTimeString(), 100);
    dbOrder($from.' 00:00:00', 200);
    dbOrder($to.' 23:59:59', 400);
    dbOrder(CarbonImmutable::parse($to)->addDay()->toDateTimeString(), 800);
    $machine = app(OrderStateMachine::class);
    $machine->transitionForRefund(dbOrder($from.' 09:00:00', 1600), OrderStatus::CANCELLED);
    $machine->transitionForRefund(dbOrder($from.' 10:00:00', 3200), OrderStatus::REFUNDED);
    dbLogin('ADMIN');
    $query = ['period' => $period];
    if ($period === 'custom') {
        $query += ['from' => $from, 'to' => $to];
    }
    $this->getJson('/api/v1/admin/dashboard?'.http_build_query($query))->assertOk()
        ->assertJsonPath('data.period', $period)->assertJsonPath('data.startDate', $from)->assertJsonPath('data.endDate', $to)
        ->assertJsonPath('data.paidOrders', 2)->assertJsonPath('data.salesMinor', 600)
        ->assertJsonPath('data.paidOrdersToday', 0)->assertJsonPath('data.salesTodayMinor', 0);
})->with([
    'Monday through Sunday' => ['week', '2026-03-09', '2026-03-15'],
    'calendar month' => ['month', '2026-03-01', '2026-03-31'],
    'calendar year' => ['year', '2026-01-01', '2026-12-31'],
    'custom range' => ['custom', '2026-02-27', '2026-03-02'],
    'single custom day' => ['custom', '2026-03-08', '2026-03-08'],
]);

it('defaults to today and gives both dashboard roles period totals', function (string $role) {
    Carbon::setTestNow('2026-03-11 12:00:00');
    dbOrder('2026-03-11 10:00:00', 500);
    dbOrder('2026-03-10 10:00:00', 300, OrderStatus::PROCESSING);
    dbLogin($role);
    $today = $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.period', 'today')->assertJsonPath('data.startDate', '2026-03-11')
        ->assertJsonPath('data.endDate', '2026-03-11')->assertJsonPath('data.paidOrders', 1);
    $week = $this->getJson('/api/v1/admin/dashboard?period=week')->assertOk()
        ->assertJsonPath('data.paidOrders', 2)->assertJsonPath('data.paidOrdersToday', 1)
        ->assertJsonPath('data.processingCount', 1);
    if ($role === 'ADMIN') {
        $today->assertJsonPath('data.salesMinor', 500);
        $week->assertJsonPath('data.salesMinor', 800)->assertJsonPath('data.salesTodayMinor', 500);
    } else {
        $today->assertJsonMissingPath('data.salesMinor');
        $week->assertJsonMissingPath('data.salesMinor')->assertJsonMissingPath('data.salesTodayMinor');
    }
})->with(['ADMIN', 'SALES_ORDERS']);

it('returns zero for a range without payments while keeping current fulfilment counts', function () {
    dbOrder('2026-03-10 10:00:00', 300, OrderStatus::PROCESSING);
    dbLogin('ADMIN');
    $this->getJson('/api/v1/admin/dashboard?period=custom&from=2020-01-01&to=2020-01-31')->assertOk()
        ->assertJsonPath('data.paidOrders', 0)->assertJsonPath('data.salesMinor', 0)->assertJsonPath('data.processingCount', 1);
});

it('nets cash by processed refund date and keeps refunded paid orders in the range count and average', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    $order = dbOrder('2026-03-10 09:00:00', 1000);
    $refund = RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'STAFF', 'reason' => 'Refund', 'status' => 'COMPLETED']);
    RefundTransaction::query()->create(['refund_request_id' => $refund->id, 'method' => RefundMethod::MANUAL,
        'amount_minor' => 300, 'status' => RefundTransactionStatus::PROCESSED, 'created_at' => CarbonImmutable::parse('2026-03-10 10:00:00')]);
    app(OrderStateMachine::class)->transitionForRefund($order, OrderStatus::REFUNDED);
    dbLogin('ADMIN');
    $this->getJson('/api/v1/admin/dashboard?period=custom&from=2026-03-10&to=2026-03-10')->assertOk()
        ->assertJsonPath('data.range.current.receivedMinor', 1000)
        ->assertJsonPath('data.range.current.refundsMinor', 300)
        ->assertJsonPath('data.range.current.refundCount', 1)
        ->assertJsonPath('data.range.current.netSalesMinor', 700)
        ->assertJsonPath('data.range.current.paidOrders', 1)
        ->assertJsonPath('data.range.current.averageOrderValueMinor', 1000);
});

it('omits revenue, customer and reconciliation details when the role lacks those permissions', function () {
    dbOrder('2026-03-10 09:00:00', 1000);
    CheckoutAttempt::query()->create(['paystack_reference' => 'success-no-order', 'customer_snapshot_json' => [], 'delivery_snapshot_json' => [],
        'items_snapshot_json' => [], 'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'SUCCEEDED', 'expires_at' => now()->addHour(), 'verified_at' => now()]);
    CheckoutAttempt::query()->create(['paystack_reference' => 'pending-verified', 'customer_snapshot_json' => [], 'delivery_snapshot_json' => [],
        'items_snapshot_json' => [], 'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->addHour(), 'verified_at' => now()]);
    dbLogin('CUSTOMER_SERVICE');
    $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    expect(array_key_exists('range', $data))->toBeFalse()
        ->and(array_key_exists('reconciliationCount', $data))->toBeFalse()
        ->and(array_key_exists('failedNotificationCount', $data))->toBeFalse()
        ->and(array_key_exists('completedMinor', $data['refunds']))->toBeFalse()
        ->and(array_key_exists('sourceChannels', $data['catalogue']))->toBeFalse()
        ->and(array_key_exists('amountMinor', $data['catalogue']['bestSellers'][0] ?? []))->toBeFalse()
        ->and(collect($data['attentionQueue'])->contains(fn ($row) => $row['kind'] === 'payment'))->toBeFalse();
});

it('compares running month dates to the prior month without overlapping current dates', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    dbOrder('2026-02-01 10:00:00', 500);
    dbOrder('2026-02-10 10:00:00', 600);
    dbOrder('2026-03-01 10:00:00', 700);
    dbOrder('2026-03-10 10:00:00', 800);
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN)->firstOrFail();
    $data = app(BuildDashboard::class)->execute(CarbonImmutable::parse('2026-03-10 12:00:00'), 'month', staff: $admin);
    expect($data['range']['current']['startDate'])->toBe('2026-03-01')
        ->and($data['range']['current']['endDate'])->toBe('2026-03-10')
        ->and($data['range']['previous']['startDate'])->toBe('2026-02-01')
        ->and($data['range']['previous']['endDate'])->toBe('2026-02-10')
        ->and($data['range']['previous']['paidOrders'])->toBe(2);
});

it('returns a null comparison when sales exist only outside the prior elapsed range', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    dbOrder('2026-02-01 10:00:00', 500);
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN)->firstOrFail();
    $data = app(BuildDashboard::class)->execute(CarbonImmutable::parse('2026-03-10 12:00:00'), 'today', staff: $admin);
    expect($data['range']['previous'])->toBeNull();
});

it('uses latest status history for queue age and keeps stale counts independent of queue caps', function () {
    $now = CarbonImmutable::parse('2026-03-12 12:00:00', 'UTC');
    Carbon::setTestNow($now->subHours(50));
    $orders = [];
    for ($i = 0; $i < 9; $i++) {
        $orders[] = dbOrder('2026-03-10 10:00:00', 100, OrderStatus::PROCESSING);
    }
    $recent = dbOrder('2026-03-10 10:00:00', 100, OrderStatus::PROCESSING);
    $staff = StaffUser::query()->where('role', StaffRole::ADMIN)->firstOrFail();
    Carbon::setTestNow($now->subHour());
    app(OrderStateMachine::class)->transition($recent, OrderStatus::READY_FOR_DISPATCH, $staff);
    $recent->forceFill(['updated_at' => $now->subDays(10)])->save();
    Carbon::setTestNow($now);
    dbLogin('ADMIN');
    $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    $recentRow = collect($data['attentionQueue'])->firstWhere('id', $recent->id);
    expect($recentRow['ageHours'])->toBe(1)->and($recentRow['stale'])->toBeFalse()
        ->and(collect($data['attentionQueue'])->where('kind', 'order')->count())->toBe(10)
        ->and($data['attentionSummary']['staleCount'])->toBe(9);
    Carbon::setTestNow($now->subHours(50));
    for ($i = 0; $i < 11; $i++) {
        dbOrder('2026-03-10 10:00:00', 100, OrderStatus::PROCESSING);
    }
    Carbon::setTestNow($now);
    $capped = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    expect(collect($capped['attentionQueue'])->where('kind', 'order')->count())->toBe(10)
        ->and($capped['attentionSummary']['staleCount'])->toBe(20);
});

it('groups product variants by product and keeps distinct bundles separate in catalogue analytics', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    $category = Category::query()->create(['name' => 'Hair', 'slug' => 'hair', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Curl Cream', 'slug' => 'curl-cream', 'category_id' => $category->id, 'is_published' => true]);
    $unsold = Product::query()->create(['name' => 'Leave In', 'slug' => 'leave-in', 'category_id' => $category->id, 'is_published' => true]);
    $small = ProductVariant::query()->create(['product_id' => $product->id, 'size_label' => 'Small', 'product_code' => 'CC-S', 'price_minor' => 1000]);
    $large = ProductVariant::query()->create(['product_id' => $product->id, 'size_label' => 'Large', 'product_code' => 'CC-L', 'price_minor' => 2000]);
    $bundleA = Bundle::query()->create(['name' => 'Wash Set', 'slug' => 'wash-set', 'product_code' => 'WS', 'price_minor' => 3000, 'is_published' => true]);
    $bundleB = Bundle::query()->create(['name' => 'Style Set', 'slug' => 'style-set', 'product_code' => 'SS', 'price_minor' => 4000, 'is_published' => true]);
    $order = dbOrder('2026-03-10 09:00:00', 10000);
    foreach ([[$small->id, null, 'PRODUCT', 'Curl Cream', 1000], [$large->id, null, 'PRODUCT', 'Curl Cream', 2000],
        [null, $bundleA->id, 'BUNDLE', 'Wash Set', 3000], [null, $bundleB->id, 'BUNDLE', 'Style Set', 4000]] as [$variantId, $bundleId, $type, $name, $amount]) {
        OrderItem::query()->create(['order_id' => $order->id, 'line_type' => $type, 'product_variant_id' => $variantId, 'bundle_id' => $bundleId,
            'name_snapshot' => $name, 'product_code_snapshot' => $name, 'quantity' => 1, 'unit_price_minor' => $amount, 'line_total_minor' => $amount]);
    }
    dbLogin('ADMIN');
    $catalogue = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.catalogue');
    $productRows = collect($catalogue['bestSellers'])->where('catalogueType', 'product');
    $bundleRows = collect($catalogue['bestSellers'])->where('catalogueType', 'bundle');
    expect($catalogue['bestSellersTotal'])->toBe(3)
        ->and($productRows->count())->toBe(1)
        ->and($productRows->first()['productId'])->toBe($product->id)
        ->and($bundleRows->pluck('bundleId')->sort()->values()->all())->toBe([$bundleA->id, $bundleB->id])
        ->and($catalogue['sourceChannels'])->toBe([['source' => 'ONLINE', 'count' => 1, 'salesMinor' => 10000]])
        ->and(collect($catalogue['unsold'])->pluck('productId')->contains($unsold->id))->toBeTrue();
});

it('measures delivery duration from immutable status history in the selected completion period', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $order = dbOrder('2026-03-01 10:00:00');
    $staff = StaffUser::query()->where('role', StaffRole::ADMIN)->firstOrFail();
    $machine = app(OrderStateMachine::class);
    Carbon::setTestNow('2026-03-01 11:00:00');
    $order = $machine->transition($order, OrderStatus::PROCESSING, $staff);
    Carbon::setTestNow('2026-03-01 12:00:00');
    $order = $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, $staff);
    Carbon::setTestNow('2026-03-01 13:00:00');
    $order = $machine->transition($order, OrderStatus::DISPATCHED, $staff);
    Carbon::setTestNow('2026-03-10 10:00:00');
    $machine->transition($order, OrderStatus::DELIVERED, $staff);
    dbLogin('ADMIN');
    $this->getJson('/api/v1/admin/dashboard?period=custom&from=2026-03-10&to=2026-03-10')->assertOk()
        ->assertJsonPath('data.fulfillment.deliveredCount', 1)
        ->assertJsonPath('data.fulfillment.averageHoursToDelivered', 216)
        ->assertJsonPath('data.fulfillment.averageHoursToDispatched', 3)
        ->assertJsonPath('data.fulfillmentComparison.deliveredCount', 0);
});

it('includes persisted successful verifications with no order and excludes failed verified attempts', function () {
    foreach ([['unmatched-success', 'SUCCEEDED'], ['failed-verified', 'FAILED'], ['mismatch', 'MISMATCH'], ['pending-verified', 'PENDING'],
        ['confirmed-but-uncommitted', 'PENDING']] as [$reference, $status]) {
        CheckoutAttempt::query()->create(['paystack_reference' => $reference, 'customer_snapshot_json' => [], 'delivery_snapshot_json' => [],
            'items_snapshot_json' => [], 'amount_minor' => 1000, 'currency' => 'GHS', 'status' => $status, 'expires_at' => now()->addHour(),
            'verified_at' => in_array($status, ['SUCCEEDED', 'FAILED', 'PENDING'], true) ? now() : null,
            'verification_snapshot_json' => $reference === 'confirmed-but-uncommitted' ? ['status' => 'success'] : null]);
    }
    dbLogin('ADMIN');
    $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    expect(collect($data['attentionQueue'])->where('kind', 'payment')->count())->toBe(2)
        ->and(collect($data['attentionQueue'])->where('kind', 'mismatch')->count())->toBe(1)
        ->and($data['checkoutOutcomes']['unmatchedPayment'])->toBe(2)
        ->and($data['checkoutOutcomes']['mismatch'])->toBe(1);
});

it('handles calendar rollovers, leap days and Sunday week boundaries', function (string $now, string $period, string $from, string $to) {
    $admin = StaffUser::query()->where('role', StaffRole::ADMIN)->firstOrFail();
    $summary = app(BuildDashboard::class)->execute(CarbonImmutable::parse($now), $period, staff: $admin);
    expect($summary['startDate'])->toBe($from)->and($summary['endDate'])->toBe($to);
})->with([
    ['2026-03-15', 'week', '2026-03-09', '2026-03-15'],
    ['2027-01-01', 'week', '2026-12-28', '2027-01-03'],
    ['2028-02-29', 'month', '2028-02-01', '2028-02-29'],
    ['2027-01-01', 'year', '2027-01-01', '2027-12-31'],
]);

it('rejects invalid dashboard filters', function (array $query, array $errors) {
    dbLogin('ADMIN');
    $this->getJson('/api/v1/admin/dashboard?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($errors);
})->with([
    [['period' => 'invalid'], ['period']],
    [['period' => ['week']], ['period']],
    [['period' => 'custom'], ['from', 'to']],
    [['period' => 'custom', 'from' => '2026-03-01'], ['to']],
    [['period' => 'custom', 'from' => '2026-03-11', 'to' => '2026-03-10'], ['to']],
    [['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-10'], ['from']],
    [['period' => 'custom', 'from' => 'yesterday', 'to' => 'today'], ['from', 'to']],
    [['period' => 'week', 'from' => '2026-03-01'], ['from']],
    [['from' => '2026-03-01', 'to' => '2026-03-02'], ['from', 'to']],
]);

it('rejects custom dashboard dates after the current Accra date', function () {
    dbLogin('ADMIN');
    $tomorrow = CarbonImmutable::now(BuildDashboard::TIMEZONE)->addDay()->toDateString();
    $this->getJson('/api/v1/admin/dashboard?'.http_build_query(['period' => 'custom', 'from' => $tomorrow, 'to' => $tomorrow]))
        ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
});
