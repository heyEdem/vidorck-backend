<?php

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\SlugRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $overrides */
function publicCatalogProduct(array $overrides = []): Product
{
    $category = Category::query()->firstOrCreate(
        ['slug' => 'hair-care'], ['name' => 'Hair care', 'is_active' => true, 'display_order' => 2],
    );

    return Product::query()->create(array_merge([
        'name' => 'Crème Shampoo',
        'slug' => 'creme-shampoo',
        'category_id' => $category->id,
        'is_published' => true,
        'display_order' => 1,
    ], $overrides));
}

function publicCatalogVariant(Product $product, string $code = 'CR-SM-001', bool $active = true): ProductVariant
{
    return $product->variants()->create([
        'size_label' => 'Standard',
        'product_code' => $code,
        'source_price_minor' => 2000,
        'price_minor' => 2200,
        'is_active' => $active,
    ]);
}

it('lists only publicly visible products and exposes a safe storefront shape with global facets', function () {
    $hair = HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair', 'display_order' => 0]);
    $visible = publicCatalogProduct();
    $visible->hairTypes()->attach($hair->id);
    publicCatalogVariant($visible);

    $draft = publicCatalogProduct(['name' => 'Draft Shampoo', 'slug' => 'draft-shampoo', 'is_published' => false]);
    publicCatalogVariant($draft, 'DRAFT-001');
    $withoutActiveVariant = publicCatalogProduct(['name' => 'Unavailable Shampoo', 'slug' => 'unavailable-shampoo']);
    publicCatalogVariant($withoutActiveVariant, 'INACTIVE-001', false);
    $inactiveCategory = Category::query()->create(['name' => 'Hidden category', 'slug' => 'hidden-category', 'is_active' => false]);
    $hiddenCategoryProduct = publicCatalogProduct(['name' => 'Hidden category item', 'slug' => 'hidden-category-item', 'category_id' => $inactiveCategory->id]);
    publicCatalogVariant($hiddenCategoryProduct, 'HIDDEN-001');

    $response = $this->getJson('/api/v1/products');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'creme-shampoo')
        ->assertJsonPath('data.0.category', 'Hair care')
        ->assertJsonPath('data.0.image', null)
        ->assertJsonPath('data.0.hairTypes', ['Natural Hair'])
        ->assertJsonPath('data.0.variants.0', ['label' => 'Standard', 'productCode' => 'CR-SM-001', 'priceMinor' => 2200])
        ->assertJsonPath('meta.facets.categories.0', ['slug' => 'hair-care', 'name' => 'Hair care', 'count' => 1])
        ->assertJsonPath('meta.facets.hairTypes.0', ['slug' => 'natural-hair', 'name' => 'Natural Hair', 'count' => 1]);

    expect($response->getContent())->not->toContain('sourcePriceMinor')->not->toContain('source_price_minor')->not->toContain('"id"');
});

it('normalizes accent-insensitive search, escapes wildcard characters, filters by category and hair type, and paginates at 15', function () {
    $hair = HairType::query()->create(['name' => 'Permed Hair', 'slug' => 'permed-hair']);
    $matching = publicCatalogProduct();
    $matching->hairTypes()->attach($hair->id);
    publicCatalogVariant($matching);

    for ($index = 1; $index <= 16; $index++) {
        $product = publicCatalogProduct([
            'name' => 'Crème Shampoo '.$index,
            'slug' => 'creme-shampoo-'.$index,
            'display_order' => $index + 1,
        ]);
        publicCatalogVariant($product, 'CR-SM-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT));
    }

    $this->getJson('/api/v1/products?q=CREME&category=Hair%20care&hair_type=permed-hair')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'creme-shampoo');
    $this->getJson('/api/v1/products?q=%25')
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?page=2')
        ->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.page', 2)->assertJsonPath('meta.perPage', 15)->assertJsonPath('meta.total', 17);
});

