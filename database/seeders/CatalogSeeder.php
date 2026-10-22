<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Conditioner', 'Gel', 'Hair care', 'Hair oil', 'Pomade', 'Relaxer',
            'Setting lotion', 'Shampoo', 'Styling', 'Texturizer', 'Treatment',
        ];

        foreach ($categories as $order => $name) {
            $slug = str($name)->slug()->toString();
            if (! Category::query()->where('slug', $slug)->orWhere('name', $name)->exists()) {
                Category::query()->create([
                    'slug' => $slug, 'name' => $name, 'display_order' => $order, 'is_active' => true,
                ]);
            }
        }

        foreach (['Natural Hair', 'Permed Hair'] as $order => $name) {
            HairType::query()->firstOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name, 'display_order' => $order],
            );
        }
    }
}
