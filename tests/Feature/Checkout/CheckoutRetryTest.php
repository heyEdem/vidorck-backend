<?php

use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.paystack.secret_key' => 'sk_test_retry', 'services.paystack.base_url' => 'https://api.paystack.co', 'services.paystack.callback_url' => null, 'services.storefront.url' => 'https://shop.example']);
    Http::preventStrayRequests();
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1400, 'price_minor' => 1300, 'is_active' => true]);
    DeliveryArea::query()->create(['name' => 'Osu', 'aliases_json' => ['Oxford'], 'is_active' => true]);
});

function retryParent(array $overrides = []): CheckoutAttempt
{
    return CheckoutAttempt::query()->create(array_replace([
        'public_id' => (string) Str::ulid(), 'paystack_reference' => 'REF-RETRY-'.Str::random(12),
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'ama@example.test'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Osu', 'address' => '12 Oxford Street', 'landmark' => 'Near the mall', 'ghanaPostGps' => 'GA-123-4567'],
        'items_snapshot_json' => [['slug' => 'shampoo', 'lineType' => 'PRODUCT', 'lineId' => 'line-1', 'parentLineId' => null, 'quantity' => 2, 'unitPriceMinor' => 1300, 'lineTotalMinor' => 2600, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        'amount_minor' => 2600, 'currency' => 'GHS', 'status' => CheckoutAttemptStatus::FAILED, 'expires_at' => now()->subMinute(),
    ], $overrides));
}

it('verifies the parent before creating a linked fresh attempt at the saved amount', function (): void {
    $parent = retryParent();
    Http::fake(function (Request $request) use ($parent) {
        if (str_ends_with($request->url(), '/transaction/verify/'.$parent->paystack_reference)) {
            return Http::response(['status' => true, 'data' => ['status' => 'reversed']]);
        }

        return Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/retry']]);
    });

    $response = $this->postJson('/api/v1/checkout/'.$parent->paystack_reference.'/retry');
    $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('authorizationUrl', 'https://checkout.paystack.com/retry');
    $child = CheckoutAttempt::query()->whereNotNull('parent_attempt_id')->sole();
    expect($child->parent_attempt_id)->toBe($parent->id)
        ->and($child->paystack_reference)->not->toBe($parent->paystack_reference)
        ->and($child->amount_minor)->toBe(2600)
        ->and($child->customer_snapshot_json['phoneDisplay'])->toBe('0241234567')
        ->and($child->delivery_snapshot_json['ghanaPostGps'])->toBe('GA-123-4567')
        ->and($child->items_snapshot_json[0]['unitPriceMinor'])->toBe(1300);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/transaction/initialize') && $request['amount'] === 2600 && $request['currency'] === 'GHS' && $request['metadata']['attemptId'] === $child->public_id);
});

it('allows failed initialization parents when Paystack explicitly says the reference does not exist', function (): void {
    $parent = retryParent();
    Http::fake(function (Request $request) use ($parent) {
        if (str_ends_with($request->url(), '/transaction/verify/'.$parent->paystack_reference)) {
            return Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404);
        }

        return Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/retry']]);
    });

    $this->postJson('/api/v1/checkout/'.$parent->paystack_reference.'/retry')->assertCreated();
    expect(CheckoutAttempt::query()->whereNotNull('parent_attempt_id')->count())->toBe(1);
});

it('blocks active, unknown, mismatched, already paid, and already retried attempts with specific conflicts', function (): void {
    $pending = retryParent(['status' => CheckoutAttemptStatus::PENDING, 'expires_at' => now()->addHour()]);
    $this->postJson('/api/v1/checkout/'.$pending->paystack_reference.'/retry')->assertConflict()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'CHECKOUT_PENDING');
    $unknown = retryParent(['status' => CheckoutAttemptStatus::MISMATCH]);
    $this->postJson('/api/v1/checkout/'.$unknown->paystack_reference.'/retry')->assertConflict()->assertJsonPath('code', 'CHECKOUT_NOT_RETRYABLE');
    $paid = retryParent(['status' => CheckoutAttemptStatus::SUCCEEDED, 'verified_at' => now()]);
    $this->postJson('/api/v1/checkout/'.$paid->paystack_reference.'/retry')->assertConflict()->assertJsonPath('code', 'CHECKOUT_ALREADY_PAID');
    $retried = retryParent();
    retryParent(['parent_attempt_id' => $retried->id]);
    $this->postJson('/api/v1/checkout/'.$retried->paystack_reference.'/retry')->assertConflict()->assertJsonPath('code', 'CHECKOUT_ALREADY_RETRIED');
    $this->postJson('/api/v1/checkout/unknown-reference/retry')->assertNotFound();
    Http::assertNothingSent();
});

