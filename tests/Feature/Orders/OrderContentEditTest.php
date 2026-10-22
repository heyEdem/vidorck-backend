<?php

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderContentRevision;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

const OCE_PASSWORD = 'Long-Staff-Password9!';

function oceLogin(string $role): void
{
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => strtolower($role).'@example.com', 'password' => OCE_PASSWORD])->assertOk();
}

/** Creates a PAID manual order (as Admin) and returns its number. @param list<array<string, mixed>> $items */
function oceOrder(array $items, int $amount): string
{
    oceLogin('ADMIN');

    return test()->postJson('/api/v1/admin/orders/manual', [
        'confirm' => true, 'fullName' => 'Ama Mensah', 'phone' => '0244000000', 'email' => 'ama@example.com',
        'region' => 'Greater Accra', 'area' => 'Osu', 'address' => '12 Oxford Street', 'paymentMethod' => 'MOMO',
        'paymentReference' => 'MOMO-'.Str::random(10), 'amountPaidMinor' => $amount, 'items' => $items,
    ])->assertCreated()->json('data.orderNumber');
}

/** @return list<array<string, mixed>> */
function oceItems(string $number): array
{
    return test()->getJson("/api/v1/admin/orders/{$number}")->assertOk()->json('data.items');
}

/** @param array<string, mixed> $overrides */
function oceEdit(string $number, array $overrides = []): TestResponse
{
    return test()->patchJson("/api/v1/admin/orders/{$number}/contents", array_replace([
        'reason' => 'Customer asked for one more on WhatsApp',
        'settlement' => ['method' => 'MOMO', 'note' => 'Collected the difference by MoMo'],
        'basedOnRevision' => 0,
    ], $overrides));
}

function oceShampooOrder(): string
{
    return oceOrder([['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2]], 2600);
}

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    foreach (StaffRole::cases() as $role) {
        StaffUser::create(['name' => ucfirst(strtolower($role->value)), 'email' => strtolower($role->value).'@example.com', 'password' => OCE_PASSWORD, 'role' => $role]);
    }
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $shampoo = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $shampoo->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1100, 'price_minor' => 1300, 'is_active' => true]);
    $shampoo->variants()->create(['size_label' => '500ml', 'product_code' => 'SH-002', 'source_price_minor' => 1800, 'price_minor' => 2000, 'is_active' => true]);
    $shampoo->variants()->create(['size_label' => '300ml', 'product_code' => 'SH-003', 'source_price_minor' => 1100, 'price_minor' => 1300, 'is_active' => true]);
    $conditioner = Product::query()->create(['name' => 'Conditioner', 'slug' => 'conditioner', 'category_id' => $category->id, 'is_published' => true]);
    $conditioner->variants()->create(['size_label' => '250ml', 'product_code' => 'CN-001', 'source_price_minor' => 1300, 'price_minor' => 1500, 'is_active' => true]);
    $set = Bundle::query()->create(['name' => 'Starter Set', 'slug' => 'starter-set', 'product_code' => 'SET-1', 'price_minor' => 5000, 'is_published' => true]);
    BundleItem::query()->create(['bundle_id' => $set->id, 'product_variant_id' => ProductVariant::query()->where('product_code', 'SH-001')->value('id'), 'quantity' => 1, 'display_order' => 0]);
});

it('requires a signed-in Admin', function () {
    $number = oceShampooOrder();
    test()->postJson('/api/v1/admin/auth/logout');
    Auth::forgetGuards();
    oceEdit($number, ['items' => [['itemId' => 1, 'quantity' => 3]]])->assertUnauthorized();
    oceLogin('SALES_ORDERS');
    oceEdit($number, ['items' => [['itemId' => 1, 'quantity' => 3]]])->assertForbidden();
    expect(OrderContentRevision::query()->count())->toBe(0);
});

