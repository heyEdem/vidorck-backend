<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function concurrencyDatabase(Closure $test): void
{
    $directory = sys_get_temp_dir().'/vhi-money-flow-'.Str::random(16);
    mkdir($directory, 0700, true);
    $database = $directory.'/checkout.sqlite';
    touch($database);
    $previousDefault = config('database.default');
    $previousDatabase = config('database.connections.sqlite.database');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
    DB::purge('sqlite');

    try {
        DB::connection('sqlite')->getPdo();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $test($database, $directory);
    } finally {
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        config(['database.default' => $previousDefault, 'database.connections.sqlite.database' => $previousDatabase]);
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

function concurrencyAttempt(array $overrides = []): CheckoutAttempt
{
    return CheckoutAttempt::query()->create(array_replace([
        'public_id' => (string) Str::ulid(), 'paystack_reference' => 'REF-CONCURRENT-'.Str::random(12),
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => 'ama@example.test'],
        'delivery_snapshot_json' => ['region' => 'Greater Accra', 'area' => 'Osu', 'address' => '12 Oxford Street', 'landmark' => 'Near the mall', 'ghanaPostGps' => 'GA-123-4567'],
        'items_snapshot_json' => [['lineId' => 'line-1', 'lineType' => 'PRODUCT', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => CheckoutAttemptStatus::PENDING,
        'expires_at' => now()->addHour(), 'created_at' => now()->subSeconds(20),
    ], $overrides));
}

/** @return array{Process, Process} */
function startConcurrencyWorkers(string $database, string $reference, string $scenario, array $roles, string $directory): array
{
    $script = base_path('tests/Fixtures/paystack/concurrent-checkout-worker.php');
    $processes = [];
    foreach ($roles as $role) {
        $process = new Process([PHP_BINARY, $script, $database, $reference, $scenario, $role, $directory], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(30);
        $process->start();
        $processes[] = $process;
    }

    return $processes;
}

/** @param list<string> $paths */
function awaitConcurrencyFiles(array $paths, array $processes): void
{
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        if (array_reduce($paths, static fn (bool $ready, string $path): bool => $ready && is_file($path), true)) {
            return;
        }
        foreach ($processes as $process) {
            if (! $process->isRunning() && $process->getExitCode() !== 0) {
                throw new RuntimeException('Concurrency worker failed: '.$process->getErrorOutput().$process->getOutput());
            }
        }
        usleep(10000);
    }

    $states = array_map(static fn (string $path): string => basename($path).':'.(is_file($path) ? 'ready' : 'missing'), $paths);
    throw new RuntimeException('Timed out waiting for concurrency barrier files: '.implode(', ', $states)."\n".implode("\n", array_map(static fn (Process $process): string => $process->getErrorOutput().$process->getOutput(), $processes)));
}

/** @param list<Process> $processes */
function finishConcurrencyWorkers(array $processes): array
{
    $outputs = [];
    try {
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Concurrency worker failed ('.$process->getExitCode().'): '.$process->getErrorOutput().$process->getOutput());
            }
            $outputs[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1, SIGTERM);
            }
        }
    }

    return $outputs;
}