it('resolves only safe product and bundle redirect chains and hides unavailable targets', function () {
    $product = publicCatalogProduct(['slug' => 'current-product']);
    publicCatalogVariant($product);
    SlugRedirect::query()->create(['entity_type' => 'product', 'old_slug' => 'old-product', 'new_slug' => 'middle-product']);
    SlugRedirect::query()->create(['entity_type' => 'product', 'old_slug' => 'middle-product', 'new_slug' => 'current-product']);

    $this->get('/api/v1/products/old-product')->assertStatus(301)
        ->assertHeader('Location', '/api/v1/products/current-product')
        ->assertExactJson(['redirectTo' => 'current-product']);

    $draft = publicCatalogProduct(['name' => 'Secret Draft', 'slug' => 'secret-draft', 'is_published' => false]);
    publicCatalogVariant($draft, 'SECRET-001');
    SlugRedirect::query()->create(['entity_type' => 'product', 'old_slug' => 'old-secret', 'new_slug' => 'secret-draft']);
    $this->getJson('/api/v1/products/old-secret')->assertNotFound();
    $this->getJson('/api/v1/products/secret-draft')->assertNotFound();

    SlugRedirect::query()->create(['entity_type' => 'product', 'old_slug' => 'cycle-a', 'new_slug' => 'cycle-b']);
    SlugRedirect::query()->create(['entity_type' => 'product', 'old_slug' => 'cycle-b', 'new_slug' => 'cycle-a']);
    $this->getJson('/api/v1/products/cycle-a')->assertNotFound();
});

it('returns visible bundles with stored prices and safe nested products and variants', function () {
    $product = publicCatalogProduct();
    $variant = publicCatalogVariant($product);
    $bundle = Bundle::query()->create([
        'name' => 'Shampoo Kit', 'slug' => 'shampoo-kit', 'product_code' => 'BNDL-SM-001',
        'eyebrow' => 'Routine', 'price_minor' => 1234, 'is_published' => true,
    ]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 2]);

    $unavailableBundle = Bundle::query()->create([
        'name' => 'Hidden kit', 'slug' => 'hidden-kit', 'product_code' => 'BNDL-HIDDEN',
        'price_minor' => 1, 'is_published' => true,
    ]);
    $inactiveVariant = $product->variants()->create([
        'size_label' => 'Small', 'product_code' => 'CR-SM-002', 'source_price_minor' => 100,
        'price_minor' => 300, 'is_active' => false,
    ]);
    $unavailableBundle->items()->create(['product_variant_id' => $inactiveVariant->id, 'quantity' => 1]);

    $response = $this->getJson('/api/v1/bundles/shampoo-kit');
    $response->assertOk()
        ->assertJsonPath('data.slug', 'shampoo-kit')
        ->assertJsonPath('data.priceMinor', 1234)
        ->assertJsonPath('data.currency', 'GHS')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.product.slug', 'creme-shampoo')
        ->assertJsonPath('data.items.0.variant.productCode', 'CR-SM-001');
    expect($response->getContent())->not->toContain('sourcePriceMinor')->not->toContain('"id"');
    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/bundles/hidden-kit')->assertNotFound();
});

it('lists active categories and hair types in stable safe shapes', function () {
    Category::query()->create(['name' => 'Second', 'slug' => 'second', 'display_order' => 1, 'is_active' => true]);
    Category::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'display_order' => 0, 'is_active' => false]);
    HairType::query()->create(['name' => 'Permed Hair', 'slug' => 'permed-hair', 'display_order' => 1]);
    HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair', 'display_order' => 0]);

    $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', ['name' => 'Second', 'slug' => 'second', 'displayOrder' => 1]);
    $this->getJson('/api/v1/hair-types')->assertOk()->assertJsonPath('data.0.slug', 'natural-hair');
});

