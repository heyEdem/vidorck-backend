<?php

namespace Database\Seeders;

use App\Modules\Checkout\Models\DeliveryArea;
use Illuminate\Database\Seeder;

class DeliveryAreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            'Accra Central', 'Osu', 'Labone', 'Cantonments', 'Airport Residential', 'Roman Ridge', 'Dzorwulu', 'Abelemkpe',
            'Achimota', 'Tesano', 'Abeka', 'Lapaz', 'Kaneshie', 'Dansoman', 'Darkuman', 'Sowutuom', 'Kwabenya', 'Haatso',
            'North Legon', 'Madina', 'Adenta', 'East Legon', 'East Legon Hills', 'Adjiringanor', 'Ashaley Botwe', 'Lakeside',
            'Oyarifa', 'Amrahia', 'Oyibi', 'Nima', 'Kokomlemle', 'La', 'Labadi', 'Teshie', 'Nungua', 'Spintex', 'Baatsona',
            'Sakumono', 'Lashibi', 'Weija', 'Gbawe', 'Mallam', 'McCarthy Hill', 'Bortianor', 'Ngleshie Amanfrom', 'Kasoa',
            'Ofaakor', 'Opeikuma',
        ];

        foreach ($areas as $order => $name) {
            DeliveryArea::query()->firstOrCreate(
                ['name' => $name],
                ['aliases_json' => [], 'is_active' => true, 'display_order' => $order],
            );
        }
    }
}
