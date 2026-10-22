<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\RecordOrderContentRevision;
use App\Modules\Orders\Enums\SettlementMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderContentRevision;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function ocrOrder(): Order
{
    $publicId = (string) Str::ulid();
    $reference = 'ocr-'.Str::random(15);
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

function ocrStaff(): StaffUser
{
    return StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
}

/** @return array<string, mixed> a valid raw row; override keys to break it */
function ocrRow(Order $order, StaffUser $staff, array $over = []): array
{
    return $over + [
        'order_id' => $order->id, 'staff_id' => $staff->id, 'reason' => 'Customer swapped sizes',
        'before_items_json' => '[]', 'after_items_json' => '[]', 'before_subtotal_minor' => 1000, 'after_subtotal_minor' => 1200,
        'difference_minor' => 200, 'settlement_method' => 'MOMO', 'settlement_note' => 'Collected GHS 2.00 by MoMo', 'created_at' => now(),
    ];
}

it('records a revision through the insert-only recorder', function () {
    $order = ocrOrder();
    $staff = ocrStaff();
    $revision = app(RecordOrderContentRevision::class)->record($order, $staff, ' Customer swapped sizes ',
        [['itemId' => 1, 'quantity' => 1]], [['itemId' => 2, 'quantity' => 2]], 1000, 1200, SettlementMethod::MOMO, ' Collected GHS 2.00 ');

    $fresh = OrderContentRevision::query()->sole();
    expect($fresh->id)->toBe($revision->id)
        ->and($fresh->reason)->toBe('Customer swapped sizes')
        ->and($fresh->settlement_note)->toBe('Collected GHS 2.00')
        ->and($fresh->difference_minor)->toBe(200)
        ->and($fresh->settlement_method)->toBe(SettlementMethod::MOMO)
        ->and($fresh->before_items_json)->toBe([['itemId' => 1, 'quantity' => 1]])
        ->and($order->contentRevisions()->count())->toBe(1)
        ->and($fresh->staff->is($staff))->toBeTrue();
});

it('refuses model updates and deletes', function () {
    $revision = app(RecordOrderContentRevision::class)->record(ocrOrder(), ocrStaff(), 'Customer swapped sizes', [], [], 1000, 1000, SettlementMethod::NONE, null);

    expect(fn () => $revision->update(['reason' => 'Edited later']))->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())->toThrow(LogicException::class);
});

it('refuses UPDATE and DELETE at the database, including DELETE without WHERE', function () {
    $order = ocrOrder();
    DB::table('order_content_revisions')->insert(ocrRow($order, ocrStaff()));

    expect(fn () => DB::table('order_content_revisions')->update(['reason' => 'Edited later']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('order_content_revisions')->where('order_id', $order->id)->delete())->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::statement('DELETE FROM order_content_revisions'))->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('order_content_revisions')->count())->toBe(1);
});

it('refuses INSERT OR REPLACE over an existing revision', function () {
    $order = ocrOrder();
    $staff = ocrStaff();
    DB::table('order_content_revisions')->insert(ocrRow($order, $staff));
    $id = (int) DB::table('order_content_revisions')->value('id');

    expect(fn () => DB::statement(
        'INSERT OR REPLACE INTO order_content_revisions (id, order_id, staff_id, reason, before_items_json, after_items_json, before_subtotal_minor, after_subtotal_minor, difference_minor, settlement_method, settlement_note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $order->id, $staff->id, 'Rewritten', '[]', '[]', 1000, 1000, 0, 'NONE', null, now()->toDateTimeString()],
    ))->toThrow(QueryException::class, 'append-only');
    expect(DB::table('order_content_revisions')->value('reason'))->toBe('Customer swapped sizes');
});

it('rejects inconsistent rows at insert', function (array $over) {
    $order = ocrOrder();
    $staff = ocrStaff();
    expect(fn () => DB::table('order_content_revisions')->insert(ocrRow($order, $staff, $over)))->toThrow(QueryException::class, 'invalid order content revision');
})->with([
    'unknown method' => [['settlement_method' => 'CHEQUE']],
    'difference does not match' => [['difference_minor' => 150]],
    'changed total settled as NONE' => [['settlement_method' => 'NONE']],
    'changed total without a note' => [['settlement_note' => null]],
    'changed total with a blank note' => [['settlement_note' => '   ']],
    'blank reason' => [['reason' => '  ']],
    'negative before subtotal with matching difference' => [['before_subtotal_minor' => -100, 'after_subtotal_minor' => 1200, 'difference_minor' => 1300]],
    'negative after subtotal with matching difference' => [['before_subtotal_minor' => 1000, 'after_subtotal_minor' => -100, 'difference_minor' => -1100]],
]);

it('accepts an unchanged total with no settlement', function () {
    DB::table('order_content_revisions')->insert(ocrRow(ocrOrder(), ocrStaff(), [
        'after_subtotal_minor' => 1000, 'difference_minor' => 0, 'settlement_method' => 'NONE', 'settlement_note' => null,
    ]));
    expect(DB::table('order_content_revisions')->count())->toBe(1);
});

it('refuses to roll back a persisted order content revision history', function () {
    $order = ocrOrder();
    DB::table('order_content_revisions')->insert(ocrRow($order, ocrStaff()));
    $migration = require database_path('migrations/2026_10_15_090000_create_order_content_revisions_table.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class)
        ->and(Schema::hasTable('order_content_revisions'))->toBeTrue()
        ->and(DB::table('order_content_revisions')->count())->toBe(1)
        ->and(fn () => DB::table('order_content_revisions')->delete())->toThrow(QueryException::class, 'append-only');
});
