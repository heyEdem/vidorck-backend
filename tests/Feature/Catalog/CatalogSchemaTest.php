<?php

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Media;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SlugRedirect;
use App\Modules\Catalog\Support\CatalogNormalizer;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $overrides */
function makeCatalogProduct(array $overrides = []): Product
{
    $category = Category::query()->firstOrCreate(
        ['slug' => 'shampoo'], ['name' => 'Shampoo', 'is_active' => true],
    );

    return Product::query()->create(array_merge([
        'name' => 'Natural Shampoo',
        'slug' => 'natural-shampoo',
        'category_id' => $category->id,
        'is_published' => true,
    ], $overrides));
}

it('creates catalogue records with foreign keys and uniqueness constraints', function () {
    $product = makeCatalogProduct();
    $variant = $product->variants()->create([
        'size_label' => 'B/s', 'product_code' => 'NTRL-BS-001',
        'source_price_minor' => 2500, 'price_minor' => 2700,
    ]);

    expect($variant->normalized_size)->toBe('b/s')
        ->and(fn () => DB::table('product_variants')->insert([
            'product_id' => $product->id,
            'size_label' => 'b/S',
            'normalized_size' => 'b/s',
            'product_code' => 'OTHER-CODE',
            'source_price_minor' => 1,
            'price_minor' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('product_variants')->insert([
        'product_id' => 99999,
        'size_label' => 'Large',
        'normalized_size' => 'large',
        'product_code' => 'ORPHAN',
        'source_price_minor' => 1,
        'price_minor' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('products')->where('id', $product->id)->delete())
        ->toThrow(QueryException::class);

    $explicitPriceVariant = $product->variants()->create([
        'size_label' => 'Large', 'product_code' => 'NTRL-LG-001', 'price_minor' => 4321,
    ]);

    expect($explicitPriceVariant->fresh()->source_price_minor)->toBeNull();
});

it('normalizes product names and variant sizes on save', function () {
    expect(CatalogNormalizer::normalize('  Crème   BRÛLÉE  '))->toBe('creme brulee');

    $product = makeCatalogProduct(['name' => '  Crème   Shampoo  ']);
    $variant = $product->variants()->create([
        'size_label' => '  B/s   Large ', 'product_code' => 'CREME-BS-001',
        'source_price_minor' => 1000, 'price_minor' => 1200,
    ]);

    expect($product->fresh()->normalized_name)->toBe('creme shampoo')
        ->and($variant->fresh()->normalized_size)->toBe('b/s large');
});

it('seeds structural catalogue data idempotently without products or bundles', function () {
    $seeder = app(CatalogSeeder::class);
    $seeder->run();
    $seeder->run();

    expect(Category::query()->count())->toBe(11)
        ->and(HairType::query()->count())->toBe(2)
        ->and(Product::query()->count())->toBe(0)
        ->and(Bundle::query()->count())->toBe(0)
        ->and(HairType::query()->orderBy('display_order')->pluck('name')->all())->toBe(['Natural Hair', 'Permed Hair']);
});

it('seeds structural catalogue data in production without creating development staff', function () {
    app()->detectEnvironment(fn (): string => 'production');

    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(Category::query()->count())->toBe(11)
        ->and(HairType::query()->count())->toBe(2)
        ->and(Product::query()->count())->toBe(0)
        ->and(Bundle::query()->count())->toBe(0)
        ->and(DB::table('staff_users')->count())->toBe(0);
});

it('preserves admin category edits when structural data is seeded again', function () {
    $seeder = app(CatalogSeeder::class);
    $seeder->run();
    $shampoo = Category::query()->where('slug', 'shampoo')->sole();
    $shampoo->update(['slug' => 'shampoos', 'display_order' => 42, 'is_active' => false]);
    Category::query()->where('slug', 'relaxer')->update(['name' => 'Gentle relaxer']);

    $seeder->run();

    expect(Category::query()->count())->toBe(11)
        ->and($shampoo->fresh()->slug)->toBe('shampoos')
        ->and($shampoo->fresh()->display_order)->toBe(42)
        ->and($shampoo->fresh()->is_active)->toBeFalse()
        ->and(Category::query()->where('slug', 'relaxer')->sole()->name)->toBe('Gentle relaxer');
});

it('rejects negative catalogue prices even when writes bypass form validation', function () {
    $product = makeCatalogProduct();
    $variant = $product->variants()->create([
        'size_label' => 'Standard', 'product_code' => 'PRICE-GUARD', 'price_minor' => 200,
    ]);

    expect(fn () => DB::table('product_variants')->where('id', $variant->id)->update(['price_minor' => -1]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('product_variants')->where('id', $variant->id)->update(['source_price_minor' => -1]))
        ->toThrow(QueryException::class);
    expect(fn () => $product->variants()->create([
        'size_label' => 'Negative', 'product_code' => 'NEGATIVE', 'price_minor' => -1,
    ]))->toThrow(QueryException::class);
    expect(fn () => Bundle::query()->create([
        'name' => 'Negative', 'slug' => 'negative', 'product_code' => 'NEGATIVE-SET', 'price_minor' => -1,
    ]))->toThrow(QueryException::class);
    $bundle = Bundle::query()->create([
        'name' => 'Valid', 'slug' => 'valid', 'product_code' => 'VALID-SET', 'price_minor' => 200,
    ]);
    expect(fn () => DB::table('bundles')->where('id', $bundle->id)->update(['price_minor' => -1]))
        ->toThrow(QueryException::class);
});

it('shows only published products with active variants and active categories', function () {
    $visible = makeCatalogProduct();
    $visible->variants()->create([
        'size_label' => 'Standard', 'product_code' => 'SHMP-001',
        'source_price_minor' => 1000, 'price_minor' => 1200,
    ]);

    $noVariant = makeCatalogProduct(['name' => 'No Variant', 'slug' => 'no-variant']);
    $unpublished = makeCatalogProduct(['name' => 'Draft', 'slug' => 'draft', 'is_published' => false]);
    $unpublished->variants()->create([
        'size_label' => 'Standard', 'product_code' => 'DRAFT-001',
        'source_price_minor' => 1000, 'price_minor' => 1200,
    ]);
    $inactiveCategory = Category::query()->create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);
    $deadCategoryProduct = makeCatalogProduct([
        'name' => 'Dead Category', 'slug' => 'dead-category', 'category_id' => $inactiveCategory->id,
    ]);
    $deadCategoryProduct->variants()->create([
        'size_label' => 'Standard', 'product_code' => 'DEAD-001',
        'source_price_minor' => 1000, 'price_minor' => 1200,
    ]);

    expect(Product::query()->visible()->pluck('id')->all())->toBe([$visible->id]);
    expect($noVariant->variants)->toHaveCount(0);
});

it('hides published bundles with no items or any unavailable component', function () {
    $product = makeCatalogProduct();
    $variant = $product->variants()->create([
        'size_label' => 'Standard', 'product_code' => 'BUNDLE-001',
        'source_price_minor' => 1000, 'price_minor' => 1200,
    ]);
    $visible = Bundle::query()->create([
        'name' => 'Visible', 'slug' => 'visible', 'product_code' => 'VISIBLE-BUNDLE', 'price_minor' => 1200, 'is_published' => true,
    ]);
    $visible->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);

    $empty = Bundle::query()->create([
        'name' => 'Empty', 'slug' => 'empty', 'product_code' => 'EMPTY-BUNDLE', 'price_minor' => 1200, 'is_published' => true,
    ]);

    $inactiveVariant = $product->variants()->create([
        'size_label' => 'Small', 'product_code' => 'BUNDLE-002',
        'source_price_minor' => 1000, 'price_minor' => 1200, 'is_active' => false,
    ]);
    $invalid = Bundle::query()->create([
        'name' => 'Invalid', 'slug' => 'invalid', 'product_code' => 'INVALID-BUNDLE', 'price_minor' => 1200, 'is_published' => true,
    ]);
    $invalid->items()->create(['product_variant_id' => $inactiveVariant->id, 'quantity' => 1]);

    expect(Bundle::query()->visible()->pluck('id')->all())->toBe([$visible->id])
        ->and($empty->items)->toHaveCount(0)
        ->and(BundleItem::query()->where('bundle_id', $invalid->id)->exists())->toBeTrue();

    expect(fn () => $visible->items()->create(['product_variant_id' => $variant->id, 'quantity' => 0]))
        ->toThrow(QueryException::class);
});

it('allows only one active media record for each polymorphic owner', function () {
    $product = makeCatalogProduct();
    $mediaFields = [
        'public_id' => str_repeat('a', 26),
        'original_path' => 'media/a/original.jpg',
        'variants_json' => ['400' => 'media/a/400.webp'],
        'width' => 1200,
        'height' => 800,
        'mime' => 'image/jpeg',
        'bytes' => 1234,
    ];
    $activeMedia = $product->media()->create($mediaFields);

    expect(fn () => $product->media()->create(array_merge($mediaFields, [
        'public_id' => str_repeat('b', 26),
    ])))->toThrow(QueryException::class);

    $activeMedia->update(['retired_at' => now()]);
    $newMedia = $product->media()->create(array_merge($mediaFields, [
        'public_id' => str_repeat('c', 26),
    ]));

    expect(Media::query()->where('owner_type', Product::class)->where('owner_id', $product->id)
        ->whereNull('retired_at')->count())->toBe(1)
        ->and($newMedia->retired_at)->toBeNull();
});

it('uniquely identifies redirects by entity and old slug', function () {
    SlugRedirect::query()->create(['entity_type' => 'product', 'old_slug' => 'old-name', 'new_slug' => 'new-name']);
    SlugRedirect::query()->create(['entity_type' => 'bundle', 'old_slug' => 'old-name', 'new_slug' => 'bundle-name']);

    expect(fn () => SlugRedirect::query()->create([
        'entity_type' => 'product', 'old_slug' => 'old-name', 'new_slug' => 'another-name',
    ]))->toThrow(QueryException::class);
});
