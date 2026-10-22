<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Orders\Actions\CreateManualOrder;
use App\Modules\Orders\Actions\UpdateOrderContents;
use App\Modules\Orders\Enums\SettlementMethod;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderContentRevision;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

function ounAdmin(): StaffUser
{
    return StaffUser::query()->where('role', StaffRole::ADMIN->value)->sole();
}

function ounOrder(?string $email = 'ama@example.com'): Order
{
    return app(CreateManualOrder::class)->execute([
        'confirm' => true, 'fullName' => 'Ama Mensah', 'phone' => '0244000000', 'email' => $email,
        'region' => 'Greater Accra', 'area' => 'Osu', 'address' => '12 Oxford Street', 'paymentMethod' => 'MOMO',
        'paymentReference' => 'MOMO-'.Str::random(10), 'amountPaidMinor' => 2600,
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2]],
    ], ounAdmin());
}

function ounEdit(Order $order, int $quantity, int $basedOn = 0, ?string $note = 'Collected the difference by MoMo'): OrderContentRevision
{
    app(UpdateOrderContents::class)->execute($order, ounAdmin(), [['itemId' => $order->items()->orderByDesc('id')->value('id'), 'quantity' => $quantity]],
        'Customer asked for a different quantity', SettlementMethod::MOMO, $note, $basedOn);

    return OrderContentRevision::query()->latest('id')->firstOrFail();
}

/** @return list<string> */
function ounKeys(): array
{
    return NotificationLog::query()->where('idempotency_key', 'like', 'order-updated:%')->orderBy('id')->pluck('template')->all();
}

beforeEach(function () {
    StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1100, 'price_minor' => 1300, 'is_active' => true]);
});

it('emails and texts the customer once per revision', function () {
    $order = ounOrder();
    $first = ounEdit($order, 3);
    $second = ounEdit($order->fresh(), 1, 1);

    expect(ounKeys())->toBe(['order-updated', 'sms.customer_order_updated', 'order-updated', 'sms.customer_order_updated']);
    $keys = NotificationLog::query()->where('idempotency_key', 'like', 'order-updated:%')->pluck('idempotency_key')->all();
    expect(collect($keys)->filter(fn (string $k): bool => str_contains($k, ':'.$first->id.':'))->count())->toBe(2)
        ->and(collect($keys)->filter(fn (string $k): bool => str_contains($k, ':'.$second->id.':'))->count())->toBe(2);
});

it('still texts the customer when no email is on file', function () {
    ounEdit(ounOrder(null), 3);
    expect(ounKeys())->toBe(['sms.customer_order_updated']);
});

it('sends nothing when the edit is rejected', function () {
    $order = ounOrder();
    expect(fn () => ounEdit($order, 3, 0, null))->toThrow(ApiException::class);
    expect(ounKeys())->toBe([])->and(OrderContentRevision::query()->count())->toBe(0);
});

it('rolls notification logs and jobs back with the enclosing business transaction', function () {
    $order = ounOrder();
    $jobsBeforeEdit = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($order, $jobsBeforeEdit): void {
            ounEdit($order, 3);
            expect(ounKeys())->toHaveCount(2)->and(DB::table('jobs')->count())->toBe($jobsBeforeEdit + 2);
            throw new RuntimeException('force enclosing rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('force enclosing rollback');
    }

    expect(ounKeys())->toBe([])->and(DB::table('jobs')->count())->toBe($jobsBeforeEdit)->and(OrderContentRevision::query()->count())->toBe(0);
});
