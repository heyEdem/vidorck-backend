<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('logs route, status and duration for each request', function () {
    Log::spy();

    $this->get('/api/v1/unknown')->assertNotFound();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'request'
        && $context['method'] === 'GET' && $context['status'] === 404
        && is_string($context['route']) && is_int($context['duration_ms']));
});

it('seeds dev Admin and Sales accounts idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(StaffUser::query()->count())->toBe(2)
        ->and(StaffUser::query()->where('email', 'admin@vidorckhair.com')->value('role'))->toBe(StaffRole::ADMIN)
        ->and(StaffUser::query()->where('email', 'sales@vidorckhair.com')->value('role'))->toBe(StaffRole::SALES_ORDERS);
});

it('does not seed staff accounts outside local and testing', function () {
    app()['env'] = 'production';
    (new DatabaseSeeder)->run();
    app()['env'] = 'testing';

    expect(StaffUser::query()->count())->toBe(0);
});
