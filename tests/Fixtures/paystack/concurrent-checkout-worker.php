<?php

use App\Modules\Checkout\Actions\CheckCheckoutStatus;
use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Actions\RetryCheckout;
use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Orders\Events\OrderPaid;
use App\Support\Http\ApiException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $databasePath, $reference, $scenario, $role, $barrierDirectory] = $argv + [null, null, null, null, null, null];
if (! is_string($databasePath) || $databasePath === '' || $databasePath === ':memory:' || ! is_file($databasePath)
    || ! is_string($reference) || $reference === '' || ! in_array($scenario, ['paid-race', 'poll-race', 'retry-race'], true)
    || ! is_string($role) || ! preg_match('/^[a-z0-9_-]+$/', $role) || ! is_string($barrierDirectory) || ! is_dir($barrierDirectory)) {
    fwrite(STDERR, "Invalid worker arguments; refusing to use a fallback database.\n");
    exit(2);
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $databasePath, 'services.paystack.secret_key' => 'sk_test_concurrent', 'services.paystack.base_url' => 'https://api.paystack.co', 'services.storefront.url' => 'https://shop.example']);
app(DatabaseManager::class)->purge('sqlite');
Http::preventStrayRequests();

$verification = static function (CheckoutAttempt $attempt): array {
    return [
        'status' => 'success', 'reference' => $attempt->paystack_reference, 'amount' => $attempt->amount_minor,
        'currency' => 'GHS', 'channel' => 'mobile_money', 'paid_at' => '2026-10-10T10:00:00Z',
        'metadata' => ['attemptId' => $attempt->public_id],
    ];
};
$append = static function (string $file, string $value): void {
    file_put_contents($file, $value."\n", FILE_APPEND | LOCK_EX);
};
$waitFor = static function (string $file): void {
    $deadline = microtime(true) + 10;
    while (! is_file($file)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for process barrier: '.$file);
        }
        usleep(10000);
    }
};

try {
    $attempt = CheckoutAttempt::query()->where('paystack_reference', $reference)->firstOrFail();
    if ($scenario === 'paid-race') {
        $event = PaymentEvent::query()->where('reference', $reference)->firstOrFail();
        Event::listen(OrderPaid::class, static function (OrderPaid $paid) use ($append, $barrierDirectory, $role): void {
            $append($barrierDirectory.'/order-paid.events', $role.':'.$paid->order->order_number);
        });
    }

    Http::fake(static function ($request) use ($scenario, $role, $barrierDirectory, $attempt, $verification, $append, $waitFor) {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Provider HTTP fake ran inside a transaction.');
        }
        $url = $request->url();
        if (str_ends_with($url, '/transaction/verify/'.$attempt->paystack_reference)) {
            $append($barrierDirectory.'/provider.calls', $scenario.':'.$role.':verify');
            if (in_array($scenario, ['paid-race', 'retry-race'], true)) {
                file_put_contents($barrierDirectory.'/'.$role.'.verify.ready', 'ready', LOCK_EX);
                $waitFor($barrierDirectory.'/release.verify');
            } else {
                usleep(250000);
            }
            $data = match ($scenario) {
                'retry-race' => ['status' => 'reversed'],
                'poll-race' => ['status' => 'pending'],
                default => $verification($attempt),
            };

            return Http::response(['status' => true, 'data' => $data]);
        }
        if (str_ends_with($url, '/transaction/initialize')) {
            $append($barrierDirectory.'/provider.calls', $scenario.':'.$role.':initialize');

            return Http::response(['status' => true, 'data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/concurrent']]);
        }

        throw new RuntimeException('Unexpected provider request: '.$url);
    });

    $result = null;
    if ($scenario === 'paid-race') {
        if ($role === 'webhook') {
            (new ProcessPaystackEvent((int) $event->id))->handle(app(PaystackClient::class), app(HandleChargeSuccess::class));
            $result = ['processed' => $event->fresh()->processed_at !== null];
        } else {
            $result = app(CheckCheckoutStatus::class)->execute($attempt);
        }
    } elseif ($scenario === 'poll-race') {
        file_put_contents($barrierDirectory.'/'.$role.'.started', 'ready', LOCK_EX);
        $waitFor($barrierDirectory.'/release.start');
        $result = app(CheckCheckoutStatus::class)->execute($attempt);
    } else {
        try {
            app(RetryCheckout::class)->execute($reference);
            file_put_contents($barrierDirectory.'/'.$role.'.result', 'created', LOCK_EX);
        } catch (HttpExceptionInterface $exception) {
            file_put_contents($barrierDirectory.'/'.$role.'.result', (string) $exception->getStatusCode().':'.($exception instanceof ApiException ? $exception->errorCode : ''), LOCK_EX);
        }
    }

    echo json_encode(['ok' => true, 'database' => config('database.connections.sqlite.database'), 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(1);
}
