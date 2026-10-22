<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNumberSequence;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function paidAttempt(array $overrides = []): CheckoutAttempt
{
    $publicId = (string) Str::ulid();
    $amount = 3500;

    return CheckoutAttempt::query()->create(array_merge([
        'public_id' => $publicId, 'paystack_reference' => 'ref-'.Str::random(15),
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'ama@example.test'],
        'delivery_snapshot_json' => ['area' => 'Osu', 'address' => '1 Main Road'],
        'items_snapshot_json' => [[
            'slug' => 'extra', 'lineType' => 'BUNDLE_EXTRA', 'lineId' => null, 'parentLineId' => 'bundle-line',
            'productVariantId' => null, 'bundleId' => null, 'name' => 'Extra Oil', 'size' => '100ml',
            'productCode' => 'OIL', 'imageRef' => null, 'bundleContents' => null,
            'quantity' => 1, 'unitPriceMinor' => 500, 'lineTotalMinor' => 500,
        ], [
            'slug' => 'bundle', 'lineType' => 'BUNDLE', 'lineId' => 'bundle-line', 'parentLineId' => null,
            'productVariantId' => null, 'bundleId' => null, 'name' => 'Original Set', 'size' => null,
            'productCode' => 'SET-1', 'imageRef' => 'old/image.jpg',
            'bundleContents' => [['productVariantId' => 8888, 'name' => 'Oil', 'size' => '100ml', 'productCode' => 'OIL', 'quantity' => 2]],
            'quantity' => 1, 'unitPriceMinor' => 3000, 'lineTotalMinor' => 3000,
        ]],
        'amount_minor' => $amount, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(),
        'verified_at' => now(), 'verification_snapshot_json' => [
            'status' => 'success', 'reference' => null, 'amount' => $amount, 'currency' => 'GHS',
            'metadata' => ['attemptId' => $publicId], 'channel' => 'mobile_money', 'paid_at' => '2026-04-02T10:00:00.000Z',
        ],
    ], $overrides, ['verification_snapshot_json' => array_merge([
        'status' => 'success', 'reference' => 'ref-placeholder', 'amount' => $amount, 'currency' => 'GHS',
        'metadata' => ['attemptId' => $publicId], 'channel' => 'mobile_money', 'paid_at' => '2026-04-02T10:00:00.000Z',
    ], $overrides['verification_snapshot_json'] ?? [])]));
}

function setVerificationReference(CheckoutAttempt $attempt): CheckoutAttempt
{
    $snapshot = $attempt->verification_snapshot_json;
    $snapshot['reference'] = $attempt->paystack_reference;
    $attempt->forceFill(['verification_snapshot_json' => $snapshot])->save();

    return $attempt->fresh();
}

it('requires provider verification before creating any order state', function () {
    $attempt = paidAttempt(['verified_at' => null]);
    $attempt->forceFill(['verification_snapshot_json' => null])->save();

    expect(fn () => app(CreateOrderFromAttempt::class)->execute($attempt))->toThrow(InvalidArgumentException::class)
        ->and(Order::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(OrderNumberSequence::query()->count())->toBe(0);
});

it('creates snapshot order once, accepts late success, links extras, and returns existing order for stale attempt copies', function () {
    $attempt = setVerificationReference(paidAttempt(['status' => 'ABANDONED']));
    $stale = new CheckoutAttempt;
    $stale->setRawAttributes($attempt->getAttributes());
    $order = app(CreateOrderFromAttempt::class)->execute($attempt);
    $again = app(CreateOrderFromAttempt::class)->execute($stale);

    expect($again->is($order))->toBeTrue()
        ->and($order->order_number)->toBe('VHI-2026-000001')
        ->and($order->source->value)->toBe('ONLINE')
        ->and($order->payment_method)->toBe('MOBILE_MONEY')
        ->and($order->paid_at->toISOString())->toStartWith('2026-04-02T10:00:00')
        ->and($order->items)->toHaveCount(2)
        ->and($order->items[1]->name_snapshot)->toBe('Original Set')
        ->and($order->items[1]->bundle_contents_json[0]['name'])->toBe('Oil')
        ->and($order->items[0]->parent_item_id)->toBe($order->items[1]->id)
        ->and($attempt->fresh()->status->value)->toBe('SUCCEEDED')
        ->and(Customer::query()->sole()->orders_count)->toBe(1)
        ->and(Customer::query()->sole()->lifetime_paid_minor)->toBe(3500)
        ->and(OrderStatusEvent::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'order.paid')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'order.paid')->sole()->after)->toMatchArray([
            'status' => 'PAID', 'orderNumber' => 'VHI-2026-000001', 'source' => 'ONLINE',
            'totalPaidMinor' => 3500, 'currency' => 'GHS', 'checkoutAttemptId' => $attempt->id,
        ])
        ->and(OrderNumberSequence::query()->sole()->last_value)->toBe(1);
});

