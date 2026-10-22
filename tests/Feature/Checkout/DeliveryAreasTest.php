<?php

use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Actions\ManageDeliveryAreas;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function deliveryAreaAdminLogin(string $email = 'delivery-sales@example.com'): void
{
    test()->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    test()->postJson('/api/v1/admin/auth/login', ['email' => $email, 'password' => 'Long-Staff-Password9!'])->assertOk();
}

it('lists the 48 active seeded areas publicly in display order with only public fields', function () {
    app(DeliveryAreaSeeder::class)->run();

    $response = $this->getJson('/api/v1/delivery-areas')->assertOk()->assertJsonCount(48, 'data');

    expect(collect($response->json('data'))->pluck('name')->all())->toBe([
        'Accra Central', 'Osu', 'Labone', 'Cantonments', 'Airport Residential', 'Roman Ridge', 'Dzorwulu', 'Abelemkpe',
        'Achimota', 'Tesano', 'Abeka', 'Lapaz', 'Kaneshie', 'Dansoman', 'Darkuman', 'Sowutuom', 'Kwabenya', 'Haatso',
        'North Legon', 'Madina', 'Adenta', 'East Legon', 'East Legon Hills', 'Adjiringanor', 'Ashaley Botwe', 'Lakeside',
        'Oyarifa', 'Amrahia', 'Oyibi', 'Nima', 'Kokomlemle', 'La', 'Labadi', 'Teshie', 'Nungua', 'Spintex', 'Baatsona',
        'Sakumono', 'Lashibi', 'Weija', 'Gbawe', 'Mallam', 'McCarthy Hill', 'Bortianor', 'Ngleshie Amanfrom', 'Kasoa',
        'Ofaakor', 'Opeikuma',
    ]);
    expect($response->getContent())->not->toContain('"id"')->not->toContain('isActive')->not->toContain('displayOrder');
    expect($response->json('data.0'))->toBe(['name' => 'Accra Central', 'aliases' => []]);
});

it('hides inactive areas publicly and includes them in the catalogue reader admin list', function () {
    app(DeliveryAreaSeeder::class)->run();
    DeliveryArea::query()->where('name', 'Osu')->update(['is_active' => false]);
    $public = $this->getJson('/api/v1/delivery-areas')->assertOk();
    expect($public->json('data'))->toHaveCount(47);
    expect(collect($public->json('data'))->pluck('name'))->not->toContain('Osu');

    StaffUser::create(['name' => 'Sales', 'email' => 'delivery-sales@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::SALES_ORDERS]);
    deliveryAreaAdminLogin();
    $admin = $this->getJson('/api/v1/admin/delivery-areas')->assertOk()->assertJsonCount(48, 'data');
    $osu = collect($admin->json('data'))->firstWhere('name', 'Osu');
    expect($osu)->toMatchArray(['name' => 'Osu', 'aliases' => [], 'isActive' => false, 'displayOrder' => 1]);
    expect($osu)->toHaveKey('id');
    expect($admin->getContent())->not->toContain('aliases_json')->not->toContain('is_active')->not->toContain('display_order');
});

it('requires a fresh active staff session for admin delivery areas', function () {
    $this->getJson('/api/v1/admin/delivery-areas')->assertUnauthorized();

    $staff = StaffUser::create(['name' => 'Sales', 'email' => 'delivery-sales@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::SALES_ORDERS]);
    deliveryAreaAdminLogin();
    $this->getJson('/api/v1/admin/delivery-areas')->assertOk();

    $staff->update(['is_active' => false]);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/delivery-areas')->assertUnauthorized();
});

it('creates a delivery area with safe defaults, an audit event, and one storefront revalidation', function () {
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'Unauthenticated'])->assertUnauthorized();
    $sales = StaffUser::create(['name' => 'Sales', 'email' => 'delivery-sales@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::SALES_ORDERS]);
    deliveryAreaAdminLogin();
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => '  New Area  '])->assertForbidden();

    $admin = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    deliveryAreaAdminLogin('delivery-admin@example.com');
    Queue::fake();

    $response = $this->postJson('/api/v1/admin/delivery-areas', [
        'name' => '  New Area  ', 'aliases' => ['  New  ', 'Alias'], 'isActive' => false, 'displayOrder' => 4,
        'is_admin' => true,
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'New Area')
        ->assertJsonPath('data.aliases', ['New', 'Alias'])
        ->assertJsonPath('data.isActive', false)->assertJsonPath('data.displayOrder', 4);
    expect($response->json('data'))->toHaveKeys(['id', 'name', 'aliases', 'isActive', 'displayOrder'])
        ->and($response->json('data'))->not->toHaveKey('is_admin');
    expect(DeliveryArea::query()->where('name', 'New Area')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'delivery_area.created')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'delivery_area.created')->firstOrFail()->after)->toMatchArray([
        'name' => 'New Area', 'aliases' => ['New', 'Alias'], 'isActive' => false, 'displayOrder' => 4,
    ]);
    Queue::assertPushed(RevalidateStorefront::class, 1);
    Queue::assertPushed(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === ['delivery-areas']);
});

it('rejects invalid aliases and collisions with every existing canonical name and alias', function () {
    $admin = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    deliveryAreaAdminLogin('delivery-admin@example.com');
    $existing = DeliveryArea::query()->create(['name' => 'Tema', 'aliases_json' => ['Community 1'], 'is_active' => false]);

    $this->postJson('/api/v1/admin/delivery-areas', [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'T'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'New', 'aliases' => ['One', 'one']])->assertUnprocessable()->assertJsonValidationErrors(['aliases.1']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'Tema'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'Community 1'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'New', 'aliases' => ['tema']])->assertUnprocessable()->assertJsonValidationErrors(['aliases']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'New', 'aliases' => ['community 1']])->assertUnprocessable()->assertJsonValidationErrors(['aliases']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'New', 'aliases' => ['new']])->assertUnprocessable()->assertJsonValidationErrors(['aliases']);
    $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'New', 'aliases' => array_fill(0, 21, 'Alias')])->assertUnprocessable()->assertJsonValidationErrors(['aliases']);

    expect(DeliveryArea::query()->count())->toBe(1)->and($existing->fresh()->is_active)->toBeFalse();
});

