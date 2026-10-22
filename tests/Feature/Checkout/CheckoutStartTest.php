<?php

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackUnavailable;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function checkoutPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'fullName' => 'Ama Mensah', 'phone' => '0241234567', 'email' => 'ama@example.com',
        'region' => 'Greater Accra', 'area' => 'osu', 'address' => '12 Oxford Street',
        'landmark' => 'Near the mall', 'ghanaPostGps' => 'ga-123-4567', 'policyAccepted' => true,
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2, 'lineId' => 'line-1']],
    ], $overrides);
}

function checkoutCatalogProduct(): Product
{
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1400, 'price_minor' => 1300, 'is_active' => true]);

    return $product;
}

function checkoutArea(): DeliveryArea
{
    return DeliveryArea::query()->create(['name' => 'Osu', 'aliases_json' => ['Oxford'], 'is_active' => true]);
}

beforeEach(function (): void {
    config(['services.paystack.secret_key' => 'sk_test_secret', 'services.paystack.base_url' => 'https://api.paystack.co', 'services.paystack.callback_url' => null, 'services.storefront.url' => 'https://shop.example']);
    Http::preventStrayRequests();
});

it('prices from the catalogue and initializes a pending Paystack attempt with immutable snapshots', function () {
    checkoutCatalogProduct();
    checkoutArea();
    Http::fake(['https://api.paystack.co/transaction/initialize' => function ($request) {
        return Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/abc']], 200);
    }]);

    $response = $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2, 'lineId' => 'line-1', 'priceMinor' => 1, 'amount' => 1]]]));
    $response->assertCreated()->assertJsonStructure(['authorizationUrl', 'reference'])->assertJsonPath('authorizationUrl', 'https://checkout.paystack.com/abc');

    $attempt = CheckoutAttempt::query()->sole();
    expect($attempt->amount_minor)->toBe(2600)
        ->and($attempt->currency)->toBe('GHS')
        ->and($attempt->status->value)->toBe('PENDING')
        ->and($attempt->customer_snapshot_json['phoneE164'])->toBe('+233241234567')
        ->and($attempt->customer_snapshot_json['phoneDisplay'])->toBe('0241234567')
        ->and($attempt->delivery_snapshot_json['area'])->toBe('Osu')
        ->and($attempt->delivery_snapshot_json['ghanaPostGps'])->toBe('GA-123-4567')
        ->and($attempt->items_snapshot_json[0]['unitPriceMinor'])->toBe(1300)
        ->and($attempt->items_snapshot_json[0]['lineTotalMinor'])->toBe(2600);
    Http::assertSent(fn ($request): bool => $request['amount'] === 2600);
    Http::assertSent(fn ($request): bool => $request['currency'] === 'GHS' && $request['channels'] === ['mobile_money', 'card']);
    Http::assertSent(fn ($request): bool => $request['callback_url'] === 'https://shop.example/checkout/return' && $request['email'] === 'ama@example.com');
    Http::assertSent(fn ($request): bool => $request['metadata']['attemptId'] === $attempt->public_id);
});

