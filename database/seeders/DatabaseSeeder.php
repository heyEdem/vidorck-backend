<?php

namespace Database\Seeders;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed structural catalogue defaults in every environment. Development
     * staff accounts run only in local/testing (task 1.2).
     */
    public function run(): void
    {
        $this->call([CatalogSeeder::class, DeliveryAreaSeeder::class]);

        if (! app()->environment(['local', 'testing'])) {
            if (isset($this->command)) {
                $this->command->warn('Skipping dev staff accounts outside local/testing; use staff:create-admin.');
            }

            return;
        }

        $accounts = [
            ['Vidorck Admin', 'admin@vidorckhair.com', StaffRole::ADMIN, env('SEED_ADMIN_PASSWORD', 'Vidorck-Admin-2026!')],
            ['Vidorck Sales', 'sales@vidorckhair.com', StaffRole::SALES_ORDERS, env('SEED_STAFF_PASSWORD', 'Vidorck-Sales-2026!')],
        ];

        foreach ($accounts as [$name, $email, $role, $password]) {
            StaffUser::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $password, 'role' => $role, 'is_active' => true, 'must_change_password' => false],
            );
        }
    }
}
