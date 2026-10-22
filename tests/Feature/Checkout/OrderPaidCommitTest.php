<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function paidCommitAttempt(): CheckoutAttempt
{
    $publicId = (string) Str::ulid();
    $reference = 'commit-'.Str::random(15);

    return CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => null],
        'delivery_snapshot_json' => ['area' => 'Osu'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Oil', 'productCode' => 'OIL', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);
}

it('dispatches OrderPaid after the outer commit exactly once and suppresses it on rollback', function () {
    $seen = [];
    Event::listen(OrderPaid::class, function (OrderPaid $event) use (&$seen): void {
        $seen[] = $event->order->getKey();
    });

    $attempt = paidCommitAttempt();
    DB::beginTransaction();
    $order = app(CreateOrderFromAttempt::class)->execute($attempt);
    expect($seen)->toBe([]);
    DB::commit();
    expect($seen)->toBe([$order->id]);

    app(CreateOrderFromAttempt::class)->execute($attempt->fresh());
    expect($seen)->toBe([$order->id])->and(AuditLog::query()->where('action', 'order.paid')->count())->toBe(1);

    $rolledBackAttempt = paidCommitAttempt();
    DB::beginTransaction();
    $rolledBackOrder = app(CreateOrderFromAttempt::class)->execute($rolledBackAttempt);
    expect($seen)->toBe([$order->id]);
    DB::rollBack();

    expect($seen)->toBe([$order->id])
        ->and(Order::query()->whereKey($rolledBackOrder->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'order.paid')->count())->toBe(1)
        ->and($rolledBackAttempt->fresh()->status->value)->toBe('PENDING');
});
