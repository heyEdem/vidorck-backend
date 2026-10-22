<?php

use App\Modules\Catalog\Actions\CatalogInvalidation;
use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([RevalidateStorefront::class]);
});

it('dispatches one revalidation job with product, old slug, and dependent bundle tags', function () {
    $category = Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $product = Product::create(['name' => 'Daily Shampoo', 'slug' => 'daily-shampoo', 'category_id' => $category->id]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'size_label' => '250 ml', 'normalized_size' => '250-ml',
        'product_code' => 'SHAMPOO-250', 'source_price_minor' => 1000, 'price_minor' => 1200]);
    $bundle = Bundle::create(['name' => 'Hair Set', 'slug' => 'hair-set', 'product_code' => 'SET-1', 'price_minor' => 1000]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);

    app(CatalogInvalidation::class)->product($product, 'old-shampoo');

    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);
    Bus::assertDispatched(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === [
        'bundle:hair-set', 'bundles', 'product:daily-shampoo', 'product:old-shampoo', 'products',
    ]);
});

it('dispatches one job for bundle, category, and delivery-area invalidations', function () {
    $invalidation = app(CatalogInvalidation::class);
    $bundle = Bundle::create(['name' => 'Hair Set', 'slug' => 'hair-set', 'product_code' => 'SET-1', 'price_minor' => 1000]);

    $invalidation->bundle($bundle, 'old-set');
    $invalidation->categories();
    $invalidation->deliveryAreas();

    Bus::assertDispatchedTimes(RevalidateStorefront::class, 3);
    Bus::assertDispatched(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === ['bundle:hair-set', 'bundle:old-set', 'bundles']);
    Bus::assertDispatched(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === ['bundles', 'categories', 'products']);
    Bus::assertDispatched(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === ['delivery-areas']);
});

it('invalidates product and dependent bundle details when a category changes', function () {
    $category = Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $otherCategory = Category::create(['name' => 'Body Care', 'slug' => 'body-care']);
    $product = Product::create(['name' => 'Daily Shampoo', 'slug' => 'daily-shampoo', 'category_id' => $category->id]);
    Product::create(['name' => 'Body Wash', 'slug' => 'body-wash', 'category_id' => $otherCategory->id]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'size_label' => '250 ml', 'normalized_size' => '250-ml',
        'product_code' => 'SHAMPOO-250', 'source_price_minor' => 1000, 'price_minor' => 1200]);
    $bundle = Bundle::create(['name' => 'Hair Set', 'slug' => 'hair-set', 'product_code' => 'SET-1', 'price_minor' => 1000]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);

    app(CatalogInvalidation::class)->categories($category);

    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);
    Bus::assertDispatched(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === [
        'bundle:hair-set', 'bundles', 'categories', 'product:daily-shampoo', 'products',
    ]);
});

it('includes every product and bundle detail tag for a global category invalidation', function () {
    $category = Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $product = Product::create(['name' => 'Daily Shampoo', 'slug' => 'daily-shampoo', 'category_id' => $category->id]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'size_label' => '250 ml', 'normalized_size' => '250-ml',
        'product_code' => 'SHAMPOO-250', 'source_price_minor' => 1000, 'price_minor' => 1200]);
    $bundle = Bundle::create(['name' => 'Hair Set', 'slug' => 'hair-set', 'product_code' => 'SET-1', 'price_minor' => 1000]);
    BundleItem::create(['bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);

    app(CatalogInvalidation::class)->categories();

    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);
    Bus::assertDispatched(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === [
        'bundle:hair-set', 'bundles', 'categories', 'product:daily-shampoo', 'products',
    ]);
});

it('posts unique tags with the shared secret and throws for non-success responses', function () {
    config([
        'services.storefront.revalidate_url' => 'https://storefront.example/api/revalidate',
        'services.storefront.revalidate_secret' => 'test-secret',
    ]);
    Http::fake(['storefront.example/*' => Http::response(['message' => 'failed'], 503)]);

    $job = new RevalidateStorefront(['products', 'product:daily-shampoo', 'products']);
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 180]);
    expect(fn () => $job->handle())->toThrow(RequestException::class);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://storefront.example/api/revalidate'
        && $request->header('x-revalidate-secret') === ['test-secret']
        && $request['tags'] === ['products', 'product:daily-shampoo']);
});

it('does not follow redirects and retries a non-success redirect response', function () {
    config([
        'services.storefront.revalidate_url' => 'https://storefront.example/api/revalidate',
        'services.storefront.revalidate_secret' => 'test-secret',
    ]);
    Http::fake(['storefront.example/*' => Http::response('', 302, ['Location' => 'https://other.example/'])]);

    expect(fn () => (new RevalidateStorefront(['products']))->handle())
        ->toThrow(RuntimeException::class, 'Storefront revalidation returned a non-success status.');
    Http::assertSentCount(1);
});

it('skips revalidation when no storefront URL is configured', function () {
    config(['services.storefront.revalidate_url' => null, 'services.storefront.revalidate_secret' => null]);
    Http::fake();

    (new RevalidateStorefront(['products']))->handle();

    Http::assertNothingSent();
});
