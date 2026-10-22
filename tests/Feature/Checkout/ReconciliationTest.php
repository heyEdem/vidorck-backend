<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    Http::preventStrayRequests();
});

function reconciliationLogin(string $email): void
{
    test()->postJson('/api/v1/admin/auth/login', ['email' => $email, 'password' => 'Long-Staff-Password9!'])->assertOk();
}

/** @param array<string, mixed> $overrides */
function reconciliationAttempt(array $overrides = []): CheckoutAttempt
{
    return CheckoutAttempt::query()->create(array_merge([
        'paystack_reference' => 'recon-'.Str::random(12),
        'customer_snapshot_json' => ['name' => 'PRIVATE_CUSTOMER_SENTINEL', 'email' => 'private@example.test'],
        'delivery_snapshot_json' => ['address' => 'PRIVATE_ADDRESS_SENTINEL', 'area' => 'Osu'],
        'items_snapshot_json' => [['name' => 'PRIVATE_ITEM_SENTINEL']],
        'verification_snapshot_json' => ['authorization_code' => 'PRIVATE_PROVIDER_SECRET_SENTINEL'],
        'amount_minor' => 4200,
        'currency' => 'GHS',
        'status' => 'PENDING',
        'expires_at' => now()->addHour(),
    ], $overrides));
}

function reconciliationOrder(CheckoutAttempt $attempt): Order
{
    $customer = Customer::query()->create([
        'full_name' => 'Ama Mensah', 'phone_e164' => '+233241234567', 'phone_display' => '0241234567',
    ]);

    return Order::query()->create([
        'order_number' => 'VHI-2026-000001', 'tracking_token' => Str::random(32),
        'customer_id' => $customer->id, 'checkout_attempt_id' => $attempt->id,
        'subtotal_minor' => 4200, 'total_paid_minor' => 4200, 'payment_method' => 'PAYSTACK',
        'payment_reference' => $attempt->paystack_reference,
        'customer_snapshot_json' => ['name' => 'Ama Mensah'], 'delivery_snapshot_json' => ['area' => 'Osu'], 'paid_at' => now(),
    ]);
}

