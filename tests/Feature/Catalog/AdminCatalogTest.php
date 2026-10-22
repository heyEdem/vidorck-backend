<?php

use App\Modules\Catalog\Actions\ManageBundles;
use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'catalog-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->sales = StaffUser::create(['name' => 'Sales', 'email' => 'catalog-sales@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::SALES_ORDERS]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'catalog-admin@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

function catalogAdminLoginSales(): void
{
    test()->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    test()->postJson('/api/v1/admin/auth/login', ['email' => 'catalog-sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
}

function catalogAdminCategory(): Category
{
    return Category::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo']);
}

function catalogAdminProduct(): Product
{
    $product = Product::query()->create(['name' => 'Natural Shampoo', 'slug' => 'natural-shampoo', 'category_id' => catalogAdminCategory()->id]);
    $product->variants()->create(['size_label' => 'B/s', 'product_code' => 'NAT-SH-001', 'source_price_minor' => 2500, 'price_minor' => 2700]);

    return $product;
}

it('allows catalogue reads to both roles and rejects every catalogue write for sales staff', function () {
    $product = catalogAdminProduct();
    catalogAdminLoginSales();

    $this->getJson('/api/v1/admin/products')->assertOk();
    $this->getJson('/api/v1/admin/products/'.$product->id)->assertOk();
    $this->getJson('/api/v1/admin/products/'.$product->id.'/variants')->assertOk();
    $this->getJson('/api/v1/admin/categories')->assertOk();
    $this->getJson('/api/v1/admin/bundles')->assertOk();
    $bundle = Bundle::query()->create(['name' => 'Sales bundle', 'slug' => 'sales-bundle',
        'product_code' => 'SALES-BUNDLE', 'price_minor' => 900]);

    foreach ([
        fn () => $this->postJson('/api/v1/admin/products', ['name' => 'No', 'categoryId' => $product->category_id]),
        fn () => $this->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'No']),
        fn () => $this->postJson('/api/v1/admin/products/'.$product->id.'/publish'),
        fn () => $this->postJson('/api/v1/admin/products/'.$product->id.'/unpublish'),
        fn () => $this->postJson('/api/v1/admin/products/'.$product->id.'/variants', []),
        fn () => $this->patchJson('/api/v1/admin/products/'.$product->id.'/variants/'.$product->variants->first()->id, ['priceMinor' => 3000]),
        fn () => $this->postJson('/api/v1/admin/categories', ['name' => 'No', 'slug' => 'no']),
        fn () => $this->patchJson('/api/v1/admin/categories/'.$product->category_id, ['name' => 'No']),
        fn () => $this->postJson('/api/v1/admin/bundles', []),
        fn () => $this->patchJson('/api/v1/admin/bundles/'.$bundle->id, ['priceMinor' => 300]),
        fn () => $this->postJson('/api/v1/admin/bundles/'.$bundle->id.'/publish'),
        fn () => $this->postJson('/api/v1/admin/bundles/'.$bundle->id.'/unpublish'),
    ] as $write) {
        $write()->assertForbidden();
    }
});

it('uses the documented AdminVariant shape in product details and standalone variant reads', function () {
    $product = catalogAdminProduct();
    $detail = $this->getJson('/api/v1/admin/products/'.$product->id)->assertOk()->json('data.variants.0');
    $standalone = $this->getJson('/api/v1/admin/products/'.$product->id.'/variants')->assertOk()->json('data.0');
    $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
    $required = $spec['components']['schemas']['AdminVariant']['required'];

    expect($detail)->toBe($standalone)
        ->and(array_diff($required, array_keys($detail)))->toBe([])
        ->and($detail['productId'])->toBe($product->id);
});

it('derives and updates prices, honors explicit prices, and skips audit and invalidation for no-op patches', function () {
    Queue::fake();
    $category = catalogAdminCategory();
    $hair = HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair']);
    $this->postJson('/api/v1/admin/products', ['name' => '💇', 'categoryId' => $category->id])
        ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
    expect(Product::query()->where('name', '💇')->exists())->toBeFalse();

    $created = $this->postJson('/api/v1/admin/products', ['name' => 'Natural Shampoo', 'slug' => 'natural-shampoo',
        'categoryId' => $category->id, 'hairTypeIds' => [$hair->id], 'variants' => [
            ['label' => 'B/s', 'productCode' => 'NAT-001', 'sourcePriceMinor' => 2500],
            ['label' => 'Large', 'productCode' => 'NAT-002', 'sourcePriceMinor' => 2500, 'priceMinor' => 3100],
        ]])->assertCreated()->assertJsonPath('data.variants.0.priceMinor', 2700)
        ->assertJsonPath('data.variants.0.sourcePriceMinor', 2500)->assertJsonPath('data.hairTypeIds', [$hair->id]);
    $productId = $created->json('data.id');
    $variantId = $created->json('data.variants.0.id');
    Queue::assertPushed(RevalidateStorefront::class, 1);

    $this->patchJson('/api/v1/admin/products/'.$productId.'/variants/'.$variantId, ['sourcePriceMinor' => 3000])
        ->assertOk()->assertJsonPath('data.priceMinor', 3200);
    $this->patchJson('/api/v1/admin/products/'.$productId.'/variants/'.$variantId, ['sourcePriceMinor' => null, 'priceMinor' => 4123])
        ->assertOk()->assertJsonPath('data.sourcePriceMinor', null)->assertJsonPath('data.priceMinor', 4123);
    $audits = AuditLog::count();
    $this->patchJson('/api/v1/admin/products/'.$productId.'/variants/'.$variantId, ['priceMinor' => 4123])->assertOk();
    expect(AuditLog::count())->toBe($audits);
    Queue::assertPushed(RevalidateStorefront::class, 3);
});

