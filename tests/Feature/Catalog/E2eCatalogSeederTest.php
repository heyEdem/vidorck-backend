<?php

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\E2eCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to seed the E2E catalogue in production and writes nothing', function () {
    $this->seed(CatalogSeeder::class);

    app()['env'] = 'production';

    try {
        expect(fn () => (new E2eCatalogSeeder)->run())->toThrow(RuntimeException::class, 'only run in local or testing');
    } finally {
        app()['env'] = 'testing';
    }

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(Bundle::query()->count())->toBe(0);
});

it('seeds the E2E catalogue in the testing environment', function () {
    $this->seed(CatalogSeeder::class);

    (new E2eCatalogSeeder)->run();

    expect(Product::query()->where('slug', 'hair-mayonnaise')->exists())->toBeTrue()
        ->and(Bundle::query()->where('slug', 'e2e-hair-set')->exists())->toBeTrue();
});
