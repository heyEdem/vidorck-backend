<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Notifications\Actions\CountFailedNotifications;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Mail\EmailNotificationSender;
use App\Modules\Notifications\Models\NotificationAttempt;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Planners\OrderNotificationPlanner;
use App\Modules\Notifications\Sms\SmsMessage;
use App\Modules\Notifications\Sms\SmsNotificationSender;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\SendResult;
use App\Modules\Orders\Actions\CreateManualOrder;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\RequestCancellation;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\DatabaseMigrations;

require_once __DIR__.'/NotificationTestHelpers.php';

uses(DatabaseMigrations::class);

function ntOrder(?string $email = 'ama@example.com'): Order
{
    return app(CreateOrderFromAttempt::class)->execute(ntAttempt($email));
}

/** @return list<string> */
function ntKeys(string $like = ''): array
{
    return NotificationLog::query()->where('idempotency_key', 'like', '%'.$like.'%')->orderBy('id')->pluck('idempotency_key')->all();
}

function ntTemplates(): array
{
    return NotificationLog::query()->orderBy('id')->pluck('template')->all();
}

function ntJobs(): int
{
    return DB::table('jobs')->count();
}

it('sends a confirmation email and SMS on an online paid order', function () {
    ntOrder();
    expect(ntTemplates())->toBe(['order-confirmation', 'sms.customer_order_paid'])->and(ntJobs())->toBe(2);
});

it('texts the paid customer once, to their own number, with the paid total and no staff details', function () {
    config(['services.storefront.url' => 'https://shop.test']);
    $order = ntOrder();
    $job = collect(ntCommands())->first(fn ($c) => $c->payload->template === 'sms.customer_order_paid');
    expect($job->channel)->toBe(NotificationChannel::SMS)->and($job->recipient)->toBe('+233241234567')
        ->and(SmsMessage::forPayload($job->payload))->toBe("Vidorck Hair: Hi Ama! Payment of GHS 10.00 received. Order {$order->order_number} is confirmed and being prepared. Track: https://shop.test/track-order");
    expect(ntKeys('order-paid:'.$order->order_number.':customer'))->toHaveCount(2); // one per channel
    app(OrderNotificationPlanner::class)->orderPaid($order);
    expect(NotificationLog::query()->count())->toBe(2);
});

it('sends only the confirmation email when the customer phone is invalid', function () {
    $attempt = ntAttempt();
    $attempt->customer_snapshot_json = array_merge($attempt->customer_snapshot_json, ['phoneE164' => 'not-a-phone']);
    $attempt->save();
    app(CreateOrderFromAttempt::class)->execute($attempt);
    expect(ntTemplates())->toBe(['order-confirmation']);
});

it('sends staff nothing when an order is paid', function () {
    ntOrder();
    expect(ntKeys(':staff'))->toBe([])->and(ntTemplates())->toBe(['order-confirmation', 'sms.customer_order_paid']);
});

it('skips the customer email when none is on file', function () {
    ntOrder(null);
    expect(ntTemplates())->toBe(['sms.customer_order_paid']);
});

it('never fails order creation on a malformed customer email', function () {
    Log::spy();
    $order = ntOrder('no-at-sign');
    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and(ntTemplates())->toBe(['sms.customer_order_paid']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode([$message, $context]), 'no-at-sign'))->atLeast()->once();
});

it('sends once when the same order is finalised twice', function () {
    $attempt = ntAttempt();
    app(CreateOrderFromAttempt::class)->execute($attempt);
    app(CreateOrderFromAttempt::class)->execute($attempt->fresh());
    expect(NotificationLog::query()->count())->toBe(2);
});

it('uses frozen snapshots for the payload', function () {
    $order = ntOrder();
    DB::table('order_items')->where('order_id', $order->id)->update(['name_snapshot' => 'Oil']);
    $payload = ntCommands()[0];
    expect($payload->payload->data['items'][0])->toBe(['name' => 'Oil', 'size' => '100ml', 'qty' => 2, 'child' => false])
        ->and($payload->payload->data['customerName'])->toBe('Ama Mensah');
});