it('lists mismatches and verified orderless attempts once and excludes ordinary or ordered attempts', function () {
    $admin = StaffUser::query()->create(['name' => 'Admin', 'email' => 'recon-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    reconciliationLogin('recon-admin@example.com');

    $mismatch = reconciliationAttempt(['status' => 'MISMATCH', 'verified_at' => now()]);
    $verifiedWithoutOrder = reconciliationAttempt(['status' => 'SUCCEEDED', 'verified_at' => now()->subMinute()]);
    reconciliationAttempt(['status' => 'PENDING', 'last_verified_at' => now()]);
    reconciliationAttempt(['status' => 'FAILED']);
    $paid = reconciliationAttempt(['status' => 'SUCCEEDED', 'verified_at' => now()]);
    reconciliationOrder($paid);

    Queue::fake();
    $response = $this->getJson('/api/v1/admin/payments/reconciliation?perPage=100')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.page', 1)->assertJsonPath('meta.perPage', 100);

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mismatch->id, $verifiedWithoutOrder->id])->sort()->values()->all());
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('returns only the safe reconciliation fields without customer or provider snapshots', function () {
    StaffUser::query()->create(['name' => 'Admin', 'email' => 'recon-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    reconciliationLogin('recon-admin@example.com');
    $attempt = reconciliationAttempt(['status' => 'MISMATCH', 'last_verified_at' => now()->subMinute()]);

    $response = $this->getJson('/api/v1/admin/payments/reconciliation')->assertOk();
    $item = $response->json('data.0');
    expect($item)->toHaveKeys(['id', 'attemptId', 'reference', 'status', 'amountMinor', 'currency',
        'expiresAt', 'createdAt', 'verifiedAt', 'lastVerifiedAt'])
        ->and(array_keys($item))->toBe(['id', 'attemptId', 'reference', 'status', 'amountMinor', 'currency',
            'expiresAt', 'createdAt', 'verifiedAt', 'lastVerifiedAt'])
        ->and($item['attemptId'])->toBe($attempt->public_id)
        ->and($item['reference'])->toBe($attempt->paystack_reference)
        ->and($item['status'])->toBe('MISMATCH');
    expect($response->getContent())->not->toContain('PRIVATE_CUSTOMER_SENTINEL')
        ->not->toContain('PRIVATE_ADDRESS_SENTINEL')->not->toContain('PRIVATE_ITEM_SENTINEL')
        ->not->toContain('PRIVATE_PROVIDER_SECRET_SENTINEL')->not->toContain('trackingToken')
        ->not->toContain('customer_snapshot_json')->not->toContain('verification_snapshot_json');
});

it('paginates in stable created-time and id descending order and validates pagination inputs', function () {
    StaffUser::query()->create(['name' => 'Admin', 'email' => 'recon-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    reconciliationLogin('recon-admin@example.com');
    $sameTime = now()->subMinutes(2);
    reconciliationAttempt(['status' => 'MISMATCH', 'created_at' => $sameTime, 'updated_at' => $sameTime]);
    reconciliationAttempt(['status' => 'MISMATCH', 'created_at' => $sameTime, 'updated_at' => $sameTime]);
    reconciliationAttempt(['status' => 'MISMATCH', 'created_at' => now()->subMinute()]);

    $pageOne = $this->getJson('/api/v1/admin/payments/reconciliation?page=1&perPage=2')->assertOk()
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.page', 1);
    $pageTwo = $this->getJson('/api/v1/admin/payments/reconciliation?page=2&perPage=2')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.page', 2);
    $all = collect($pageOne->json('data'))->pluck('id')->merge(collect($pageTwo->json('data'))->pluck('id'))->all();
    expect($all)->toBe(array_values(array_unique($all)))->and($pageOne->json('data.1.id'))->toBeGreaterThan($pageTwo->json('data.0.id'));

    $this->getJson('/api/v1/admin/payments/reconciliation?page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
    $this->getJson('/api/v1/admin/payments/reconciliation?perPage=101')->assertUnprocessable()->assertJsonValidationErrors(['perPage']);
});

it('allows only active Admin sessions and rejects unauthenticated or Sales users', function () {
    $this->getJson('/api/v1/admin/payments/reconciliation')->assertUnauthorized();
    StaffUser::query()->create(['name' => 'Sales', 'email' => 'recon-sales@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::SALES_ORDERS]);
    reconciliationLogin('recon-sales@example.com');
    $this->getJson('/api/v1/admin/payments/reconciliation')->assertForbidden();

    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $admin = StaffUser::query()->create(['name' => 'Admin', 'email' => 'recon-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    reconciliationLogin('recon-admin@example.com');
    $this->getJson('/api/v1/admin/payments/reconciliation')->assertOk();

    $admin->update(['is_active' => false]);
    Auth::forgetGuards();
    $this->getJson('/api/v1/admin/payments/reconciliation')->assertUnauthorized();
});

it('does not mutate reconciliation rows while listing them', function () {
    StaffUser::query()->create(['name' => 'Admin', 'email' => 'recon-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    reconciliationLogin('recon-admin@example.com');
    $attempt = reconciliationAttempt(['status' => 'MISMATCH', 'last_verified_at' => now()->subMinute()]);
    $before = $attempt->only(['status', 'last_verified_at', 'updated_at']);

    $this->getJson('/api/v1/admin/payments/reconciliation')->assertOk();

    expect($attempt->fresh()->only(['status', 'last_verified_at', 'updated_at']))->toEqual($before);
    Http::assertNothingSent();
});

it('rejects a revoked Admin session', function () {
    $admin = StaffUser::query()->create(['name' => 'Admin', 'email' => 'recon-admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    reconciliationLogin('recon-admin@example.com');
    $admin->forceFill(['session_version' => $admin->session_version + 1])->save();
    Auth::forgetGuards();

    $this->getJson('/api/v1/admin/payments/reconciliation')->assertUnauthorized();
});
