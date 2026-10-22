<?php

use App\Modules\Catalog\Actions\BundlePricing;
use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'bundle-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'bundle-admin@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

/** @return array{Product,ProductVariant} */
function adminBundleProduct(string $name, string $slug, string $code, int $price): array
{
    $category = Category::query()->firstOrCreate(['slug' => 'shampoo'], ['name' => 'Shampoo', 'is_active' => true]);
    $product = Product::query()->create(['name' => $name, 'slug' => $slug, 'category_id' => $category->id, 'is_published' => true]);
    $variant = $product->variants()->create(['size_label' => 'Standard', 'product_code' => $code, 'price_minor' => $price, 'is_active' => true]);

    return [$product, $variant];
}

it('computes the suggested bundle price as the component total less 300 pesewas', function () {
    $texturizer = adminBundleProduct('Texturizer', 'texturizer', 'TXTRZR-055', 2200)[1];
    $natural = [
        adminBundleProduct('Natural Shampoo', 'natural-shampoo', 'NTRLSHMP-BS-031', 2700)[1],
        adminBundleProduct('Natural Conditioner', 'natural-conditioner', 'CNDTR-003', 4200)[1],
        adminBundleProduct('Natural Pomade', 'natural-pomade', 'NTRLPMD-SS-035', 2200)[1],
        adminBundleProduct('Growth Oil', 'growth-oil', 'NTRLMRCL-OL-036', 1900)[1],
        $texturizer,
        adminBundleProduct('Original Super', 'original-super', 'RGNLSPR-039', 1400)[1],
    ];
    $permed = [
        adminBundleProduct('Fruit Shampoo', 'fruit-shampoo', 'FRTSHMP-002', 4200)[1],
        adminBundleProduct('Burns Oil', 'burns-oil', 'BRNS-OL-016', 2200)[1],
        adminBundleProduct('Treatment Oil', 'treatment-oil', 'TRTMNT-OL-054', 2200)[1],
        $texturizer,
        adminBundleProduct('Hair Bounce', 'hair-bounce', 'BNC-SS-013', 1200)[1],
        adminBundleProduct('Leave-in Conditioner', 'leave-in-conditioner', 'LVN-SS-027', 2700)[1],
    ];

    expect(app(BundlePricing::class)->suggestedPrice([
        ...array_map(fn (ProductVariant $variant): array => ['variantId' => $variant->id, 'quantity' => 1], $natural),
    ]))->toBe(14300)
        ->and(app(BundlePricing::class)->suggestedPrice([
            ...array_map(fn (ProductVariant $variant): array => ['variantId' => $variant->id, 'quantity' => 1], $permed),
        ]))->toBe(14400);
});

it('creates and replaces bundle items in order while preserving the stored authoritative price', function () {
    Queue::fake();
    [, $first] = adminBundleProduct('First product', 'first-product', 'FIRST-001', 7000);
    [, $second] = adminBundleProduct('Second product', 'second-product', 'SECOND-001', 5000);
    $created = $this->postJson('/api/v1/admin/bundles', ['name' => 'Routine Set', 'slug' => 'routine-set', 'productCode' => 'ROUTINE-001',
        'priceMinor' => 10999, 'isPublished' => true, 'items' => [
            ['variantId' => $second->id, 'quantity' => 2, 'displayOrder' => 8],
            ['variantId' => $first->id, 'quantity' => 1, 'displayOrder' => 2],
        ]])->assertCreated()->assertJsonPath('data.priceMinor', 10999)
        ->assertJsonPath('data.suggestedPriceMinor', 16700)->assertJsonPath('data.isPubliclyVisible', true)
        ->assertJsonPath('data.items.0.variantId', $first->id)->assertJsonPath('data.items.1.variantId', $second->id);
    $bundleId = $created->json('data.id');
    Queue::assertPushed(RevalidateStorefront::class, 1);

    $this->patchJson('/api/v1/admin/bundles/'.$bundleId, ['priceMinor' => 12000, 'items' => [
        ['variantId' => $second->id, 'quantity' => 3, 'displayOrder' => 0],
    ]])->assertOk()->assertJsonPath('data.priceMinor', 12000)->assertJsonPath('data.suggestedPriceMinor', 14700)
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('data.items.0.displayOrder', 0);
    expect(AuditLog::where('action', 'catalog.bundle.updated')->count())->toBe(1);
    Queue::assertPushed(RevalidateStorefront::class, 2);
    $auditCount = AuditLog::count();
    $this->patchJson('/api/v1/admin/bundles/'.$bundleId, ['priceMinor' => 12000, 'items' => [
        ['variantId' => $second->id, 'quantity' => 3, 'displayOrder' => 0],
    ]])->assertOk();
    expect(AuditLog::count())->toBe($auditCount);
    Queue::assertPushed(RevalidateStorefront::class, 2);
});

