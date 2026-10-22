<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Local/E2E only: a small published catalogue for the storefront and admin E2E tests. Never called from DatabaseSeeder. */
final class E2eCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2eCatalogSeeder rewrites live catalogue rows by code and may only run in local or testing.');
        }

        $category = Category::query()->where('slug', 'treatment')->firstOrFail();
        $permed = HairType::query()->where('slug', 'permed-hair')->firstOrFail();

        // Mirrors the storefront's former static "hair-mayonnaise" (customer price = source + 200).
        $mayonnaise = Product::query()->updateOrCreate(['slug' => 'hair-mayonnaise'], [
            'name' => 'Hair Mayonnaise', 'category_id' => $category->id, 'display_order' => 0,
            'is_published' => true, 'is_new_look' => true, 'image_position' => 'center 64%',
        ]);
        $mayonnaise->hairTypes()->syncWithoutDetaching([$permed->id]);
        $variants = [];
        foreach ([['B/s', 'MYNS-BS-037', 4000, 0], ['S/s', 'MYNS-SS-038', 2000, 1]] as [$label, $code, $source, $order]) {
            $variants[$label] = ProductVariant::query()->updateOrCreate(['product_code' => $code], [
                'product_id' => $mayonnaise->id, 'size_label' => $label, 'source_price_minor' => $source,
                'price_minor' => $source + 200, 'is_active' => true, 'display_order' => $order,
            ]);
        }

        // Enough products for a second shop page (15 per page).
        for ($index = 1; $index <= 16; $index++) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $filler = Product::query()->updateOrCreate(['slug' => "e2e-filler-{$number}"], [
                'name' => "E2E Filler {$number}", 'category_id' => $category->id, 'display_order' => 100 + $index, 'is_published' => true,
            ]);
            ProductVariant::query()->updateOrCreate(['product_code' => "E2E-FILL-{$number}"], [
                'product_id' => $filler->id, 'size_label' => 'Standard', 'source_price_minor' => 1000,
                'price_minor' => 1200, 'is_active' => true, 'display_order' => 0,
            ]);
        }

        $bundle = Bundle::query()->updateOrCreate(['slug' => 'e2e-hair-set'], [
            'name' => 'E2E Hair Set', 'product_code' => 'BNDL-E2E-001', 'eyebrow' => 'E2E routine',
            'price_minor' => 6100, 'is_published' => true, 'display_order' => 0,
        ]);
        $bundle->items()->delete();
        foreach ([$variants['S/s'], $variants['B/s']] as $order => $variant) {
            $bundle->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1, 'display_order' => $order]);
        }
    }
}