it('creates notifications for manual orders and skips the email when absent', function () {
    $category = Category::query()->create(['name' => 'Hair care', 'slug' => 'hair-care', 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Shampoo', 'slug' => 'shampoo', 'category_id' => $category->id, 'is_published' => true]);
    $product->variants()->create(['size_label' => '250ml', 'product_code' => 'SH-001', 'source_price_minor' => 1400, 'price_minor' => 1300, 'is_active' => true]);
    $input = fn (string $ref, ?string $email) => [
        'fullName' => 'Ama Mensah', 'phone' => '0244000000', 'email' => $email, 'region' => 'Greater Accra', 'area' => 'Tema', 'address' => '12 Oxford Street',
        'specialArrangement' => true, 'paymentMethod' => 'MOMO', 'paymentReference' => $ref, 'amountPaidMinor' => 2600,
        'items' => [['slug' => 'shampoo', 'productCode' => 'SH-001', 'quantity' => 2, 'lineId' => 'l1']],
    ];
    app(CreateManualOrder::class)->execute($input('M-1', 'ama@example.com'), ntAdmin());
    expect(ntTemplates())->toBe(['order-confirmation', 'sms.customer_order_paid']);
    app(CreateManualOrder::class)->execute($input('M-2', null), ntAdmin());
    expect(ntTemplates())->toBe(['order-confirmation', 'sms.customer_order_paid', 'sms.customer_order_paid']);
});

it('emails and texts milestones once, never for processing, corrections or revisits', function () {
    $order = ntOrder();
    $machine = app(OrderStateMachine::class);
    $admin = ntAdmin();
    $machine->transition($order, OrderStatus::PROCESSING, $admin);
    expect(ntKeys('order-status'))->toBe([]);
    $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, $admin);
    $machine->transition($order, OrderStatus::PROCESSING, $admin, 'wrong click');
    $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, $admin);
    $machine->transition($order, OrderStatus::DISPATCHED, $admin);
    $machine->transition($order, OrderStatus::DELIVERED, $admin);
    expect(array_slice(ntTemplates(), 2))->toBe([
        'ready-for-dispatch', 'sms.customer_ready_for_dispatch', 'dispatched', 'sms.customer_dispatched', 'delivered', 'sms.customer_delivered',
    ]);
    expect(ntKeys('order-status:'.$order->order_number.':READY_FOR_DISPATCH'))->toHaveCount(2); // email + SMS, one each
});

it('texts each milestone to the customer with their first name and order number', function () {
    $order = ntOrder();
    $machine = app(OrderStateMachine::class);
    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED, OrderStatus::DELIVERED] as $step) {
        $machine->transition($order, $step, ntAdmin());
    }
    $texts = collect(ntCommands())->filter(fn ($c) => $c->channel === NotificationChannel::SMS && str_starts_with($c->payload->template, 'sms.customer_') && $c->payload->template !== 'sms.customer_order_paid')
        ->map(fn ($c) => [$c->recipient, SmsMessage::forPayload($c->payload)])->values()->all();
    $n = $order->order_number;
    expect($texts)->toBe([
        ['+233241234567', "Vidorck Hair: Hi Ama! Order {$n} is packed and ready. We'll WhatsApp or call you to confirm the courier and delivery fee before dispatch."],
        ['+233241234567', "Vidorck Hair: Hi Ama! Order {$n} is on its way. Rider details will follow on WhatsApp, so please keep your phone nearby."],
        ['+233241234567', "Vidorck Hair: Hi Ama! Order {$n} has been delivered. We hope you love it! Thank you for choosing Vidorck Hair."],
    ]);
});

it('still emails a milestone when the customer phone is invalid, and texts when there is no email', function () {
    $order = ntOrder(null);
    $machine = app(OrderStateMachine::class);
    $machine->transition($order, OrderStatus::PROCESSING, ntAdmin());
    $machine->transition($order, OrderStatus::READY_FOR_DISPATCH, ntAdmin());
    expect(array_slice(ntTemplates(), 1))->toBe(['sms.customer_ready_for_dispatch']);

    $badPhone = ntOrder();
    DB::table('orders')->where('id', $badPhone->id)->update(['customer_snapshot_json' => json_encode(array_merge((array) $badPhone->customer_snapshot_json, ['phoneE164' => 'not-a-phone']))]);
    $machine->transition($badPhone->fresh(), OrderStatus::PROCESSING, ntAdmin());
    $machine->transition($badPhone->fresh(), OrderStatus::READY_FOR_DISPATCH, ntAdmin());
    expect(array_slice(ntTemplates(), -1))->toBe(['ready-for-dispatch']);
});

it('acknowledges a cancellation request by email and customer SMS only, without the reason', function () {
    $order = ntOrder();
    app(RequestCancellation::class)->execute($order->order_number, '0241234567', 'secret reason text');
    $logs = NotificationLog::query()->whereIn('template', ['cancellation-received', 'sms.customer_cancellation_received'])->get();
    expect($logs)->toHaveCount(2)->and(NotificationLog::query()->where('idempotency_key', 'like', 'cancellation:%')->count())->toBe(2);
    foreach (ntCommands() as $job) {
        expect(json_encode($job->payload->data))->not->toContain('secret reason text');
    }
});

it('texts the customer the cancellation acknowledgement once, to their own number', function () {
    $order = ntOrder(null);
    $request = app(RequestCancellation::class)->execute($order->order_number, '0241234567', 'secret reason text');
    $job = collect(ntCommands())->first(fn ($c) => $c->payload->template === 'sms.customer_cancellation_received');
    expect($job->recipient)->toBe('+233241234567')
        ->and(SmsMessage::forPayload($job->payload))->toBe("Vidorck Hair: we got your cancellation request for order {$order->order_number}. This doesn't cancel the order yet; we'll be in touch.")
        ->and(ntKeys('cancellation:'))->toHaveCount(1)
        ->and(ntKeys('cancellation:'))->each->toContain(':customer:SMS:');
    app(OrderNotificationPlanner::class)->cancellationRequested($order->fresh(), RefundRequest::query()->latest('id')->firstOrFail());
    expect(ntKeys('cancellation:'))->toHaveCount(1);
});