it('keeps unchanged lines at their paid price, reprices changed and new lines, and never changes total paid', function () {
    $number = oceOrder([
        ['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2],
        ['slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1],
    ], 4600);
    [$unchanged, $changed] = oceItems($number);
    ProductVariant::query()->where('product_code', 'SH-001')->update(['price_minor' => 1400]);
    ProductVariant::query()->where('product_code', 'SH-002')->update(['price_minor' => 2100]);
    $detail = test()->getJson("/api/v1/admin/orders/{$number}")->assertOk()
        ->assertJsonPath('data.items.0.currentUnitPriceMinor', 1400)
        ->assertJsonPath('data.items.1.currentUnitPriceMinor', 2100);
    $eventsBefore = OrderStatusEvent::query()->count();

    $res = oceEdit($number, ['items' => [
        ['itemId' => $unchanged['id'], 'quantity' => 2],
        ['itemId' => $changed['id'], 'quantity' => 2, 'expectedUnitPriceMinor' => 2100],
        ['slug' => 'conditioner', 'productCode' => 'CN-001', 'quantity' => 1, 'expectedUnitPriceMinor' => 1500],
    ]])->assertOk()
        ->assertJsonPath('data.status', 'PAID')
        ->assertJsonPath('data.subtotalMinor', 8300)
        ->assertJsonPath('data.totalPaidMinor', 4600)
        ->assertJsonPath('data.items.0.productCode', 'SH-001')
        ->assertJsonPath('data.items.0.unitPriceMinor', 1300)
        ->assertJsonPath('data.items.1.productCode', 'SH-002')
        ->assertJsonPath('data.items.1.unitPriceMinor', 2100)
        ->assertJsonPath('data.items.1.quantity', 2)
        ->assertJsonPath('data.items.2.productCode', 'CN-001')
        ->assertJsonPath('data.items.2.unitPriceMinor', 1500)
        ->assertJsonPath('data.contentRevisions.0.beforeSubtotalMinor', 4600)
        ->assertJsonPath('data.contentRevisions.0.afterSubtotalMinor', 8300)
        ->assertJsonPath('data.contentRevisions.0.differenceMinor', 3700)
        ->assertJsonPath('data.contentRevisions.0.settlementMethod', 'MOMO')
        ->assertJsonPath('data.contentRevisions.0.settlementNote', 'Collected the difference by MoMo')
        ->assertJsonPath('data.contentRevisions.0.staffName', 'Admin')
        ->assertJsonPath('data.contentRevisions.0.beforeItems.1.quantity', 1)
        ->assertJsonPath('data.contentRevisions.0.afterItems.2.productCode', 'CN-001');

    $order = Order::query()->where('order_number', $number)->sole();
    expect($order->total_paid_minor)->toBe(4600)
        ->and($order->subtotal_minor)->toBe(8300)
        ->and($order->items()->count())->toBe(3)
        ->and(OrderStatusEvent::query()->count())->toBe($eventsBefore);
    $audit = AuditLog::query()->where('action', 'order.contents_updated')->sole();
    expect($audit->target_id)->toBe((string) $order->id)
        ->and($audit->before['subtotalMinor'])->toBe(4600)
        ->and($audit->after['subtotalMinor'])->toBe(8300)
        ->and($audit->after['reason'])->toBe('Customer asked for one more on WhatsApp')
        ->and($audit->after['revisionId'])->toBe($res->json('data.contentRevisions.0.id'));
});

it('refuses to change the quantity of an item that is no longer on sale, but lets it stay unchanged or go', function () {
    $number = oceOrder([
        ['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2],
        ['slug' => 'conditioner', 'productCode' => 'CN-001', 'quantity' => 1],
    ], 4100);
    [$shampoo, $conditioner] = oceItems($number);
    ProductVariant::query()->where('product_code', 'SH-001')->update(['is_active' => false]);
    test()->getJson("/api/v1/admin/orders/{$number}")->assertJsonPath('data.items.0.currentUnitPriceMinor', null);

    $errors = oceEdit($number, ['items' => [['itemId' => $shampoo['id'], 'quantity' => 3], ['itemId' => $conditioner['id'], 'quantity' => 1]]])
        ->assertUnprocessable()->json('errors');
    expect($errors['items.0'])->toBe(['This item is no longer available.']);

    oceEdit($number, ['items' => [['itemId' => $shampoo['id'], 'quantity' => 2], ['itemId' => $conditioner['id'], 'quantity' => 2]]])
        ->assertOk()->assertJsonPath('data.items.0.unitPriceMinor', 1300)->assertJsonPath('data.subtotalMinor', 5600);
});

it('accepts removing and re-adding a renamed product as a change', function () {
    $number = oceShampooOrder();
    Product::query()->where('slug', 'shampoo')->update(['name' => 'Shampoo (new look)']);
    oceEdit($number, [
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2]],
        'reason' => 'Refresh the product name on the order', 'settlement' => ['method' => 'NONE'],
    ])->assertOk()->assertJsonPath('data.items.0.name', 'Shampoo (new look)');
});

it('lets Sales/Orders see the revision history', function () {
    $number = oceShampooOrder();
    oceEdit($number, ['items' => [['itemId' => oceItems($number)[0]['id'], 'quantity' => 1]], 'settlement' => ['method' => 'CASH', 'note' => 'Refunded GHS 13 in cash']])->assertOk();
    oceLogin('SALES_ORDERS');
    test()->getJson("/api/v1/admin/orders/{$number}")->assertOk()
        ->assertJsonCount(1, 'data.contentRevisions')->assertJsonPath('data.contentRevisions.0.differenceMinor', -1300);
});

it('requires a settlement whenever the subtotal changes', function () {
    $number = oceShampooOrder();
    $item = oceItems($number)[0];

    $none = oceEdit($number, ['items' => [['itemId' => $item['id'], 'quantity' => 3]], 'settlement' => ['method' => 'NONE']])->assertUnprocessable()->json('errors');
    $blank = oceEdit($number, ['items' => [['itemId' => $item['id'], 'quantity' => 3]], 'settlement' => ['method' => 'CASH', 'note' => '  ']])->assertUnprocessable()->json('errors');

    expect(array_keys($none))->toContain('settlement.method')
        ->and(array_keys($blank))->toContain('settlement.note')
        ->and(OrderContentRevision::query()->count())->toBe(0)
        ->and(oceItems($number)[0]['quantity'])->toBe(2);
});

it('accepts a same-total swap with no settlement', function () {
    $number = oceShampooOrder();
    oceEdit($number, [
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-003', 'quantity' => 2]],
        'reason' => 'Customer prefers the 300ml bottle',
        'settlement' => ['method' => 'NONE'],
    ])->assertOk()->assertJsonPath('data.subtotalMinor', 2600)
        ->assertJsonPath('data.items.0.productCode', 'SH-003')
        ->assertJsonPath('data.contentRevisions.0.differenceMinor', 0)
        ->assertJsonPath('data.contentRevisions.0.settlementNote', null);
});

it('rejects an edit that changes nothing', function () {
    $number = oceShampooOrder();
    $errors = oceEdit($number, ['items' => [['itemId' => oceItems($number)[0]['id'], 'quantity' => 2]], 'settlement' => ['method' => 'NONE']])
        ->assertUnprocessable()->json('errors');
    expect(array_keys($errors))->toContain('items');
});

it('allows edits only while the order is Paid or Processing', function () {
    $number = oceShampooOrder();
    $id = oceItems($number)[0]['id'];
    test()->postJson("/api/v1/admin/orders/{$number}/transitions", ['toStatus' => 'PROCESSING'])->assertOk();
    oceEdit($number, ['items' => [['itemId' => $id, 'quantity' => 3]]])->assertOk();

    test()->postJson("/api/v1/admin/orders/{$number}/transitions", ['toStatus' => 'READY_FOR_DISPATCH'])->assertOk();
    $newId = oceItems($number)[0]['id'];
    oceEdit($number, ['items' => [['itemId' => $newId, 'quantity' => 1]], 'basedOnRevision' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_EDITABLE');
});

it('refuses edits while a refund request is open, and the refund still uses total paid', function () {
    $number = oceShampooOrder();
    $order = Order::query()->where('order_number', $number)->sole();
    $id = oceItems($number)[0]['id'];
    oceEdit($number, ['items' => [['itemId' => $id, 'quantity' => 1]], 'settlement' => ['method' => 'CASH', 'note' => 'Refunded GHS 13 in cash']])->assertOk();

    $refund = RefundRequest::query()->create(['order_id' => $order->id, 'channel' => 'WEB', 'reason' => 'Changed my mind', 'status' => 'REQUESTED']);
    oceEdit($number, ['items' => [['itemId' => oceItems($number)[0]['id'], 'quantity' => 2]], 'basedOnRevision' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'ORDER_REFUND_OPEN');

    test()->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'APPROVE'])->assertOk();
    test()->postJson("/api/v1/admin/refunds/{$refund->id}/manual-completion", ['method' => 'MOMO', 'reference' => 'RFD-'.Str::random(8), 'amountMinor' => 1300])
        ->assertUnprocessable()->assertJsonPath('code', 'AMOUNT_MISMATCH');
    test()->postJson("/api/v1/admin/refunds/{$refund->id}/manual-completion", ['method' => 'MOMO', 'reference' => 'RFD-'.Str::random(8), 'amountMinor' => 2600])
        ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
});

it('rejects a stale edit instead of overwriting a newer one', function () {
    $number = oceShampooOrder();
    $id = oceItems($number)[0]['id'];
    oceEdit($number, ['items' => [['itemId' => $id, 'quantity' => 3]]])->assertOk();
    oceEdit($number, ['items' => [['itemId' => $id, 'quantity' => 1]], 'basedOnRevision' => 0])
        ->assertStatus(409)->assertJsonPath('code', 'ORDER_CONTENTS_CHANGED');
    expect(OrderContentRevision::query()->count())->toBe(1);
});

it('keeps a bundle extra as a product line when its bundle is removed', function () {
    $number = oceOrder([
        ['slug' => 'starter-set', 'productCode' => 'SET-1', 'quantity' => 1, 'lineId' => 'b1'],
        ['slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1, 'lineId' => 'x1', 'parentLineId' => 'b1'],
    ], 7000);
    [$bundle, $extra] = oceItems($number);
    expect($bundle['lineType'])->toBe('BUNDLE')->and($extra['lineType'])->toBe('BUNDLE_EXTRA');

    oceEdit($number, ['items' => [['itemId' => $extra['id'], 'quantity' => 1]], 'settlement' => ['method' => 'CASH', 'note' => 'Refunded GHS 50 in cash']])
        ->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.lineType', 'PRODUCT')
        ->assertJsonPath('data.items.0.parentItemId', null)
        ->assertJsonPath('data.items.0.unitPriceMinor', 2000)
        ->assertJsonPath('data.subtotalMinor', 2000);
});

it('re-links a kept extra to its kept bundle', function () {
    $number = oceOrder([
        ['slug' => 'starter-set', 'productCode' => 'SET-1', 'quantity' => 1, 'lineId' => 'b1'],
        ['slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1, 'lineId' => 'x1', 'parentLineId' => 'b1'],
    ], 7000);
    [$bundle, $extra] = oceItems($number);

    $items = oceEdit($number, ['items' => [['itemId' => $bundle['id'], 'quantity' => 1], ['itemId' => $extra['id'], 'quantity' => 2]]])
        ->assertOk()->json('data.items');
    expect($items[1]['lineType'])->toBe('BUNDLE_EXTRA')
        ->and($items[1]['parentItemId'])->toBe($items[0]['id'])
        ->and($items[1]['quantity'])->toBe(2);

    OrderItem::query()->where('order_id', Order::query()->where('order_number', $number)->value('id'))
        ->whereNotNull('parent_item_id')->delete();
    OrderItem::query()->where('order_id', Order::query()->where('order_number', $number)->value('id'))->delete();
});

it('rejects lines from another order, unavailable products and changed prices', function () {
    $other = oceShampooOrder();
    $foreignId = oceItems($other)[0]['id'];
    $number = oceShampooOrder();
    $id = oceItems($number)[0]['id'];
    Product::query()->where('slug', 'conditioner')->update(['is_published' => false]);

    $foreign = oceEdit($number, ['items' => [['itemId' => $foreignId, 'quantity' => 1]]])->assertUnprocessable()->json('errors');
    $hidden = oceEdit($number, ['items' => [['itemId' => $id, 'quantity' => 2], ['slug' => 'conditioner', 'productCode' => 'CN-001', 'quantity' => 1]]])
        ->assertUnprocessable()->json('errors');
    $beforeItems = oceItems($number);
    $beforeSubtotal = Order::query()->where('order_number', $number)->value('subtotal_minor');
    $beforeAuditCount = AuditLog::query()->count();
    $beforeStatusEvents = OrderStatusEvent::query()->count();
    $beforeJobs = DB::table('jobs')->count();
    $priced = oceEdit($number, ['items' => [['itemId' => $id, 'quantity' => 2], ['slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1, 'expectedUnitPriceMinor' => 1900]]])
        ->assertUnprocessable()->json('errors');

    expect($foreign)->toHaveKey('items.0.itemId')
        ->and($hidden['items.1'])->toBe(['This item is no longer available.'])
        ->and($priced['items.1'])->toBe(['The price has changed.'])
        ->and(OrderContentRevision::query()->count())->toBe(0)
        ->and(oceItems($number))->toEqual($beforeItems)
        ->and(Order::query()->where('order_number', $number)->value('subtotal_minor'))->toBe($beforeSubtotal)
        ->and(AuditLog::query()->count())->toBe($beforeAuditCount)
        ->and(OrderStatusEvent::query()->count())->toBe($beforeStatusEvents)
        ->and(DB::table('jobs')->count())->toBe($beforeJobs);
});

it('validates the request shape', function (array $overrides, string $field) {
    $number = oceShampooOrder();
    $errors = oceEdit($number, $overrides)->assertUnprocessable()->json('errors');
    expect(array_keys($errors))->toContain($field);
})->with([
    'no items' => [['items' => []], 'items'],
    'quantity zero' => [['items' => [['itemId' => 1, 'quantity' => 0]]], 'items.0.quantity'],
    'quantity over 20' => [['items' => [['itemId' => 1, 'quantity' => 21]]], 'items.0.quantity'],
    'duplicate item' => [['items' => [['itemId' => 1, 'quantity' => 1], ['itemId' => 1, 'quantity' => 2]]], 'items.1.itemId'],
    'new line without product code' => [['items' => [['slug' => 'shampoo', 'quantity' => 1]]], 'items.0.productCode'],
    'both an item id and a product' => [['items' => [['itemId' => 1, 'slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1]]], 'items.0'],
    'duplicate new product' => [['items' => [['slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1], ['slug' => 'shampoo', 'productCode' => 'SH-002', 'quantity' => 1]]], 'items.1'],
    'short reason' => [['items' => [['itemId' => 1, 'quantity' => 3]], 'reason' => 'abc'], 'reason'],
    'unknown settlement' => [['items' => [['itemId' => 1, 'quantity' => 3]], 'settlement' => ['method' => 'CHEQUE', 'note' => 'x']], 'settlement.method'],
    'missing revision' => [['items' => [['itemId' => 1, 'quantity' => 3]], 'basedOnRevision' => null], 'basedOnRevision'],
    'string quantity' => [['items' => [['itemId' => 1, 'quantity' => '3']]], 'items.0.quantity'],
]);