it('invalidates product and dependent bundle detail tags when a category changes and hides its public content', function () {
    Queue::fake();
    $product = catalogAdminProduct();
    $variant = $product->variants->first();
    $bundle = Bundle::query()->create(['name' => 'Category set', 'slug' => 'category-set', 'product_code' => 'CATEGORY-SET', 'price_minor' => 1500, 'is_published' => true]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);

    $this->patchJson('/api/v1/admin/categories/'.$product->category_id, ['isActive' => false])->assertOk();
    Queue::assertPushed(RevalidateStorefront::class, 1);
    Queue::assertPushed(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === [
        'bundle:category-set', 'bundles', 'categories', 'product:natural-shampoo', 'products',
    ]);
    $this->getJson('/api/v1/products/natural-shampoo')->assertNotFound();
    $this->getJson('/api/v1/bundles')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/bundles/category-set')->assertNotFound();
});

it('reports duplicate category names and slugs under their own fields', function () {
    $existing = catalogAdminCategory();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Shampoo', 'slug' => 'another-slug'])
        ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('errors.name.0', 'The name has already been taken.');
    $this->patchJson('/api/v1/admin/categories/'.$existing->id, ['slug' => 'shampoo'])
        ->assertOk();

    $other = Category::query()->create(['name' => 'Conditioner', 'slug' => 'conditioner']);
    $this->patchJson('/api/v1/admin/categories/'.$other->id, ['slug' => 'shampoo'])
        ->assertUnprocessable()->assertJsonPath('errors.slug.0', 'The slug has already been taken.');
});

it('reports duplicate normalized variant sizes under the label field', function () {
    $product = catalogAdminProduct();
    $this->postJson('/api/v1/admin/products/'.$product->id.'/variants', [
        'label' => '  b/S  ', 'productCode' => 'NAT-DUP-001', 'priceMinor' => 1700,
    ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.label.0', 'The normalized size has already been taken for this product.');
    expect($product->variants()->count())->toBe(1);
});

it('invalidates the product and each dependent bundle once after a variant price change', function () {
    Queue::fake();
    $product = catalogAdminProduct();
    $variant = $product->variants->first();
    $bundle = Bundle::query()->create(['name' => 'Dependent set', 'slug' => 'dependent-set',
        'product_code' => 'DEPENDENT-SET', 'price_minor' => 2000]);
    $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);

    $this->patchJson('/api/v1/admin/products/'.$product->id.'/variants/'.$variant->id, ['priceMinor' => 3000])->assertOk();

    Queue::assertPushed(RevalidateStorefront::class, 1);
    Queue::assertPushed(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === [
        'bundle:dependent-set', 'bundles', 'product:natural-shampoo', 'products',
    ]);
});

it('does not audit or invalidate no-op product and category patches', function () {
    Queue::fake();
    $product = catalogAdminProduct();
    $category = $product->category;
    $auditCount = AuditLog::count();

    $this->patchJson('/api/v1/admin/products/'.$product->id, ['name' => $product->name])->assertOk();
    $this->patchJson('/api/v1/admin/categories/'.$category->id, ['name' => $category->name])->assertOk();

    expect(AuditLog::count())->toBe($auditCount);
    Queue::assertNothingPushed();
});

it('flattens slug changes, reserves old slugs across catalogue entities, and publishes without no-op side effects', function () {
    Queue::fake();
    $category = catalogAdminCategory();
    $product = Product::query()->create(['name' => 'Old', 'slug' => 'old-product', 'category_id' => $category->id]);
    $product->variants()->create(['size_label' => 'One', 'product_code' => 'OLD-001', 'price_minor' => 1000]);
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'Middle', 'slug' => 'middle-product'])->assertOk();
    $this->patchJson('/api/v1/admin/products/'.$product->id, ['name' => 'Final', 'slug' => 'final-product'])->assertOk();
    expect(DB::table('slug_redirects')->where('entity_type', 'product')->where('old_slug', 'old-product')->value('new_slug'))
        ->toBe('final-product');
    $this->postJson('/api/v1/admin/bundles', ['name' => 'Collision', 'slug' => 'old-product', 'productCode' => 'COLLIDE',
        'priceMinor' => 900, 'items' => [['variantId' => $product->variants->first()->id, 'quantity' => 1]]])->assertUnprocessable();

    $this->postJson('/api/v1/admin/products/'.$product->id.'/publish')->assertOk()->assertJsonPath('data.isPublished', true);
    $audits = AuditLog::count();
    $this->postJson('/api/v1/admin/products/'.$product->id.'/publish')->assertOk();
    expect(AuditLog::count())->toBe($audits);
    Queue::assertPushed(RevalidateStorefront::class, 3);
    $this->postJson('/api/v1/admin/products/'.$product->id.'/unpublish')->assertOk()->assertJsonPath('data.isPublished', false);
});