it('keeps the storefront product fixture field names and current Natural Shampoo values', function () {
    $category = Category::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'display_order' => 7]);
    $product = publicCatalogProduct([
        'name' => 'Natural Shampoo',
        'slug' => 'natural-shampoo',
        'category_id' => $category->id,
        'image_position' => 'center 35%',
        'is_new_look' => true,
    ]);
    $hairType = HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair']);
    $product->hairTypes()->attach($hairType->id);
    $product->variants()->create([
        'size_label' => 'B/s',
        'product_code' => 'NTRLSHMP-BS-031',
        'source_price_minor' => 2500,
        'price_minor' => 2700,
    ]);

    $this->getJson('/api/v1/products/natural-shampoo')
        ->assertOk()
        ->assertJsonPath('data', [
            'slug' => 'natural-shampoo',
            'name' => 'Natural Shampoo',
            'category' => 'Shampoo',
            'image' => null,
            'imagePosition' => 'center 35%',
            'isNewLook' => true,
            'hairTypes' => ['Natural Hair'],
            'variants' => [['label' => 'B/s', 'productCode' => 'NTRLSHMP-BS-031', 'priceMinor' => 2700]],
            'currency' => 'GHS',
        ]);
});

it('hides an unpublished product from listings, normalized search, category and hair filters, and slug lookup', function () {
    $category = Category::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo']);
    $hairType = HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair']);
    $product = publicCatalogProduct([
        'name' => 'Natural Shampoo',
        'slug' => 'natural-shampoo',
        'category_id' => $category->id,
    ]);
    $product->hairTypes()->attach($hairType->id);
    $variant = publicCatalogVariant($product, 'NTRLSHMP-BS-031');

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?q=Natural%20Shamp%C3%B3o')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'natural-shampoo');
    $this->getJson('/api/v1/products?category=shampoo&hair_type=natural-hair')
        ->assertOk()->assertJsonCount(1, 'data');

    $product->update(['is_published' => false]);

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?q=Natural%20Shamp%C3%B3o')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?category=shampoo&hair_type=natural-hair')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products/natural-shampoo')->assertNotFound();
    expect($variant->fresh()->is_active)->toBeTrue();
});

it('removes a published bundle from list and detail when its component product is unpublished', function () {
    $product = publicCatalogProduct(['name' => 'Bundle Component', 'slug' => 'bundle-component']);
    $variant = publicCatalogVariant($product, 'COMPONENT-001');
    $bundle = Bundle::query()->create([
        'name' => 'Component Set', 'slug' => 'component-set', 'product_code' => 'BNDL-COMP-001',
        'price_minor' => 900, 'is_published' => true,
    ]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);

    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/bundles/component-set')->assertOk()->assertJsonPath('data.slug', 'component-set');

    $product->update(['is_published' => false]);

    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/bundles/component-set')->assertNotFound();
});

it('hides products and their bundles after their category becomes inactive', function () {
    $category = Category::query()->firstOrCreate(
        ['slug' => 'hair-care'], ['name' => 'Hair care', 'is_active' => true, 'display_order' => 2],
    );
    $product = publicCatalogProduct(['name' => 'Category Item', 'slug' => 'category-item']);
    $variant = publicCatalogVariant($product, 'CATEGORY-ITEM-001');
    $bundle = Bundle::query()->create([
        'name' => 'Category Set', 'slug' => 'category-set', 'product_code' => 'BNDL-CAT-001',
        'price_minor' => 1000, 'is_published' => true,
    ]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);

    $this->getJson('/api/v1/products/category-item')->assertOk();
    $this->getJson('/api/v1/bundles/category-set')->assertOk();

    $category->update(['is_active' => false]);

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products/category-item')->assertNotFound();
    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/bundles/category-set')->assertNotFound();
});

it('ignores empty search and filter parameters instead of rejecting them', function () {
    publicCatalogVariant(publicCatalogProduct());

    $this->getJson('/api/v1/products?q=&category=&hair_type=')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/products?q=%20')->assertOk()->assertJsonPath('meta.total', 1);
});
