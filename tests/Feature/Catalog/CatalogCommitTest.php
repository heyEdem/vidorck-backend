<?php

use App\Modules\Catalog\Actions\ManageCatalog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config([
        'queue.default' => 'sync',
        'services.storefront.revalidate_url' => 'https://storefront.example/api/revalidate',
        'services.storefront.revalidate_secret' => 'commit-test-secret',
    ]);
    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
});

it('sends one revalidation request only after the outer transaction commits', function () {
    Http::fake(function (Request $request) {
        expect(DB::transactionLevel())->toBe(0);

        return Http::response(['revalidated' => true], 200);
    });

    DB::beginTransaction();
    $category = app(ManageCatalog::class)->createCategory($this->admin, ['name' => 'Hair Care', 'slug' => 'hair-care']);
    expect(Category::whereKey($category->getKey())->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'catalog.category.created')->count())->toBe(1);
    Http::assertNothingSent();

    DB::commit();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://storefront.example/api/revalidate'
        && $request->header('x-revalidate-secret') === ['commit-test-secret']
        && $request['tags'] === ['bundles', 'categories', 'products']);
});

it('does not send revalidation or retain category audit data after outer rollback', function () {
    Http::fake();

    DB::beginTransaction();
    $category = app(ManageCatalog::class)->createCategory($this->admin, ['name' => 'Hair Care', 'slug' => 'hair-care']);
    expect(Category::whereKey($category->getKey())->exists())->toBeTrue();
    Http::assertNothingSent();
    DB::rollBack();

    Http::assertNothingSent();
    expect(Category::where('slug', 'hair-care')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'catalog.category.created')->count())->toBe(0);
});