it('allows publishing a bundle that remains hidden when any component becomes inactive', function () {
    [, $variant] = adminBundleProduct('Available product', 'available-product', 'AVAILABLE-001', 8000);
    $bundle = Bundle::query()->create(['name' => 'Hidden set', 'slug' => 'hidden-set', 'product_code' => 'HIDDEN-SET-001',
        'price_minor' => 7700, 'is_published' => false]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1, 'display_order' => 0]);

    $this->postJson('/api/v1/admin/bundles/'.$bundle->id.'/publish')->assertOk()
        ->assertJsonPath('data.isPublished', true)->assertJsonPath('data.isPubliclyVisible', true);
    $variant->update(['is_active' => false]);
    $this->getJson('/api/v1/admin/bundles/'.$bundle->id)->assertOk()->assertJsonPath('data.isPubliclyVisible', false);
    $this->getJson('/api/v1/bundles/hidden-set')->assertNotFound();
});

it('hides a published bundle after its component product is unpublished and invalidates that bundle detail', function () {
    Queue::fake();
    [$product, $variant] = adminBundleProduct('Available product', 'unpublished-component', 'UNPUBLISHED-001', 8000);
    $hair = HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair']);
    $product->hairTypes()->attach($hair->id);
    $bundle = Bundle::query()->create(['name' => 'Visible set', 'slug' => 'visible-set', 'product_code' => 'VISIBLE-SET-001',
        'price_minor' => 7700, 'is_published' => true]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/admin/products/'.$product->id.'/unpublish')->assertOk();

    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/bundles/visible-set')->assertNotFound();
    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?q=Available')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?category=shampoo')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?hair_type=natural-hair')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products/unpublished-component')->assertNotFound();
    Queue::assertPushed(RevalidateStorefront::class, 1);
    Queue::assertPushed(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => in_array('bundle:visible-set', $job->tags, true));
});

it('flattens bundle slug history on each rename and rejects historical slug reuse', function () {
    [, $variant] = adminBundleProduct('Product', 'component-product', 'COMPONENT-001', 1000);
    $bundle = Bundle::query()->create(['name' => 'Old set', 'slug' => 'old-set', 'product_code' => 'OLD-SET-001', 'price_minor' => 900]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->patchJson('/api/v1/admin/bundles/'.$bundle->id, ['name' => 'Middle set', 'slug' => 'middle-set'])->assertOk();
    $this->patchJson('/api/v1/admin/bundles/'.$bundle->id, ['name' => 'Final set', 'slug' => 'final-set'])->assertOk();

    expect(DB::table('slug_redirects')->where('entity_type', 'bundle')->where('old_slug', 'old-set')->value('new_slug'))
        ->toBe('final-set');
    $this->postJson('/api/v1/admin/products', ['name' => 'Reserved name', 'slug' => 'old-set',
        'categoryId' => Category::query()->where('slug', 'shampoo')->value('id')])->assertUnprocessable();
});

it('never suggests a negative bundle price', function () {
    $variant = adminBundleProduct('Sample Sachet', 'sample-sachet', 'SMPL-001', 100)[1];

    expect(BundlePricing::fromSubtotal(100))->toBe(0)
        ->and(app(BundlePricing::class)->suggestedPrice([['variantId' => $variant->id, 'quantity' => 1]]))->toBe(0);
});

it('rejects bundle items sent as a keyed object instead of a list', function () {
    $variant = adminBundleProduct('Keyed Shampoo', 'keyed-shampoo', 'KYD-001', 2700)[1];

    $this->postJson('/api/v1/admin/bundles', ['name' => 'Keyed Bundle', 'priceMinor' => 2400,
        'items' => ['a' => ['variantId' => $variant->id, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['items']);
});