it('returns provider unavailable without inserting a child when verification is uncertain', function (): void {
    $parent = retryParent();
    Http::fake(fn (Request $request) => Http::response(['status' => false, 'message' => 'temporary'], 503));
    $this->postJson('/api/v1/checkout/'.$parent->paystack_reference.'/retry')->assertStatus(503)->assertJsonPath('code', 'PAYMENT_PROVIDER_UNAVAILABLE');
    expect(CheckoutAttempt::query()->count())->toBe(1);
    Http::assertSentCount(2);
});

it('blocks retries while Paystack reports a transaction still in progress', function (): void {
    $attempts = [
        retryParent(['status' => CheckoutAttemptStatus::FAILED]),
        retryParent(['status' => CheckoutAttemptStatus::ABANDONED]),
        retryParent(['status' => CheckoutAttemptStatus::PENDING, 'expires_at' => now()->subMinute()]),
        retryParent(['status' => CheckoutAttemptStatus::PENDING, 'expires_at' => now()->subMinute()]),
    ];
    $statuses = ['pending', 'ongoing', 'processing', 'queued'];
    Http::fake(function (Request $request) use ($attempts, $statuses) {
        foreach ($attempts as $index => $attempt) {
            if (str_ends_with($request->url(), '/transaction/verify/'.$attempt->paystack_reference)) {
                return Http::response(['status' => true, 'data' => ['status' => $statuses[$index]]]);
            }
        }

        return Http::response(['status' => true, 'data' => ['reference' => 'unexpected', 'authorization_url' => 'https://checkout.paystack.com/unexpected']]);
    });

    foreach ($attempts as $attempt) {
        $this->postJson('/api/v1/checkout/'.$attempt->paystack_reference.'/retry')->assertConflict()->assertJsonPath('code', 'CHECKOUT_PENDING');
    }

    expect(CheckoutAttempt::query()->whereNotNull('parent_attempt_id')->count())->toBe(0);
    foreach ($attempts as $attempt) {
        expect($attempt->fresh()->getRawOriginal('status'))->toBe($attempt->getRawOriginal('status'));
    }
    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/transaction/initialize'));
});

it('rejects changed prices and unavailable saved catalogue items before initialization', function (): void {
    $parent = retryParent();
    ProductVariant::query()->where('product_code', 'SH-001')->update(['price_minor' => 1400]);
    Http::fake(fn (Request $request) => Http::response(['status' => true, 'data' => ['status' => 'reversed']]));
    $this->postJson('/api/v1/checkout/'.$parent->paystack_reference.'/retry')->assertUnprocessable()->assertJsonPath('code', 'CHECKOUT_ITEMS_CHANGED');
    expect(CheckoutAttempt::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('retries a saved checkout after its product slug has changed', function (): void {
    Queue::fake();
    $parent = retryParent();
    $product = Product::query()->where('slug', 'shampoo')->firstOrFail();
    $admin = StaffUser::query()->create([
        'name' => 'Catalogue Admin',
        'email' => 'retry-catalogue-admin@example.test',
        'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN,
    ]);
    app(ManageCatalog::class)->updateProduct($admin, $product, ['slug' => 'renamed-shampoo']);
    Http::fake(function (Request $request) use ($parent) {
        if (str_ends_with($request->url(), '/transaction/verify/'.$parent->paystack_reference)) {
            return Http::response(['status' => true, 'data' => ['status' => 'reversed']]);
        }

        return Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/retry']]);
    });

    $this->postJson('/api/v1/checkout/'.$parent->paystack_reference.'/retry')->assertCreated();

    expect(CheckoutAttempt::query()->whereNotNull('parent_attempt_id')->sole()->items_snapshot_json[0]['slug'])
        ->toBe('renamed-shampoo');
    Http::assertSentCount(2);
});
