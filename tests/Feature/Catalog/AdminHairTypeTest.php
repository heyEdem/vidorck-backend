<?php

use App\Modules\Catalog\Models\HairType;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    HairType::query()->create(['name' => 'Permed Hair', 'slug' => 'permed-hair', 'display_order' => 2]);
    HairType::query()->create(['name' => 'Natural Hair', 'slug' => 'natural-hair', 'display_order' => 1]);
});

function adminHairTypeLogin(StaffRole $role): void
{
    $email = strtolower($role->value).'-hair@example.com';
    StaffUser::create(['name' => 'Staff', 'email' => $email, 'password' => 'Long-Staff-Password9!', 'role' => $role]);
    test()->postJson('/api/v1/admin/auth/login', ['email' => $email, 'password' => 'Long-Staff-Password9!'])->assertOk();
}

it('lists hair types with ids in display order for both staff roles', function (StaffRole $role) {
    adminHairTypeLogin($role);

    $response = $this->getJson('/api/v1/admin/hair-types')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->toMatchArray(['name' => 'Natural Hair', 'slug' => 'natural-hair', 'displayOrder' => 1])
        ->and($response->json('data.0.id'))->toBeInt()
        ->and($response->json('data.1.slug'))->toBe('permed-hair');
})->with([StaffRole::ADMIN, StaffRole::SALES_ORDERS]);

it('requires a staff session', function () {
    $this->getJson('/api/v1/admin/hair-types')->assertUnauthorized();
});
