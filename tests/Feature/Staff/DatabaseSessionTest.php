<?php

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** @param array<string, string> $browser
 * @param  array<string, mixed>  $data
 */
function staffBrowserRequest(array &$browser, string $method, string $uri, array $data = [], bool $csrf = true): TestResponse
{
    Auth::forgetGuards();
    Auth::setDefaultDriver('web');
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $server = ['HTTP_ORIGIN' => 'http://'.config('sanctum.stateful')[0], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($csrf && isset($browser['XSRF-TOKEN'])) {
        $server['HTTP_X_XSRF_TOKEN'] = $browser['XSRF-TOKEN'];
    }
    $response = test()->call($method, $uri, [], $browser, [], $server, json_encode($data, JSON_THROW_ON_ERROR));
    foreach ($response->headers->getCookies() as $cookie) {
        $browser[$cookie->getName()] = $cookie->getValue();
    }

    return $response;
}

/** @param array<string, string> $browser */
function staffBrowserLogin(array &$browser, string $email): void
{
    staffBrowserRequest($browser, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
    staffBrowserRequest($browser, 'POST', '/api/v1/admin/auth/login', [
        'email' => $email, 'password' => 'Long-Staff-Password9!',
    ])->assertOk();
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    // Exercise actual encrypted cookies and CSRF middleware, not Laravel's test bypass.
    $this->app->detectEnvironment(fn () => 'local');
    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->sales = StaffUser::create(['name' => 'Sales', 'email' => 'sales@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::SALES_ORDERS]);
});

it('uses encrypted XSRF cookies and denies an authenticated mutation without the header', function () {
    $browser = [];
    staffBrowserLogin($browser, 'admin@example.com');
    staffBrowserRequest($browser, 'GET', '/api/v1/admin/auth/me')->assertOk();
    staffBrowserRequest($browser, 'POST', '/api/v1/admin/auth/logout', csrf: false)->assertStatus(419);
    staffBrowserRequest($browser, 'POST', '/api/v1/admin/auth/logout')->assertNoContent();
    staffBrowserRequest($browser, 'GET', '/api/v1/admin/auth/me')->assertUnauthorized();
});

it('immediately revokes independent browser sessions on disable role change or reset', function (string $mutation) {
    $admin = $sales = $otherSales = [];
    staffBrowserLogin($admin, 'admin@example.com');
    staffBrowserLogin($sales, 'sales@example.com');
    staffBrowserLogin($otherSales, 'sales@example.com');
    expect(DB::table('sessions')->where('user_id', $this->sales->id)->count())->toBe(2);
    staffBrowserRequest($sales, 'GET', '/api/v1/admin/auth/me')->assertOk();
    if ($mutation === 'reset') {
        staffBrowserRequest($admin, 'POST', '/api/v1/admin/staff/'.$this->sales->id.'/reset-password')->assertOk();
    } else {
        staffBrowserRequest($admin, 'PATCH', '/api/v1/admin/staff/'.$this->sales->id,
            $mutation === 'disable' ? ['isActive' => false] : ['role' => 'ADMIN'])->assertOk();
    }
    expect(DB::table('sessions')->where('user_id', $this->sales->id)->count())->toBe(0);
    staffBrowserRequest($sales, 'GET', '/api/v1/admin/auth/me')->assertUnauthorized();
    staffBrowserRequest($otherSales, 'GET', '/api/v1/admin/auth/me')->assertUnauthorized();
    staffBrowserRequest($admin, 'GET', '/api/v1/admin/auth/me')->assertOk();
})->with(['disable', 'role', 'reset']);

it('expires idle database sessions after sixty minutes', function () {
    $browser = [];
    staffBrowserLogin($browser, 'admin@example.com');
    $this->travel(61)->minutes();
    staffBrowserRequest($browser, 'GET', '/api/v1/admin/auth/me')->assertUnauthorized();
});

it('expires an actively used database session at twelve hours', function () {
    $browser = [];
    staffBrowserLogin($browser, 'admin@example.com');
    for ($hour = 0; $hour < 12; $hour++) {
        $this->travel(59)->minutes();
        staffBrowserRequest($browser, 'GET', '/api/v1/admin/auth/me')->assertOk();
    }
    $this->travel(12)->minutes();
    staffBrowserRequest($browser, 'GET', '/api/v1/admin/auth/me')->assertUnauthorized();
});

it('changes a password while retaining one browser and revoking the other', function () {
    $browser = $other = [];
    staffBrowserLogin($browser, 'admin@example.com');
    staffBrowserLogin($other, 'admin@example.com');
    $oldCookie = $browser[config('session.cookie')];
    staffBrowserRequest($browser, 'POST', '/api/v1/admin/auth/password', [
        'currentPassword' => 'Long-Staff-Password9!', 'newPassword' => 'New-Staff-Password7!', 'newPasswordConfirmation' => 'New-Staff-Password7!',
    ])->assertOk();
    expect($browser[config('session.cookie')])->not->toBe($oldCookie);
    staffBrowserRequest($browser, 'GET', '/api/v1/admin/auth/me')->assertOk();
    staffBrowserRequest($other, 'GET', '/api/v1/admin/auth/me')->assertUnauthorized();
});
