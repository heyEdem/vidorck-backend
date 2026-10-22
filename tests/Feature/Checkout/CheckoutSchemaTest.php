<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function schemaCustomer(array $overrides = []): Customer
{
    return Customer::query()->create(array_merge(['full_name' => 'Ama Mensah', 'phone_e164' => '+233241234567', 'phone_display' => '0241234567'], $overrides));
}

function schemaAttempt(array $overrides = []): CheckoutAttempt
{
    return CheckoutAttempt::query()->create(array_merge(['paystack_reference' => 'ref-'.Str::random(12), 'customer_snapshot_json' => ['name' => 'Ama'], 'delivery_snapshot_json' => ['area' => 'Osu'], 'items_snapshot_json' => [], 'amount_minor' => 1000, 'expires_at' => now()->addHour()], $overrides));
}

function schemaOrder(Customer $customer, array $overrides = []): Order
{
    return Order::query()->create(array_merge(['order_number' => 'VH-2026-'.Str::random(8), 'tracking_token' => Str::random(32), 'customer_id' => $customer->id, 'subtotal_minor' => 1000, 'total_paid_minor' => 1000, 'payment_method' => 'PAYSTACK', 'customer_snapshot_json' => ['name' => 'Ama'], 'delivery_snapshot_json' => ['area' => 'Osu'], 'paid_at' => now()], $overrides));
}

it('creates checkout and order tables with ULIDs, relations, and casts', function () {
    $customer = schemaCustomer();
    $attempt = schemaAttempt();
    $order = schemaOrder($customer, ['checkout_attempt_id' => $attempt->id, 'payment_reference' => 'payment-ref']);
    $item = $order->items()->create(['line_type' => 'PRODUCT', 'name_snapshot' => 'Hair Oil', 'product_code_snapshot' => 'OIL-001', 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000]);
    $order->statusEvents()->create(['to_status' => 'PAID', 'actor_type' => 'SYSTEM', 'created_at' => now()]);

    expect(Str::isUlid($customer->public_id))->toBeTrue()
        ->and(Str::isUlid($attempt->public_id))->toBeTrue()
        ->and($attempt->order->is($order))->toBeTrue()
        ->and($item->order->is($order))->toBeTrue()
        ->and(collect(['delivery_areas', 'checkout_attempts', 'payment_events', 'order_number_sequences', 'customers', 'orders', 'order_items', 'order_status_events'])->every(fn (string $table): bool => Schema::hasTable($table)))->toBeTrue();
});