it('defaults new delivery areas to active with no aliases and display order zero', function () {
    StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    deliveryAreaAdminLogin('delivery-admin@example.com');
    Queue::fake();

    $response = $this->postJson('/api/v1/admin/delivery-areas', ['name' => 'Default Area'])->assertCreated();

    $response->assertJsonPath('data.aliases', [])->assertJsonPath('data.isActive', true)
        ->assertJsonPath('data.displayOrder', 0);
    expect(DeliveryArea::query()->where('name', 'Default Area')->value('is_active'))->toBeTrue();
});

it('rechecks the actor inside delivery-area creation before any write', function (array $changes) {
    $actor = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    $staleActor = clone $actor;
    $actor->forceFill($changes)->save();

    expect(fn () => app(ManageDeliveryAreas::class)->create($staleActor, ['name' => 'Blocked']))
        ->toThrow(AuthorizationException::class);
    expect(DeliveryArea::query()->count())->toBe(0);
})->with([
    'demoted' => [['role' => StaffRole::SALES_ORDERS]],
    'disabled' => [['is_active' => false]],
    'revoked session' => [['session_version' => 2]],
    'password change required' => [['must_change_password' => true]],
]);

it('rolls back delivery-area creation when the audit insert fails and dispatches no revalidation', function () {
    $actor = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    DB::unprepared("CREATE TRIGGER fail_delivery_area_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    Queue::fake();

    expect(fn () => app(ManageDeliveryAreas::class)->create($actor, ['name' => 'Never committed']))
        ->toThrow(QueryException::class);

    expect(DeliveryArea::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('partially updates a delivery area, preserves unspecified fields, and audits and revalidates once', function () {
    $actor = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    deliveryAreaAdminLogin('delivery-admin@example.com');
    $area = DeliveryArea::query()->create(['name' => 'Harbor', 'aliases_json' => ['Old Name'], 'is_active' => true, 'display_order' => 2]);
    Queue::fake();

    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, [
        'name' => 'New Harbor', 'aliases' => ['New Name'], 'displayOrder' => 8,
    ])->assertOk()->assertJsonPath('data.name', 'New Harbor')
        ->assertJsonPath('data.aliases', ['New Name'])->assertJsonPath('data.isActive', true)
        ->assertJsonPath('data.displayOrder', 8);
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['name' => 'New Harbor', 'aliases' => ['New Name']])->assertOk();
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, [])->assertOk();

    $audit = AuditLog::query()->where('action', 'delivery_area.updated')->sole();
    expect($area->fresh()->is_active)->toBeTrue()
        ->and($audit->before)->toMatchArray(['name' => 'Harbor', 'aliases' => ['Old Name'], 'isActive' => true, 'displayOrder' => 2])
        ->and($audit->after)->toMatchArray(['name' => 'New Harbor', 'aliases' => ['New Name'], 'isActive' => true, 'displayOrder' => 8]);
    Queue::assertPushed(RevalidateStorefront::class, 1);
    Queue::assertPushed(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === ['delivery-areas']);
});

it('accepts a newly configured active alias during guest checkout', function () {
    config(['services.paystack.secret_key' => 'sk_test_secret', 'services.paystack.base_url' => 'https://api.paystack.co',
        'services.paystack.callback_url' => null, 'services.storefront.url' => 'https://shop.example']);
    $admin = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    deliveryAreaAdminLogin('delivery-admin@example.com');
    $area = DeliveryArea::query()->create(['name' => 'Harbor', 'aliases_json' => ['Old Name'], 'is_active' => true]);
    Queue::fake();
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['aliases' => ['Harbour']])->assertOk();

    Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => 1, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1400,
        'price_minor' => 1300, 'is_active' => true]);
    Http::fake(['https://api.paystack.co/transaction/initialize' => fn ($request) => Http::response([
        'status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/abc'],
    ])]);

    $this->postJson('/api/v1/checkout', [
        'fullName' => 'Ama Mensah', 'phone' => '0241234567', 'email' => 'ama@example.com', 'region' => 'Greater Accra',
        'area' => 'harbour', 'address' => '12 Oxford Street', 'landmark' => 'Near the mall', 'ghanaPostGps' => 'GA-123-4567',
        'policyAccepted' => true, 'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 1]],
    ])->assertCreated();
    Http::assertSent(fn ($request): bool => $request['reference'] !== '');
});