it('leaves no notification when the business transaction rolls back', function () {
    try {
        DB::transaction(function () {
            ntOrder();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(NotificationLog::query()->count())->toBe(0)->and(ntJobs())->toBe(0);
});

it('commits the order and burns no key when the queue push fails inside the business transaction', function () {
    DB::unprepared("CREATE TRIGGER jobs_fail BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue down'); END");
    $attempt = ntAttempt();
    $order = app(CreateOrderFromAttempt::class)->execute($attempt);
    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and(NotificationLog::query()->count())->toBe(0)->and(ntJobs())->toBe(0);
    DB::unprepared('DROP TRIGGER jobs_fail');
    app(OrderNotificationPlanner::class)->orderPaid($order);
    expect(ntTemplates())->toBe(['order-confirmation', 'sms.customer_order_paid'])->and(ntJobs())->toBe(2);
});

it('includes priced child lines in the confirmation payload but hides free composition lines', function () {
    $attempt = ntAttempt();
    $attempt->items_snapshot_json = [
        ['lineId' => 'b', 'lineType' => 'BUNDLE', 'name' => 'Gift set', 'productCode' => 'GS', 'quantity' => 1, 'unitPriceMinor' => 800, 'lineTotalMinor' => 800],
        ['lineId' => 'x', 'parentLineId' => 'b', 'lineType' => 'BUNDLE_EXTRA', 'name' => 'Extra box', 'productCode' => 'XB', 'quantity' => 1, 'unitPriceMinor' => 200, 'lineTotalMinor' => 200],
        ['lineId' => 'f', 'parentLineId' => 'b', 'lineType' => 'BUNDLE_EXTRA', 'name' => 'Free sample', 'productCode' => 'FS', 'quantity' => 1, 'unitPriceMinor' => 0, 'lineTotalMinor' => 0],
    ];
    $attempt->save();
    app(CreateOrderFromAttempt::class)->execute($attempt);
    $items = ntCommands()[0]->payload->data['items'];
    expect(array_column($items, 'name'))->toBe(['Gift set', 'Extra box'])->and(array_column($items, 'child'))->toBe([false, true]);
    DB::statement('PRAGMA foreign_keys = OFF'); // the self-referencing parent_item_id rows would otherwise block the teardown table drop
});

function ntFailingSender(NotificationChannel $channel, callable $fails): NotificationSender
{
    return new class($channel, $fails) implements NotificationSender
    {
        public function __construct(private NotificationChannel $channel, private $fails) {}

        public function channel(): NotificationChannel
        {
            return $this->channel;
        }

        public function send(string $recipient, NotificationPayload $payload): SendResult
        {
            if (($this->fails)($recipient)) {
                throw new TransientNotificationException('provider 500');
            }

            return new SendResult('fake', 'ok-'.$recipient);
        }
    };
}

it('isolates SMS failure from the email: email SENT, SMS FAILED and retryable', function () {
    ntOrder();
    app()->instance(SmsNotificationSender::class, ntFailingSender(NotificationChannel::SMS, fn (): bool => true));

    $jobs = ntCommands();
    expect($jobs)->toHaveCount(2);
    foreach ($jobs as $job) {
        try {
            app()->call([$job, 'handle']);
        } catch (TransientNotificationException) {
        }
    }

    $latest = fn (SendNotification $job): string => NotificationAttempt::query()->where('notification_log_id', $job->logId)->orderByDesc('id')->first()->event_type->value;
    expect(array_map(fn ($j) => [$j->channel, $latest($j)], $jobs))->toBe([[NotificationChannel::EMAIL, 'SENT'], [NotificationChannel::SMS, 'FAILED']])
        ->and(app(CountFailedNotifications::class)->execute())->toBe(1);
});

it('keeps the order DISPATCHED when the email provider fails, records FAILED and retries the job', function () {
    $order = ntOrder();
    foreach ([OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH, OrderStatus::DISPATCHED] as $step) {
        app(OrderStateMachine::class)->transition($order, $step, ntAdmin());
    }
    $job = collect(ntCommands())->first(fn ($c) => $c->payload->template === 'dispatched');
    app()->instance(EmailNotificationSender::class, ntFailingSender(NotificationChannel::EMAIL, fn (): bool => true));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(TransientNotificationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::DISPATCHED)
        ->and(DB::table('order_status_events')->where('order_id', $order->id)->where('to_status', 'DISPATCHED')->exists())->toBeTrue()
        ->and(NotificationAttempt::query()->where('notification_log_id', $job->logId)->where('event_type', 'FAILED')->count())->toBe(1);

    // The worker retries after backoff and the next run succeeds, independent of the order.
    app()->instance(EmailNotificationSender::class, ntFailingSender(NotificationChannel::EMAIL, fn (): bool => false));
    $this->travel(2)->minutes();
    app()->call([$job, 'handle']);
    expect(NotificationAttempt::query()->where('notification_log_id', $job->logId)->orderByDesc('id')->first()->event_type->value)->toBe('SENT')
        ->and($order->fresh()->status)->toBe(OrderStatus::DISPATCHED);
});