it('creates one paid order when independent webhook and poll PHP processes verify concurrently', function (): void {
    concurrencyDatabase(function (string $database, string $directory): void {
        $attempt = concurrencyAttempt();
        $attempt->forceFill(['created_at' => now()->subMinute()])->save();
        $event = PaymentEvent::query()->create([
            'provider' => 'paystack', 'provider_event_id' => 'charge.success:'.$attempt->paystack_reference,
            'event_type' => 'charge.success', 'reference' => $attempt->paystack_reference,
            'signature_valid' => true, 'payload_redacted_json' => ['reference' => $attempt->paystack_reference],
        ]);
        $processes = startConcurrencyWorkers($database, $attempt->paystack_reference, 'paid-race', ['webhook', 'poll'], $directory);

        try {
            awaitConcurrencyFiles([$directory.'/webhook.verify.ready', $directory.'/poll.verify.ready'], $processes);
            expect(file_get_contents($directory.'/webhook.verify.ready'))->toBe('ready')
                ->and(file_get_contents($directory.'/poll.verify.ready'))->toBe('ready');
            touch($directory.'/release.verify');
            $outputs = finishConcurrencyWorkers($processes);
            expect(collect($outputs)->pluck('database')->unique()->all())->toBe([$database]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1, SIGTERM);
                }
            }
        }

        $order = Order::query()->sole();
        expect($attempt->fresh()->status)->toBe(CheckoutAttemptStatus::SUCCEEDED)
            ->and($event->fresh()->processed_at)->not->toBeNull()
            ->and(Customer::query()->count())->toBe(1)
            ->and(Customer::query()->sole()->orders_count)->toBe(1)
            ->and(Customer::query()->sole()->lifetime_paid_minor)->toBe(1000)
            ->and(DB::table('order_number_sequences')->where('year', 2026)->value('last_value'))->toBe(1)
            ->and(OrderStatusEvent::query()->where('order_id', $order->id)->where('to_status', 'PAID')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'order.paid')->where('target_id', (string) $order->id)->count())->toBe(1)
            ->and(count(file($directory.'/provider.calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))->toBe(2)
            ->and(count(file($directory.'/order-paid.events', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))->toBe(1);
    });
});

it('claims one Paystack verification across two simultaneous poll processes', function (): void {
    concurrencyDatabase(function (string $database, string $directory): void {
        $attempt = concurrencyAttempt();
        $attempt->forceFill(['created_at' => now()->subMinute()])->save();
        $processes = startConcurrencyWorkers($database, $attempt->paystack_reference, 'poll-race', ['poll-a', 'poll-b'], $directory);

        try {
            awaitConcurrencyFiles([$directory.'/poll-a.started', $directory.'/poll-b.started'], $processes);
            touch($directory.'/release.start');
            $outputs = finishConcurrencyWorkers($processes);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1, SIGTERM);
                }
            }
        }

        expect(collect($outputs)->pluck('result.status')->all())->toBe(['PENDING', 'PENDING'])
            ->and(count(file($directory.'/provider.calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))->toBe(1)
            ->and($attempt->fresh()->last_verified_at)->not->toBeNull()
            ->and(Order::query()->count())->toBe(0);
    });
});

it('initializes only one retry when independent requests race for the same parent', function (): void {
    concurrencyDatabase(function (string $database, string $directory): void {
        $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
        $variant = $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1000, 'price_minor' => 1000, 'is_active' => true]);
        DeliveryArea::query()->create(['name' => 'Osu', 'aliases_json' => [], 'is_active' => true]);
        $attempt = concurrencyAttempt([
            'status' => CheckoutAttemptStatus::FAILED,
            'items_snapshot_json' => [['slug' => 'shampoo', 'productVariantId' => $variant->id, 'lineId' => 'line-1', 'parentLineId' => null, 'lineType' => 'PRODUCT', 'quantity' => 1, 'unitPriceMinor' => 1000, 'lineTotalMinor' => 1000, 'name' => 'Shampoo', 'productCode' => 'SH-001']],
        ]);
        $processes = startConcurrencyWorkers($database, $attempt->paystack_reference, 'retry-race', ['retry-a', 'retry-b'], $directory);

        try {
            awaitConcurrencyFiles([$directory.'/retry-a.verify.ready', $directory.'/retry-b.verify.ready'], $processes);
            touch($directory.'/release.verify');
            finishConcurrencyWorkers($processes);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1, SIGTERM);
                }
            }
        }

        $results = [file_get_contents($directory.'/retry-a.result'), file_get_contents($directory.'/retry-b.result')];
        $calls = file($directory.'/provider.calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(CheckoutAttempt::query()->where('parent_attempt_id', $attempt->id)->count())->toBe(1)
            ->and(count(array_filter($calls, fn (string $call): bool => str_ends_with($call, ':initialize'))))->toBe(1)
            ->and(count(array_filter($calls, fn (string $call): bool => str_ends_with($call, ':verify'))))->toBe(2)
            ->and(count(array_filter($results, fn (string $result): bool => $result === 'created')))->toBe(1)
            ->and(count(array_filter($results, fn (string $result): bool => $result === '409:CHECKOUT_ALREADY_RETRIED')))->toBe(1);
    });
});
