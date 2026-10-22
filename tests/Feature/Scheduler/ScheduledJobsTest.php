<?php

use App\Jobs\PruneExpiredSessions;
use App\Modules\Catalog\Jobs\PruneRetiredMedia;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Media;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Jobs\ExpireStaleCheckoutAttempts;
use App\Modules\Checkout\Jobs\PruneFailedCheckoutAttempts;
use App\Modules\Checkout\Jobs\ReverifyPendingCheckoutAttempts;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.paystack.secret_key' => 'sk_test_sched', 'services.paystack.base_url' => 'https://api.paystack.co']);
    Http::preventStrayRequests();
});

function schedAttempt(array $overrides = [], mixed $createdAt = null): CheckoutAttempt
{
    $attempt = CheckoutAttempt::query()->create(array_replace([
        'public_id' => (string) Str::ulid(), 'paystack_reference' => 'REF-SCHED-'.Str::random(12),
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'ama@example.test'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Osu', 'address' => 'Oxford Street'],
        'items_snapshot_json' => [['lineId' => 'line-1', 'lineType' => 'PRODUCT', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => CheckoutAttemptStatus::PENDING,
        'expires_at' => now()->addHour(),
    ], $overrides));
    DB::table('checkout_attempts')->where('id', $attempt->id)->update(['created_at' => $createdAt ?? now()->subHour()]);

    return $attempt->fresh();
}

function schedOrderFor(CheckoutAttempt $attempt): Order
{
    $customer = Customer::query()->create(['public_id' => (string) Str::ulid(), 'full_name' => 'Ama', 'phone_e164' => '+2332'.random_int(10000000, 99999999), 'phone_display' => '0241234567']);

    return Order::query()->forceCreate([
        'order_number' => 'VHI-'.Str::random(8), 'tracking_token' => Str::random(32), 'source' => 'ONLINE', 'customer_id' => $customer->id,
        'checkout_attempt_id' => $attempt->id, 'status' => 'PAID', 'subtotal_minor' => 1000, 'total_paid_minor' => 1000, 'currency' => 'GHS',
        'payment_method' => 'card', 'customer_snapshot_json' => '{}', 'delivery_snapshot_json' => '{}', 'paid_at' => now(),
    ]);
}

it('stale_attempts_are_abandoned', function (): void {
    $stale = schedAttempt(['expires_at' => now()->subMinute()]);

    (new ExpireStaleCheckoutAttempts)();

    expect($stale->fresh()->status)->toBe(CheckoutAttemptStatus::ABANDONED);
});

it('leaves unexpired, verified, ordered and non-pending attempts untouched when expiring', function (): void {
    $fresh = schedAttempt();
    $verified = schedAttempt(['expires_at' => now()->subMinute(), 'verified_at' => now()->subMinutes(5)]);
    $ordered = schedAttempt(['expires_at' => now()->subMinute()]);
    schedOrderFor($ordered);
    $failed = schedAttempt(['expires_at' => now()->subMinute(), 'status' => CheckoutAttemptStatus::FAILED]);

    (new ExpireStaleCheckoutAttempts)();

    expect($fresh->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)
        ->and($verified->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)
        ->and($ordered->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)
        ->and($failed->fresh()->status)->toBe(CheckoutAttemptStatus::FAILED);
});

it('re-verifies a pending attempt only once', function (): void {
    $attempt = schedAttempt();
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'failed']])]);

    (new ReverifyPendingCheckoutAttempts)();
    Http::assertSentCount(1);
    expect($attempt->fresh()->scheduled_verified_at)->not->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::FAILED);

    $again = schedAttempt();
    DB::table('checkout_attempts')->where('id', $again->id)->update(['scheduled_verified_at' => now()]);
    (new ReverifyPendingCheckoutAttempts)();
    Http::assertSentCount(1);
});

it('counts a provider failure as the single scheduled attempt', function (): void {
    $attempt = schedAttempt();
    Http::fake(['api.paystack.co/*' => Http::response([], 500)]);

    (new ReverifyPendingCheckoutAttempts)();
    $sent = count(Http::recorded());
    (new ReverifyPendingCheckoutAttempts)();

    expect($sent)->toBeGreaterThan(0);
    Http::assertSentCount($sent);
    expect($attempt->fresh()->scheduled_verified_at)->not->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING);
});

it('skips recent and ordered attempts when re-verifying', function (): void {
    $recent = schedAttempt(createdAt: now()->subMinutes(5));
    $ordered = schedAttempt();
    schedOrderFor($ordered);

    (new ReverifyPendingCheckoutAttempts)();

    Http::assertNothingSent();
    expect($recent->fresh()->scheduled_verified_at)->toBeNull()->and($ordered->fresh()->scheduled_verified_at)->toBeNull();
});