it('scopes variant updates to their parent and rolls back nested writes on a unique conflict', function () {
    $first = catalogAdminProduct();
    $other = Product::query()->create(['name' => 'Other', 'slug' => 'other', 'category_id' => $first->category_id]);
    $foreignVariant = $other->variants()->create(['size_label' => 'One', 'product_code' => 'OTHER-001', 'price_minor' => 900]);
    $this->patchJson('/api/v1/admin/products/'.$first->id.'/variants/'.$foreignVariant->id, ['priceMinor' => 1300])->assertNotFound();
    expect($foreignVariant->fresh()->price_minor)->toBe(900);

    expect(fn () => app(ManageCatalog::class)->createProduct($this->admin, [
        'name' => 'Rolled Back', 'slug' => 'rolled-back', 'categoryId' => $first->category_id,
        'variants' => [
            ['label' => 'One', 'productCode' => 'ROLLBACK-001', 'priceMinor' => 1000],
            ['label' => 'Two', 'productCode' => 'ROLLBACK-001', 'priceMinor' => 1000],
        ],
    ]))->toThrow(ApiException::class);
    expect(Product::query()->where('slug', 'rolled-back')->exists())->toBeFalse();
});

it('rolls back product, variant, category, and bundle writes when the audit insert fails', function () {
    Queue::fake();
    $category = catalogAdminCategory();
    $product = Product::query()->create(['name' => 'Existing product', 'slug' => 'existing-product', 'category_id' => $category->id]);
    $variant = $product->variants()->create(['size_label' => 'One', 'product_code' => 'EXISTING-001', 'price_minor' => 1000]);
    DB::unprepared("CREATE TRIGGER reject_catalog_audits BEFORE INSERT ON audit_logs WHEN NEW.action LIKE 'catalog.%' BEGIN SELECT RAISE(ABORT, 'audit write denied'); END");

    expect(fn () => app(ManageCatalog::class)->createCategory($this->admin, ['name' => 'Rejected category', 'slug' => 'rejected-category']))
        ->toThrow(QueryException::class);
    expect(fn () => app(ManageCatalog::class)->createProduct($this->admin, ['name' => 'Rejected product', 'slug' => 'rejected-product', 'categoryId' => $category->id]))
        ->toThrow(QueryException::class);
    expect(fn () => app(ManageCatalog::class)->createVariant($this->admin, $product, ['label' => 'Two', 'productCode' => 'REJECTED-002', 'priceMinor' => 1200]))
        ->toThrow(QueryException::class);
    expect(fn () => app(ManageBundles::class)->create($this->admin, ['name' => 'Rejected set', 'slug' => 'rejected-set',
        'productCode' => 'REJECTED-SET', 'priceMinor' => 900, 'items' => [['variantId' => $variant->id, 'quantity' => 1]]]))
        ->toThrow(QueryException::class);

    expect(Category::query()->where('slug', 'rejected-category')->exists())->toBeFalse()
        ->and(Product::query()->where('slug', 'rejected-product')->exists())->toBeFalse()
        ->and(ProductVariant::query()->where('product_code', 'REJECTED-002')->exists())->toBeFalse()
        ->and(Bundle::query()->where('slug', 'rejected-set')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('rejects inactive, demoted, revoked, and forced-password actors inside catalogue and bundle transactions', function (array $changes) {
    Queue::fake();
    $stale = $this->admin;
    $category = catalogAdminCategory();
    $product = Product::query()->create(['name' => 'Component', 'slug' => 'component', 'category_id' => $category->id]);
    $variant = $product->variants()->create(['size_label' => 'One', 'product_code' => 'COMPONENT-001', 'price_minor' => 1000]);
    DB::table('staff_users')->where('id', $stale->id)->update($changes);

    expect(fn () => app(ManageCatalog::class)->createCategory($stale, ['name' => 'Stale category', 'slug' => 'stale-category']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageBundles::class)->create($stale, ['name' => 'Stale set', 'slug' => 'stale-set',
        'productCode' => 'STALE-SET', 'priceMinor' => 900, 'items' => [['variantId' => $variant->id, 'quantity' => 1]]]))
        ->toThrow(AuthorizationException::class);
    expect(Category::query()->where('slug', 'stale-category')->exists())->toBeFalse()
        ->and(Bundle::query()->where('slug', 'stale-set')->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'disabled' => [['is_active' => false]],
    'demoted' => [['role' => StaffRole::SALES_ORDERS->value]],
    'revoked' => [['session_version' => 2]],
    'forced password change' => [['must_change_password' => true]],
]);
