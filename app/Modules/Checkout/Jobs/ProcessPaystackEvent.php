<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Actions\HandleChargeSuccess;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Orders\Actions\CreateOrderFromAttempt;
use App\Modules\Orders\Actions\HandleRefundWebhook;
use App\Modules\Orders\Actions\LinkAttemptToOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessPaystackEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 40;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly int $paymentEventId) {}

    public function handle(PaystackClient $paystack, HandleChargeSuccess $handleChargeSuccess, ?HandleRefundWebhook $handleRefund = null): void
    {
        $event = PaymentEvent::query()->find($this->paymentEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        if ($event->event_type === 'charge.success') {
            $attempt = CheckoutAttempt::query()->where('paystack_reference', $event->reference)->first();
            if ($attempt !== null) {
                if (app(LinkAttemptToOrder::class)->existingOrder($attempt) !== null) {
                    // Reference already on an order: settle the attempt locally, no provider call, no retry.
                    app(CreateOrderFromAttempt::class)->execute($attempt);
                } else {
                    // Provider I/O deliberately precedes all checkout/order write transactions.
                    $verified = $paystack->verify($attempt->paystack_reference);
                    $handleChargeSuccess->execute($attempt, $verified);
                }
            }
        }

        if (in_array($event->event_type, ['refund.processed', 'refund.failed'], true)) {
            // Idempotent, never throws for business conflicts (they become reconciliation audit rows).
            ($handleRefund ?? app(HandleRefundWebhook::class))->execute($event);
        }

        $event->markProcessed();
    }
}