it('enforces every schema unique key through direct insertion failures', function () {
    DeliveryArea::query()->create(['name' => 'Osu', 'aliases_json' => [], 'display_order' => 1]);
    expect(fn () => DB::table('delivery_areas')->insert(['name' => 'Osu', 'aliases_json' => '[]']))->toThrow(QueryException::class);

    $attempt = schemaAttempt();
    foreach ([['public_id' => $attempt->public_id, 'paystack_reference' => 'other'], ['public_id' => (string) Str::ulid(), 'paystack_reference' => $attempt->paystack_reference]] as $dupe) {
        expect(fn () => DB::table('checkout_attempts')->insert(array_merge(attemptRow(), $dupe)))->toThrow(QueryException::class);
    }

    $event = ['provider' => 'paystack', 'provider_event_id' => 'evt-1', 'event_type' => 'charge.success', 'reference' => 'ref', 'signature_valid' => true, 'payload_redacted_json' => '{}'];
    DB::table('payment_events')->insert($event);
    expect(fn () => DB::table('payment_events')->insert($event))->toThrow(QueryException::class);

    $customer = schemaCustomer();
    expect(fn () => DB::table('customers')->insert(array_merge(customerRow(), ['public_id' => $customer->public_id])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('customers')->insert(array_merge(customerRow(), ['phone_e164' => $customer->phone_e164])))->toThrow(QueryException::class);

    DB::table('order_number_sequences')->insert(['year' => 2026, 'last_value' => 1]);
    expect(fn () => DB::table('order_number_sequences')->insert(['year' => 2026, 'last_value' => 2]))->toThrow(QueryException::class);

    $first = schemaOrder($customer, ['checkout_attempt_id' => $attempt->id, 'payment_reference' => 'pay-ref']);
    foreach ([['order_number' => $first->order_number], ['tracking_token' => $first->tracking_token], ['checkout_attempt_id' => $attempt->id], ['payment_reference' => 'pay-ref']] as $dupe) {
        expect(fn () => DB::table('orders')->insert(array_merge(orderRow($customer), $dupe)))->toThrow(QueryException::class);
    }
});

it('enforces restrict foreign keys, nonnegative money, and positive quantities', function () {
    $customer = schemaCustomer();
    $attempt = schemaAttempt();
    $order = schemaOrder($customer, ['checkout_attempt_id' => $attempt->id]);
    expect(fn () => $customer->delete())->toThrow(QueryException::class)
        ->and(fn () => $attempt->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['total_paid_minor' => -1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('checkout_attempts')->where('id', $attempt->id)->update(['amount_minor' => -1]))->toThrow(QueryException::class)
        ->and(fn () => $order->items()->create(['line_type' => 'PRODUCT', 'name_snapshot' => 'Bad', 'product_code_snapshot' => 'BAD', 'quantity' => 0, 'unit_price_minor' => 1, 'line_total_minor' => 0]))->toThrow(QueryException::class)
        ->and(fn () => $order->items()->create(['line_type' => 'PRODUCT', 'name_snapshot' => 'Bad', 'product_code_snapshot' => 'BAD', 'quantity' => 1, 'unit_price_minor' => -1, 'line_total_minor' => 0]))->toThrow(QueryException::class);
});

it('enforces currency enums and financial guards on raw inserts and updates', function (): void {
    $customer = schemaCustomer();
    $attempt = schemaAttempt();
    $order = schemaOrder($customer, ['checkout_attempt_id' => $attempt->id]);
    $item = $order->items()->create(['line_type' => 'PRODUCT', 'name_snapshot' => 'Hair Oil', 'product_code_snapshot' => 'OIL-001', 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000]);

    expect(fn () => DB::table('checkout_attempts')->insert(array_merge(attemptRow(), ['status' => 'UNKNOWN'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('checkout_attempts')->insert(array_merge(attemptRow(), ['currency' => 'USD'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('checkout_attempts')->insert(array_merge(attemptRow(), ['amount_minor' => -1])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('checkout_attempts')->where('id', $attempt->id)->update(['status' => 'UNKNOWN']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('checkout_attempts')->where('id', $attempt->id)->update(['currency' => 'USD']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('checkout_attempts')->where('id', $attempt->id)->update(['amount_minor' => -1]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('customers')->insert(array_merge(customerRow(), ['lifetime_paid_minor' => -1])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('customers')->where('id', $customer->id)->update(['lifetime_paid_minor' => -1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->insert(array_merge(orderRow($customer), ['currency' => 'USD'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->insert(array_merge(orderRow($customer), ['status' => 'UNKNOWN'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->insert(array_merge(orderRow($customer), ['total_paid_minor' => -1])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['currency' => 'USD']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['status' => 'UNKNOWN']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['subtotal_minor' => -1]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('order_items')->insert(array_merge(itemRow($order), ['line_type' => 'UNKNOWN'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->insert(array_merge(itemRow($order), ['unit_price_minor' => -1])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->insert(array_merge(itemRow($order), ['quantity' => 0])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->where('id', $item->id)->update(['line_type' => 'UNKNOWN']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->where('id', $item->id)->update(['line_total_minor' => -1]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->where('id', $item->id)->update(['quantity' => 0]))
        ->toThrow(QueryException::class);
});

it('seeds 48 delivery areas idempotently without replacing admin edits', function () {
    $seeder = app(DeliveryAreaSeeder::class);
    $seeder->run();
    $seeder->run();
    $area = DeliveryArea::query()->where('name', 'Osu')->sole();
    $area->update(['is_active' => false, 'display_order' => 91, 'aliases_json' => ['Oxford Street']]);
    $seeder->run();
    expect(DeliveryArea::query()->count())->toBe(48)
        ->and($area->fresh()->is_active)->toBeFalse()
        ->and($area->fresh()->display_order)->toBe(91)
        ->and($area->fresh()->aliases_json)->toBe(['Oxford Street']);
});

function attemptRow(): array
{
    return ['public_id' => (string) Str::ulid(), 'paystack_reference' => 'ref-'.Str::random(12), 'customer_snapshot_json' => '{}', 'delivery_snapshot_json' => '{}', 'items_snapshot_json' => '[]', 'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now(), 'created_at' => now(), 'updated_at' => now()];
}
function customerRow(): array
{
    return ['public_id' => (string) Str::ulid(), 'full_name' => 'Ama', 'phone_e164' => '+233'.random_int(200000000, 599999999), 'phone_display' => '0241234567', 'orders_count' => 0, 'lifetime_paid_minor' => 0, 'created_at' => now(), 'updated_at' => now()];
}
function orderRow(Customer $customer): array
{
    return ['order_number' => 'VH-'.Str::random(12), 'tracking_token' => Str::random(32), 'source' => 'ONLINE', 'customer_id' => $customer->id, 'status' => 'PAID', 'subtotal_minor' => 1000, 'total_paid_minor' => 1000, 'currency' => 'GHS', 'payment_method' => 'PAYSTACK', 'customer_snapshot_json' => '{}', 'delivery_snapshot_json' => '{}', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()];
}

function itemRow(Order $order): array
{
    return ['order_id' => $order->id, 'line_type' => 'PRODUCT', 'name_snapshot' => 'Hair Oil', 'product_code_snapshot' => 'OIL-001', 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000, 'created_at' => now(), 'updated_at' => now()];
}