it('rejects stale expected prices before creating attempts or contacting Paystack', function () {
    checkoutCatalogProduct();
    checkoutArea();
    $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1, 'lineId' => 'stale', 'expectedUnitPriceMinor' => 1299]]]))
        ->assertUnprocessable()->assertJsonPath('code', 'CHECKOUT_ITEMS_CHANGED')->assertJsonPath('issues.0.reason', 'PRICE_CHANGED')->assertJsonPath('issues.0.priceMinor', 1300)->assertJsonFragment(['errors' => ['items.0' => ['The price has changed.']]]);
    expect(CheckoutAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects unsupported delivery areas, malformed phone and GPS, and non-boolean consent', function () {
    checkoutCatalogProduct();
    checkoutArea();
    $this->postJson('/api/v1/checkout', checkoutPayload(['area' => 'Tema']))->assertUnprocessable()->assertJsonPath('errors.area.0', 'Choose an accepted normal-delivery area.');
    $this->postJson('/api/v1/checkout', checkoutPayload(['policyAccepted' => 1]))->assertUnprocessable()->assertJsonPath('errors.policyAccepted.0', 'Accept the policy to continue.');
    $this->postJson('/api/v1/checkout', checkoutPayload(['phone' => '024123456']))->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    $this->postJson('/api/v1/checkout', checkoutPayload(['ghanaPostGps' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors(['ghanaPostGps']);
    expect(CheckoutAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('fails initialization safely and marks the attempt failed', function () {
    checkoutCatalogProduct();
    checkoutArea();
    Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'secret detail'], 500)]);

    $this->postJson('/api/v1/checkout', checkoutPayload())->assertStatus(503)->assertJsonPath('code', 'PAYMENT_PROVIDER_UNAVAILABLE')->assertDontSee('secret detail');
    expect(CheckoutAttempt::query()->sole()->status->value)->toBe('FAILED');
});

it('prices a fixed bundle once and keeps bundle extras linked to it', function () {
    $product = checkoutCatalogProduct();
    $variant = $product->variants()->first();
    $bundle = Bundle::query()->create(['name' => 'Routine set', 'slug' => 'routine', 'product_code' => 'BD-1', 'price_minor' => 2500, 'is_published' => true]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
    checkoutArea();
    Http::fake(['https://api.paystack.co/transaction/initialize' => function ($request) {
        return Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/abc']]);
    }]);
    $payload = checkoutPayload();
    $payload['items'] = [
        ['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1, 'parentLineId' => 'bundle'],
        ['slug' => 'routine', 'productCode' => 'BD-1', 'quantity' => 1, 'lineId' => 'bundle'],
    ];
    $this->postJson('/api/v1/checkout', $payload)->assertCreated();
    $attempt = CheckoutAttempt::query()->sole();
    expect($attempt->amount_minor)->toBe(3800)
        ->and($attempt->items_snapshot_json[0]['lineType'])->toBe('BUNDLE_EXTRA')
        ->and($attempt->items_snapshot_json[0]['lineId'])->toBeNull()
        ->and($attempt->items_snapshot_json[1]['lineType'])->toBe('BUNDLE')
        ->and($attempt->items_snapshot_json[1]['bundleId'])->toBe($bundle->id)
        ->and($attempt->items_snapshot_json[1]['bundleContents'][0]['quantity'])->toBe(2);
});

it('rejects zero, excessive, and fractional quantities without creating attempts or sending HTTP', function () {
    checkoutCatalogProduct();
    checkoutArea();

    foreach ([0, 21, 1.5] as $quantity) {
        $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => $quantity]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);
    }

    expect(CheckoutAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects unpublished products, inactive variants or categories, and bundles with unavailable components', function () {
    checkoutArea();
    $makeProduct = function (string $name, string $slug, string $code, bool $published = true, bool $categoryActive = true, bool $variantActive = true): Product {
        $category = Category::query()->create(['name' => $name.' category', 'slug' => $slug.'-category', 'is_active' => $categoryActive]);
        $product = Product::query()->create(['name' => $name, 'slug' => $slug, 'category_id' => $category->id, 'is_published' => $published]);
        $product->variants()->create(['size_label' => 'One size', 'product_code' => $code, 'source_price_minor' => 1000, 'price_minor' => 900, 'is_active' => $variantActive]);

        return $product;
    };
    $unpublished = $makeProduct('Draft', 'draft-product', 'DRAFT-1', published: false);
    $inactiveVariant = $makeProduct('Inactive size', 'inactive-size', 'INACTIVE-1', variantActive: false);
    $inactiveCategory = $makeProduct('Hidden category', 'hidden-category-item', 'HIDDEN-1', categoryActive: false);
    $hiddenComponent = $makeProduct('Hidden component', 'hidden-component', 'COMPONENT-1', variantActive: false);
    $bundle = Bundle::query()->create(['name' => 'Unavailable kit', 'slug' => 'unavailable-kit', 'product_code' => 'KIT-HIDDEN', 'price_minor' => 1200, 'is_published' => true]);
    $bundle->items()->create(['product_variant_id' => $hiddenComponent->variants()->first()->id, 'quantity' => 1]);

    foreach ([
        [$unpublished->slug, 'DRAFT-1'], [$inactiveVariant->slug, 'INACTIVE-1'],
        [$inactiveCategory->slug, 'HIDDEN-1'], [$bundle->slug, 'KIT-HIDDEN'],
    ] as [$slug, $productCode]) {
        $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [['slug' => $slug, 'productCode' => $productCode, 'quantity' => 1, 'lineId' => 'unavailable']]]))
            ->assertUnprocessable()->assertJsonPath('code', 'CHECKOUT_ITEMS_CHANGED')->assertJsonPath('issues.0.reason', 'ITEM_UNAVAILABLE');
    }

    expect(CheckoutAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects orphan extras, product parents, and bundles with parents before contacting Paystack', function () {
    $product = checkoutCatalogProduct();
    $variant = $product->variants()->first();
    $bundle = Bundle::query()->create(['name' => 'Routine', 'slug' => 'routine-parent', 'product_code' => 'BUNDLE-1', 'price_minor' => 2000, 'is_published' => true]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
    $otherBundle = Bundle::query()->create(['name' => 'Other routine', 'slug' => 'other-routine', 'product_code' => 'BUNDLE-2', 'price_minor' => 2200, 'is_published' => true]);
    $otherBundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
    checkoutArea();

    $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [[
        'slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1, 'parentLineId' => 'missing',
    ]]]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.parentLineId']);

    $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [
        ['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1, 'lineId' => 'product-child', 'parentLineId' => 'product-base'],
        ['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1, 'lineId' => 'product-base'],
    ]]))->assertUnprocessable()->assertJsonFragment(['errors' => ['items.0.parentLineId' => ['Choose a bundle line in this cart.']]]);

    $this->postJson('/api/v1/checkout', checkoutPayload(['items' => [
        ['slug' => 'routine-parent', 'productCode' => 'BUNDLE-1', 'quantity' => 1, 'lineId' => 'child-bundle', 'parentLineId' => 'base-bundle'],
        ['slug' => 'other-routine', 'productCode' => 'BUNDLE-2', 'quantity' => 1, 'lineId' => 'base-bundle'],
    ]]))->assertUnprocessable()->assertJsonFragment(['errors' => ['items.0.parentLineId' => ['Bundles cannot have a parent line.']]]);

    expect(CheckoutAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects sparse keyed cart objects before pricing or provider initialization', function (): void {
    $product = checkoutCatalogProduct();
    $variant = $product->variants()->first();
    $bundle = Bundle::query()->create(['name' => 'Sparse test set', 'slug' => 'sparse-test-set', 'product_code' => 'SPARSE-SET', 'price_minor' => 2500, 'is_published' => true]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
    checkoutArea();
    Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['reference' => 'unused', 'authorization_url' => 'https://checkout.paystack.com/unused']])]);
    $payload = checkoutPayload();
    $payload['items'] = [
        0 => ['slug' => 'sparse-test-set', 'productCode' => 'SPARSE-SET', 'quantity' => 1, 'lineId' => 'sparse-bundle'],
        2 => ['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1, 'parentLineId' => 'sparse-bundle'],
    ];

    $this->postJson('/api/v1/checkout', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);

    expect(CheckoutAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects invalid Ghana phone inputs when PhoneNumber is used directly', function () {
    expect(fn () => PhoneNumber::fromGhanaInput('not a phone'))->toThrow(InvalidArgumentException::class);
});

