<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Exercise the real CSRF middleware, not Laravel's unit-test bypass.
    $this->app->detectEnvironment(fn () => 'local');
    config(['session.driver' => 'database']);
    Queue::fake();
    // The storefront and the admin share this origin in production.
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
});

it('keeps public storefront POSTs from the shared origin free of CSRF', function () {
    $this->postJson('/api/v1/complaints', [
        'name' => 'Ama Mensah', 'topic' => 'OTHER', 'message' => 'Hello from the storefront.', 'contactMethod' => 'EMAIL', 'email' => 'ama@example.com',
    ])->assertCreated()->assertJsonPath('data.status', 'NEW');
    $this->postJson('/api/v1/orders/track', [])->assertStatus(422);
});

it('does not start a session for public API calls from the shared origin', function () {
    $response = $this->getJson('/api/v1/delivery-areas')->assertOk();
    $cookies = collect($response->headers->getCookies())->map->getName()->all();
    expect($cookies)->not->toContain(config('session.cookie'))
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('still requires CSRF on admin routes from the shared origin', function () {
    StaffUser::create(['name' => 'Ada', 'email' => 'ada@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ada@example.com', 'password' => 'Long-Staff-Password9!'])
        ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
});

it('still issues a session and XSRF cookie from the CSRF endpoint', function () {
    $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $cookies = collect($response->headers->getCookies())->map->getName()->all();
    expect($cookies)->toContain('XSRF-TOKEN')->toContain(config('session.cookie'));
});
