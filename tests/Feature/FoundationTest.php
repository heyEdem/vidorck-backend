<?php

use App\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\EarlyResponseMiddleware;

it('serves the health endpoint with global headers', function () {
    $this->get('/up')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('errors use the standard envelope even without an Accept header', function () {
    $response = $this->get('/api/v1/unknown')->assertNotFound()->assertExactJson([
        'message' => 'Not found.', 'code' => 'NOT_FOUND', 'errors' => [],
    ])->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(json_decode($response->getContent())->errors)->toBeInstanceOf(stdClass::class);
});

it('normalizes API exceptions and preserves their HTTP headers', function (int $status, string $code) {
    Route::get('/api/v1/test-error', function () use ($status) {
        throw match ($status) {
            401 => new AuthenticationException,
            403 => new AuthorizationException,
            422 => ValidationException::withMessages(['fullName' => ['A name is required.']]),
            default => new HttpException($status, 'Test failure.', null, ['Retry-After' => '30']),
        };
    });

    $response = $this->get('/api/v1/test-error')->assertStatus($status)
        ->assertJsonPath('code', $code)->assertJsonStructure(['message', 'code', 'errors'])
        ->assertHeader('X-Request-Id')->assertHeader('Referrer-Policy', 'no-referrer');

    if ($status === 422) {
        $response->assertJsonPath('errors.fullName.0', 'A name is required.');
    } else {
        expect($response->json('errors'))->toBe([]);
    }

    if ($status === 429) {
        $response->assertHeader('Retry-After', '30');
    }
})->with([[401, 'UNAUTHENTICATED'], [403, 'FORBIDDEN'], [404, 'NOT_FOUND'],
    [409, 'CONFLICT'], [422, 'VALIDATION_ERROR'], [429, 'RATE_LIMITED'], [500, 'SERVER_ERROR']]);

it('does not expose internal exception details', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/test-crash', fn () => throw new RuntimeException('secret database password'));
    $response = $this->get('/api/v1/test-crash')->assertStatus(500)->assertExactJson([
        'message' => 'An unexpected error occurred.', 'code' => 'SERVER_ERROR', 'errors' => [],
    ]);
    expect(json_decode($response->getContent())->errors)->toBeInstanceOf(stdClass::class);
});

it('echoes a request ID and adds it to log context', function () {
    Route::get('/api/v1/test-context', fn () => response()->json(Log::sharedContext()));
    $this->get('/api/v1/test-context', ['X-Request-Id' => 'abc'])
        ->assertOk()->assertHeader('X-Request-Id', 'abc')->assertJsonPath('request_id', 'abc');
});

it('generates request IDs without leaking them across requests', function () {
    $first = $this->get('/up')->assertHeader('X-Request-Id')->headers->get('X-Request-Id');
    $second = $this->get('/api/v1/unknown')->assertHeader('X-Request-Id')->headers->get('X-Request-Id');
    expect($first)->toMatch('/^[0-9a-f-]{36}$/')->not->toBe($second);
});

it('replaces unsafe request IDs', function () {
    $this->get('/up', ['X-Request-Id' => str_repeat('x', 300)])
        ->assertHeader('X-Request-Id');
    expect($this->get('/up', ['X-Request-Id' => 'bad id'])->headers->get('X-Request-Id'))
        ->not->toBe('bad id');
});

it('rejects an unknown CORS origin', function () {
    $this->options('/api/v1/products', [], [
        'Origin' => 'https://unknown.example',
        'Access-Control-Request-Method' => 'GET',
    ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeader('X-Request-Id')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('allows configured origins with credentials and request ID visibility', function (string $path) {
    $this->options($path, [], [
        'Origin' => config('cors.allowed_origins')[0],
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'X-Request-Id, X-XSRF-TOKEN',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', config('cors.allowed_origins')[0])
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertHeader('X-Request-Id');
})->with(['/api/v1/products', '/sanctum/csrf-cookie']);

it('adds HSTS only in production', function () {
    $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');
    $this->app->detectEnvironment(fn () => 'production');
    $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('exposes the request ID to allowed origins on errors', function () {
    $this->get('/api/v1/unknown', ['Origin' => config('cors.allowed_origins')[0]])
        ->assertNotFound()->assertHeader('Access-Control-Expose-Headers', 'X-Request-Id')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('renders authentication middleware failures as JSON without redirecting', function () {
    Route::get('/api/v1/admin/test-protected', fn () => ['ok' => true])->middleware('auth:sanctum');
    $this->get('/api/v1/admin/test-protected')->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED')->assertHeaderMissing('Location');
});

it('preserves real rate limit retry headers', function () {
    Route::get('/api/v1/test-throttle', fn () => ['ok' => true])->middleware('throttle:1,1');
    $this->get('/api/v1/test-throttle')->assertOk();
    $this->get('/api/v1/test-throttle')->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After')
        ->assertHeader('X-Request-Id');
});

it('initializes SPA cookies using the database session driver', function () {
    config(['session.driver' => 'database']);
    $this->artisan('migrate')->assertSuccessful();
    $this->get('/sanctum/csrf-cookie')->assertNoContent()
        ->assertCookie('XSRF-TOKEN')->assertCookie(config('session.cookie'))
        ->assertHeader('X-Request-Id');
    expect(DB::table('sessions')->count())->toBe(1);
});

it('preserves explicit responses carried by exceptions', function () {
    Route::post('/api/v1/test-ack', fn () => throw new HttpResponseException(response()->noContent()));
    $this->post('/api/v1/test-ack')->assertNoContent()
        ->assertHeader('X-Request-Id')->assertHeader('X-Content-Type-Options', 'nosniff');
    Route::post('/api/v1/test-ack-middleware', fn () => ['ok' => true])->middleware(EarlyResponseMiddleware::class);
    $this->post('/api/v1/test-ack-middleware')->assertNoContent()->assertHeader('X-Request-Id');
});

it('supports specific domain error codes and safe messages', function () {
    Route::get('/api/v1/test-domain', fn () => throw new ApiException(
        403, 'PASSWORD_CHANGE_REQUIRED', 'Please change your password.',
    ));
    $this->get('/api/v1/test-domain')->assertForbidden()->assertExactJson([
        'message' => 'Please change your password.', 'code' => 'PASSWORD_CHANGE_REQUIRED', 'errors' => [],
    ]);
});

it('preserves method allow headers in the standard envelope', function () {
    Route::get('/api/v1/test-get-only', fn () => ['ok' => true]);
    $this->post('/api/v1/test-get-only')->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')->assertHeader('Allow', 'GET, HEAD');
});

it('renders missing models as generic not found errors', function () {
    Route::get('/api/v1/test-missing-model', fn () => throw (new ModelNotFoundException)->setModel(User::class, [123]));
    $this->get('/api/v1/test-missing-model')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

it('uses the error envelope for stateful CSRF failures', function () {
    // Laravel skips CSRF during unit tests, so emulate a normal HTTP environment.
    $this->app->detectEnvironment(fn () => 'local');
    Route::post('/api/v1/admin/test-csrf', fn () => ['ok' => true])->middleware('api');
    $domain = config('sanctum.stateful')[0];
    $this->post('/api/v1/admin/test-csrf', [], ['Origin' => 'http://'.$domain])
        ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH')
        ->assertHeader('X-Request-Id');
});

it('preserves domain field errors and HTTP headers', function () {
    Route::post('/api/v1/test-domain-fields', fn () => throw new ApiException(
        422, 'ITEM_UNAVAILABLE', 'An item is unavailable.',
        ['items.0.productCode' => ['Choose an available variant.']], ['Retry-After' => '30'],
    ));
    $this->post('/api/v1/test-domain-fields')->assertUnprocessable()
        ->assertJsonPath('code', 'ITEM_UNAVAILABLE')
        ->assertJsonPath('errors', ['items.0.productCode' => ['Choose an available variant.']])
        ->assertHeader('Retry-After', '30');
});