it('retries Paystack verification after server errors but not client errors and preserves amount types', function () {
    Http::fakeSequence('https://api.paystack.co/transaction/verify/REF-1')
        ->push(['status' => false], 503)
        ->push(['status' => true, 'data' => ['status' => 'success', 'amount' => '900']], 200);

    $verified = app(PaystackClient::class)->verify('REF-1');
    expect($verified['amount'])->toBe('900');
    expect(Http::recorded()->filter(fn ($pair): bool => str_contains($pair[0]->url(), '/transaction/verify/REF-1'))->count())->toBe(2);

    Http::fake(['https://api.paystack.co/transaction/verify/REF-2' => Http::response(['message' => 'not authorized'], 401)]);
    expect(fn () => app(PaystackClient::class)->verify('REF-2'))->toThrow(PaystackUnavailable::class);
    expect(Http::recorded()->filter(fn ($pair): bool => str_contains($pair[0]->url(), '/transaction/verify/REF-2'))->count())->toBe(1);
});

it('does not repeat uncertain initialization or refund POSTs and sends the GHS refund payload', function () {
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response(['status' => false], 503),
        'https://api.paystack.co/refund' => Http::sequence()
            ->push(['status' => false], 503)
            ->push(['status' => true, 'data' => ['id' => 123, 'amount' => '900']], 200),
    ]);
    $client = app(PaystackClient::class);

    expect(fn () => $client->initialize(['reference' => 'VHI-test']))->toThrow(PaystackUnavailable::class);
    expect(fn () => $client->refund(['transaction' => 'REFUND-REF', 'amount' => 900, 'currency' => 'GHS']))->toThrow(PaystackUnavailable::class);
    expect(Http::recorded()->count())->toBe(2);

    $refund = $client->refund(['transaction' => 'REFUND-REF', 'amount' => 900, 'currency' => 'GHS']);
    expect($refund['amount'])->toBe('900')
        ->and(Http::recorded()->count())->toBe(3);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/refund'
        && $request['transaction'] === 'REFUND-REF' && $request['amount'] === 900 && $request['currency'] === 'GHS');
});

it('does not repeat initialization or refund POSTs after a connection failure', function () {
    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $calls[$path] = ($calls[$path] ?? 0) + 1;
        throw new ConnectionException('Connection interrupted.');
    });
    $client = app(PaystackClient::class);

    expect(fn () => $client->initialize(['reference' => 'VHI-uncertain']))->toThrow(PaystackUnavailable::class);
    expect(fn () => $client->refund(['transaction' => 'REFUND-UNCERTAIN', 'amount' => 900, 'currency' => 'GHS']))->toThrow(PaystackUnavailable::class);
    expect($calls)->toBe(['/transaction/initialize' => 1, '/refund' => 1]);
});