it('prunes media retired over 30 days ago, files first, and keeps newer and active media', function (): void {
    Storage::fake('media');
    $category = Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $make = function (string $slug, ?DateTimeInterface $retiredAt) use ($category): Media {
        $product = Product::create(['name' => $slug, 'slug' => $slug, 'category_id' => $category->id]);
        Storage::disk('media')->put("m/$slug/original.jpg", 'x');
        Storage::disk('media')->put("m/$slug/320.webp", 'x');

        return $product->media()->create(['public_id' => (string) Str::ulid(), 'original_path' => "m/$slug/original.jpg",
            'variants_json' => ['320' => "m/$slug/320.webp"], 'width' => 10, 'height' => 10, 'mime' => 'image/jpeg', 'bytes' => 1, 'retired_at' => $retiredAt]);
    };
    $old = $make('old', now()->subDays(31));
    $recent = $make('recent', now()->subDays(29));
    $active = $make('active', null);

    (new PruneRetiredMedia)();

    expect(Media::find($old->id))->toBeNull()
        ->and(Media::find($recent->id))->not->toBeNull()
        ->and(Media::find($active->id))->not->toBeNull();
    Storage::disk('media')->assertMissing(['m/old/original.jpg', 'm/old/320.webp']);
    Storage::disk('media')->assertExists(['m/recent/original.jpg', 'm/active/320.webp']);
});

it('keeps the media row when file deletion fails', function (): void {
    $category = Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $product = Product::create(['name' => 'x', 'slug' => 'x', 'category_id' => $category->id]);
    $media = $product->media()->create(['public_id' => (string) Str::ulid(), 'original_path' => 'm/x/original.jpg', 'variants_json' => [],
        'width' => 10, 'height' => 10, 'mime' => 'image/jpeg', 'bytes' => 1, 'retired_at' => now()->subDays(40)]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('boom'));
    Storage::shouldReceive('disk')->with('media')->andReturn($disk);

    (new PruneRetiredMedia)();

    expect(Media::find($media->id))->not->toBeNull();
});

it('prunes only expired sessions and only on the database driver', function (): void {
    config(['session.driver' => 'database', 'session.lifetime' => 60]);
    $row = fn (string $id, int $minutesAgo) => ['id' => $id, 'payload' => '', 'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp()];
    DB::table('sessions')->insert([$row('old', 120), $row('live', 10)]);

    config(['session.driver' => 'file']);
    (new PruneExpiredSessions)();
    expect(DB::table('sessions')->count())->toBe(2);

    config(['session.driver' => 'database']);
    (new PruneExpiredSessions)();
    expect(DB::table('sessions')->pluck('id')->all())->toBe(['live']);
});

it('prunes failed attempts only when old, unverified, without order or child', function (): void {
    $old = now()->subDays(91);
    $failed = fn (array $o = [], $created = null) => schedAttempt(array_replace(['status' => CheckoutAttemptStatus::FAILED], $o), $created ?? $old);
    $prunable = $failed();
    $recent = $failed(created: now()->subDays(89));
    $verified = $failed(['verified_at' => now()->subDays(91)]);
    $ordered = $failed();
    schedOrderFor($ordered);
    $parent = $failed();
    $child = schedAttempt(['parent_attempt_id' => $parent->id, 'status' => CheckoutAttemptStatus::PENDING], now()->subDay());
    $pending = schedAttempt(['status' => CheckoutAttemptStatus::PENDING], $old);

    (new PruneFailedCheckoutAttempts)();

    expect(CheckoutAttempt::find($prunable->id))->toBeNull();
    foreach ([$recent, $verified, $ordered, $parent, $child, $pending] as $kept) {
        expect(CheckoutAttempt::find($kept->id))->not->toBeNull();
    }
});

it('registers every scheduled job with the right frequency', function (): void {
    $events = collect(app(Schedule::class)->events());
    $find = fn (string $needle) => $events->first(fn ($event) => str_contains((string) ($event->command ?? ''), $needle) || str_contains($event->description ?? '', $needle));
    $expected = [
        'checkout:expire-stale-attempts' => '0 * * * *',
        'checkout:reverify-pending-attempts' => '*/15 * * * *',
        'catalog:prune-retired-media' => '0 0 * * *',
        'sessions:prune-expired' => '0 0 * * *',
        'checkout:prune-failed-attempts' => '0 0 * * *',
        'queue:prune-failed --hours=24' => '0 0 * * *',
        'sqlite:optimize' => '0 0 * * *',
        'backups:check-litestream' => '0 * * * *',
    ];
    foreach ($expected as $needle => $cron) {
        $event = $find($needle);
        expect($event)->not->toBeNull("missing $needle")
            ->and($event->expression)->toBe($cron)
            ->and($event->withoutOverlapping)->toBeTrue();
    }
    expect($events)->toHaveCount(count($expected));
});

it('lists the placeholder litestream command as not configured', function (): void {
    $this->artisan('backups:check-litestream')->expectsOutput('Litestream freshness check not configured (Phase 7.4)')->assertExitCode(0);
    $this->artisan('schedule:list')->assertExitCode(0);
});

it('does not downgrade an attempt a webhook verified while the provider call was in flight', function (): void {
    $attempt = schedAttempt();
    Http::fake(['api.paystack.co/*' => function () use ($attempt) {
        DB::table('checkout_attempts')->where('id', $attempt->id)->update(['verified_at' => now()]);

        return Http::response(['status' => true, 'data' => ['status' => 'failed']]);
    }]);

    (new ReverifyPendingCheckoutAttempts)();

    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING);
});