it('rejects invalid merged names and aliases during partial updates', function () {
    StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    deliveryAreaAdminLogin('delivery-admin@example.com');
    $area = DeliveryArea::query()->create(['name' => 'Harbor', 'aliases_json' => ['Old Name'], 'is_active' => true]);
    DeliveryArea::query()->create(['name' => 'Tema', 'aliases_json' => ['Community 1'], 'is_active' => false]);

    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['name' => 'Tema'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['aliases' => ['community 1']])->assertUnprocessable()->assertJsonValidationErrors(['aliases']);
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['name' => 'Old Name'])->assertUnprocessable()->assertJsonValidationErrors(['aliases']);
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['aliases' => ['New', 'new']])->assertUnprocessable()->assertJsonValidationErrors(['aliases.1']);
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    expect($area->fresh()->name)->toBe('Harbor')->and($area->fresh()->aliases_json)->toBe(['Old Name']);
});

it('enforces Admin access and returns not found for an unknown delivery area', function () {
    $this->patchJson('/api/v1/admin/delivery-areas/999', ['name' => 'Missing'])->assertUnauthorized();
    $area = DeliveryArea::query()->create(['name' => 'Harbor', 'aliases_json' => [], 'is_active' => true]);
    StaffUser::create(['name' => 'Sales', 'email' => 'delivery-sales@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::SALES_ORDERS]);
    deliveryAreaAdminLogin();
    $this->patchJson('/api/v1/admin/delivery-areas/'.$area->id, ['name' => 'No Access'])->assertForbidden();

    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    deliveryAreaAdminLogin('delivery-admin@example.com');
    $this->patchJson('/api/v1/admin/delivery-areas/999', ['name' => 'Missing'])->assertNotFound();
});

it('rechecks the actor inside a delivery-area update transaction', function () {
    $actor = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    $staleActor = clone $actor;
    $area = DeliveryArea::query()->create(['name' => 'Harbor', 'aliases_json' => ['Old Name'], 'is_active' => true, 'display_order' => 1]);
    $actor->forceFill(['is_active' => false])->save();

    expect(fn () => app(ManageDeliveryAreas::class)->update($staleActor, $area, ['name' => 'Blocked']))
        ->toThrow(AuthorizationException::class);
    expect($area->fresh()->name)->toBe('Harbor')->and(AuditLog::query()->count())->toBe(0);
});

it('merges changes into the freshly loaded delivery area and rolls back on audit failure', function () {
    $actor = StaffUser::create(['name' => 'Admin', 'email' => 'delivery-admin@example.com', 'password' => 'Long-Staff-Password9!',
        'role' => StaffRole::ADMIN]);
    $area = DeliveryArea::query()->create(['name' => 'Harbor', 'aliases_json' => ['Old Name'], 'is_active' => true, 'display_order' => 1]);
    $staleArea = clone $area;
    $area->update(['display_order' => 2]);
    Queue::fake();

    $updated = app(ManageDeliveryAreas::class)->update($actor, $staleArea, ['name' => 'New Harbor']);
    expect($updated->display_order)->toBe(2)->and($updated->aliases_json)->toBe(['Old Name']);

    DB::unprepared("CREATE TRIGGER fail_delivery_area_update_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    expect(fn () => app(ManageDeliveryAreas::class)->update($actor, $updated, ['name' => 'Never committed']))
        ->toThrow(QueryException::class);

    expect($updated->fresh()->name)->toBe('New Harbor');
    Queue::assertPushed(RevalidateStorefront::class, 1);
    Queue::assertPushed(RevalidateStorefront::class, fn (RevalidateStorefront $job): bool => $job->tags === ['delivery-areas']);
});
