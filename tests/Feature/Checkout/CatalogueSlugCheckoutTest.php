<?php

use App\Modules\Catalog\Actions\ManageBundles;
use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Actions\PriceCart;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->catalogueSlugAdmin = StaffUser::query()->create([
        'name' => 'Catalogue Admin',
        'email' => 'catalogue-slug-admin@example.test',
        'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN,
    ]);
    $this->catalogueSlugCategory = Category::query()->create([
        'name' => 'Hair care',
        'slug' => 'hair-care',
        'is_active' => true,
    ]);
});

function catalogueSlugProduct(string $name, string $slug, string $code, bool $published = true, bool $variantActive = true): Product
{
    $product = Product::query()->create([
        'name' => $name,
        'slug' => $slug,
        'category_id' => test()->catalogueSlugCategory->id,
        'is_published' => $published,
    ]);
    $product->variants()->create([
        'size_label' => '250ml',
        'product_code' => $code,
        'source_price_minor' => 1400,
        'price_minor' => 1300,
        'is_active' => $variantActive,
    ]);

    return $product;
}

function catalogueSlugBundle(string $name, string $slug, string $code): Bundle
{
    $product = catalogueSlugProduct('Bundle component', 'bundle-component', 'BUNDLE-COMPONENT');
    $bundle = Bundle::query()->create([
        'name' => $name,
        'slug' => $slug,
        'product_code' => $code,
        'price_minor' => 2500,
        'is_published' => true,
    ]);
    $bundle->items()->create(['product_variant_id' => $product->variants()->firstOrFail()->id, 'quantity' => 1]);

    return $bundle;
}

function catalogueSlugPriceLine(string $slug, string $productCode, array $overrides = []): array
{
    return array_replace([
        'slug' => $slug,
        'productCode' => $productCode,
        'quantity' => 1,
        'lineId' => 'line-1',
    ], $overrides);
}

function catalogueSlugIssues(array $line): array
{
    try {
        app(PriceCart::class)->price([$line]);
    } catch (HttpResponseException $exception) {
        return $exception->getResponse()->getData(true)['issues'];
    }

    throw new RuntimeException('Expected cart pricing to reject the line.');
}

it('prices a product through repeated slug renames and returns its current slug', function (): void {
    $product = catalogueSlugProduct('Shampoo', 'shampoo-a', 'SH-001');
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $product, ['slug' => 'shampoo-b']);
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $product->fresh(), ['slug' => 'shampoo-c']);

    $priced = app(PriceCart::class)->price([catalogueSlugPriceLine('shampoo-a', 'SH-001')]);

    expect($priced['lines'][0]['slug'])->toBe('shampoo-c')
        ->and($priced['lines'][0]['unitPriceMinor'])->toBe(1300);
});

it('prices a renamed bundle from its old slug and returns its current slug', function (): void {
    $bundle = catalogueSlugBundle('Routine set', 'routine-a', 'BD-001');
    app(ManageBundles::class)->update($this->catalogueSlugAdmin, $bundle, ['slug' => 'routine-b']);

    $priced = app(PriceCart::class)->price([catalogueSlugPriceLine('routine-a', 'BD-001')]);

    expect($priced['lines'][0]['slug'])->toBe('routine-b')
        ->and($priced['lines'][0]['lineType'])->toBe('BUNDLE')
        ->and($priced['lines'][0]['unitPriceMinor'])->toBe(2500);
});

it('keeps the exact product code and entity type checks for historical slugs', function (): void {
    $product = catalogueSlugProduct('Shampoo', 'shampoo-a', 'SH-001');
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $product, ['slug' => 'shampoo-b']);
    catalogueSlugProduct('Other product', 'other-product', 'OTHER-001');
    catalogueSlugBundle('Routine set', 'routine', 'BD-001');

    expect(catalogueSlugIssues(catalogueSlugPriceLine('shampoo-a', 'OTHER-001'))[0]['reason'])->toBe('ITEM_UNAVAILABLE')
        ->and(catalogueSlugIssues(catalogueSlugPriceLine('shampoo-a', 'BD-001'))[0]['reason'])->toBe('ITEM_UNAVAILABLE');
});

it('rejects historical slugs when the current product or variant is unavailable', function (): void {
    $hiddenProduct = catalogueSlugProduct('Hidden shampoo', 'hidden-a', 'HIDDEN-001');
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $hiddenProduct, ['slug' => 'hidden-b']);
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $hiddenProduct->fresh(), ['isPublished' => false]);

    $inactiveProduct = catalogueSlugProduct('Inactive shampoo', 'inactive-a', 'INACTIVE-001');
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $inactiveProduct, ['slug' => 'inactive-b']);
    $inactiveProduct->variants()->update(['is_active' => false]);

    expect(catalogueSlugIssues(catalogueSlugPriceLine('hidden-a', 'HIDDEN-001'))[0]['reason'])->toBe('ITEM_UNAVAILABLE')
        ->and(catalogueSlugIssues(catalogueSlugPriceLine('inactive-a', 'INACTIVE-001'))[0]['reason'])->toBe('ITEM_UNAVAILABLE');
});

it('still reports a changed price for a historical product slug', function (): void {
    $product = catalogueSlugProduct('Shampoo', 'shampoo-a', 'SH-001');
    app(ManageCatalog::class)->updateProduct($this->catalogueSlugAdmin, $product, ['slug' => 'shampoo-b']);

    $issue = catalogueSlugIssues(catalogueSlugPriceLine('shampoo-a', 'SH-001', ['expectedUnitPriceMinor' => 1299]));

    expect($issue[0]['reason'])->toBe('PRICE_CHANGED')
        ->and($issue[0]['priceMinor'])->toBe(1300);
});