it('rejects mismatched provider verification without writes', function () {
    $attempt = paidAttempt(['verification_snapshot_json' => ['status' => 'success', 'reference' => 'other', 'amount' => 3500, 'currency' => 'GHS', 'metadata' => ['attemptId' => 'wrong']]]);

    expect(fn () => app(CreateOrderFromAttempt::class)->execute($attempt))->toThrow(InvalidArgumentException::class)
        ->and(Order::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});

it('keeps order status events append-only through models and direct SQL', function () {
    $attempt = setVerificationReference(paidAttempt());
    $order = app(CreateOrderFromAttempt::class)->execute($attempt);
    $event = $order->statusEvents()->sole();
    $guardMigration = require database_path('migrations/2026_10_10_120000_make_order_status_events_append_only.php');
    $guardMigration->down();
    $guardMigration->up();
    $guardMigration->up();

    expect(fn () => $event->update(['reason' => 'rewrite']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('order_status_events')->where('id', $event->id)->update(['reason' => 'rewrite']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_status_events')->where('id', $event->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_status_events')->truncate())->toThrow(QueryException::class);
});

it('rolls back customer, sequence, order, and attempt changes when item insertion fails', function () {
    $attempt = setVerificationReference(paidAttempt());
    DB::unprepared("CREATE TRIGGER fail_order_item_insert BEFORE INSERT ON order_items BEGIN SELECT RAISE(ABORT, 'injected failure'); END");

    expect(fn () => app(CreateOrderFromAttempt::class)->execute($attempt))->toThrow(QueryException::class)
        ->and(Order::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(OrderNumberSequence::query()->count())->toBe(0)
        ->and(OrderStatusEvent::query()->count())->toBe(0)
        ->and($attempt->fresh()->status->value)->toBe('PENDING');
});

it('rolls back the paid order transaction when its audit append fails while retaining verification evidence', function (): void {
    $attempt = setVerificationReference(paidAttempt());
    DB::unprepared("CREATE TRIGGER fail_order_paid_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'order.paid' BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");

    expect(fn () => app(CreateOrderFromAttempt::class)->execute($attempt))->toThrow(QueryException::class)
        ->and(Order::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(OrderNumberSequence::query()->count())->toBe(0)
        ->and(OrderStatusEvent::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($attempt->fresh()->status->value)->toBe('PENDING')
        ->and($attempt->fresh()->verified_at)->not->toBeNull()
        ->and($attempt->fresh()->verification_snapshot_json)->not->toBeNull();
});

it('increments one per-phone customer summary and starts a new yearly number sequence', function () {
    $first = setVerificationReference(paidAttempt());
    $one = app(CreateOrderFromAttempt::class)->execute($first);
    $sameYear = setVerificationReference(paidAttempt([
        'verification_snapshot_json' => ['paid_at' => '2026-08-01T00:00:00Z'],
    ]));
    $sameYearOrder = app(CreateOrderFromAttempt::class)->execute($sameYear);
    $second = setVerificationReference(paidAttempt([
        'customer_snapshot_json' => ['fullName' => 'Ama New Name', 'phoneE164' => '+233241234567', 'phoneDisplay' => '+233241234567', 'email' => 'new@example.test'],
        'amount_minor' => 7000,
        'items_snapshot_json' => [
            ['lineType' => 'BUNDLE', 'lineId' => 'b', 'name' => 'Set', 'productCode' => 'SET', 'quantity' => 1, 'unitPriceMinor' => 6000, 'lineTotalMinor' => 6000, 'bundleContents' => []],
            ['lineType' => 'BUNDLE_EXTRA', 'parentLineId' => 'b', 'name' => 'Extra', 'productCode' => 'EX', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000],
        ],
        'verification_snapshot_json' => ['amount' => 7000, 'paid_at' => '2027-01-01T00:00:00Z'],
    ]));
    $two = app(CreateOrderFromAttempt::class)->execute($second);
    $olderPayment = setVerificationReference(paidAttempt([
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'old@example.test'],
        'amount_minor' => 1000,
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Oil', 'productCode' => 'OIL', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000]],
        'verification_snapshot_json' => ['amount' => 1000, 'paid_at' => '2026-01-01T00:00:00Z'],
    ]));
    $olderOrder = app(CreateOrderFromAttempt::class)->execute($olderPayment);

    expect($one->order_number)->toBe('VHI-2026-000001')
        ->and($sameYearOrder->order_number)->toBe('VHI-2026-000002')
        ->and($two->order_number)->toBe('VHI-2027-000001')
        ->and($olderOrder->order_number)->toBe('VHI-2026-000003')
        ->and(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->sole()->full_name)->toBe('Ama New Name')
        ->and(Customer::query()->sole()->email)->toBe('new@example.test')
        ->and(Customer::query()->sole()->phone_display)->toBe('+233241234567')
        ->and(Customer::query()->sole()->orders_count)->toBe(4)
        ->and(Customer::query()->sole()->lifetime_paid_minor)->toBe(15000)
        ->and(Customer::query()->sole()->first_order_at->toISOString())->toStartWith('2026-01-01T00:00:00')
        ->and(Customer::query()->sole()->last_order_at->toISOString())->toStartWith('2027-01-01T00:00:00');
});