it('does not mark a verified attempt as mismatch', function (): void {
    $attempt = schedAttempt(['verified_at' => now()]);

    app(HandleChargeSuccess::class)->execute($attempt, ['status' => 'success', 'reference' => 'OTHER']);

    expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)
        ->and(AuditLog::query()->where('action', 'checkout.mismatch.detected')->count())->toBe(0);
});

it('treats an unlinked order sharing the reference as an order for expiry and pruning', function (): void {
    $stale = schedAttempt(['expires_at' => now()->subMinute(), 'paystack_reference' => 'PSK-SHARED-1']);
    $failed = schedAttempt(['status' => CheckoutAttemptStatus::FAILED, 'paystack_reference' => 'PSK-SHARED-2'], now()->subDays(91));
    foreach (['PSK-shared-1', 'psk-SHARED-2'] as $reference) {
        $customer = Customer::query()->create(['public_id' => (string) Str::ulid(), 'full_name' => 'Ama', 'phone_e164' => '+2332'.random_int(10000000, 99999999), 'phone_display' => '0241234567']);
        Order::query()->forceCreate([
            'order_number' => 'VHI-'.Str::random(8), 'tracking_token' => Str::random(32), 'source' => 'MANUAL', 'customer_id' => $customer->id,
            'status' => 'PAID', 'subtotal_minor' => 1000, 'total_paid_minor' => 1000, 'currency' => 'GHS', 'payment_method' => 'card',
            'payment_reference' => $reference, 'customer_snapshot_json' => '{}', 'delivery_snapshot_json' => '{}', 'paid_at' => now(),
        ]);
    }

    (new ExpireStaleCheckoutAttempts)();
    (new PruneFailedCheckoutAttempts)();

    expect($stale->fresh()->status)->toBe(CheckoutAttemptStatus::PENDING)
        ->and(CheckoutAttempt::find($failed->id))->not->toBeNull();
});

it('keeps the scheduled attempt available when the ten second throttle rejects the claim', function (): void {
    $attempt = schedAttempt();
    DB::table('checkout_attempts')->where('id', $attempt->id)->update(['last_verified_at' => now()]);
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']])]);

    (new ReverifyPendingCheckoutAttempts)();
    Http::assertNothingSent();
    expect($attempt->fresh()->scheduled_verified_at)->toBeNull();

    DB::table('checkout_attempts')->where('id', $attempt->id)->update(['last_verified_at' => now()->subMinute()]);
    (new ReverifyPendingCheckoutAttempts)();
    Http::assertSentCount(1);
    expect($attempt->fresh()->scheduled_verified_at)->not->toBeNull();
});

it('prunes sessions on the configured session connection', function (): void {
    config(['session.driver' => 'database', 'session.lifetime' => 60, 'session.connection' => 'sched_sessions']);
    config(['database.connections.sched_sessions' => config('database.connections.'.config('database.default'))]);
    DB::connection('sched_sessions')->statement('create table if not exists sessions (id varchar primary key, user_id integer, ip_address varchar, user_agent text, payload text, last_activity integer)');
    DB::connection('sched_sessions')->table('sessions')->insert(['id' => 'old', 'payload' => '', 'last_activity' => now()->subMinutes(120)->getTimestamp()]);

    (new PruneExpiredSessions)();

    expect(DB::connection('sched_sessions')->table('sessions')->count())->toBe(0);
});
