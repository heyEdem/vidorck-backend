<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Notifications\Planners\RefundNotificationPlanner;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Enums\RefundTransactionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Actions\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * refund.processed / refund.failed from the existing payment_events path (product-spec 6.8, website-spec 16.2).
 * Idempotent: a replay finds the request already terminal with a matching transaction fact and does nothing.
 * Never throws for business conflicts (that would retry five times and then vanish into failed_jobs); conflicts are
 * recorded as an immutable system audit row `refund.reconciliation_required` and are derivable as
 * "COMPLETED request whose order is not REFUNDED".
 */
final class HandleRefundWebhook
{
    public function __construct(private readonly AuditLogger $audit, private readonly OrderStateMachine $machine) {}

    public function execute(PaymentEvent $event): void
    {
        if (! in_array($event->event_type, ['refund.processed', 'refund.failed'], true)) {
            return;
        }
        $processed = $event->event_type === 'refund.processed';
        $data = is_array($event->payload_redacted_json) ? $event->payload_redacted_json : [];
        $refundRef = (string) ($data['refund_reference'] ?? $data['id'] ?? 'event-'.$event->id);
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : null;

        DB::transaction(function () use ($event, $processed, $data, $refundRef, $amount): void {
            $order = $event->reference === '' ? null : Order::query()->where('payment_reference', $event->reference)->first();
            if ($order === null) {
                Log::warning('Refund webhook for an unknown charge reference.', ['payment_event_id' => $event->id]);
                $this->audit->record(null, 'refund.webhook.unmatched', null, [], ['paymentEventId' => $event->id, 'event' => $event->event_type]);

                return;
            }
            $wanted = $processed ? RefundTransactionStatus::PROCESSED : RefundTransactionStatus::FAILED;
            $seen = RefundTransaction::query()->where('provider_reference', $refundRef)->where('status', $wanted->value)
                ->whereIn('refund_request_id', RefundRequest::query()->where('order_id', $order->id)->select('id'))->exists();
            if ($seen) {
                return;
            }
            $refund = RefundRequest::query()->where('order_id', $order->id)
                ->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::inFlight()))
                ->orderByDesc('id')->first();
            if ($refund === null) {
                if ($processed) {
                    $this->flag($order, null, $event, 'NO_INFLIGHT_REQUEST');
                }

                return;
            }
            $from = $refund->status->value;
            $to = $processed ? RefundStatus::COMPLETED : RefundStatus::FAILED;
            $claimed = RefundRequest::query()->whereKey($refund->id)->where('status', $from)
                ->update(['status' => $to->value, 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            RefundTransaction::query()->create([
                'refund_request_id' => $refund->id, 'method' => RefundMethod::PAYSTACK, 'provider_reference' => $refundRef,
                'amount_minor' => $amount ?? (int) $order->total_paid_minor, 'status' => $wanted,
                'response_redacted_json' => array_intersect_key($data, array_flip(['id', 'status', 'currency', 'amount', 'refund_reference'])),
                'created_at' => now(),
            ]);
            $this->audit->record(null, $processed ? 'refund.completed' : 'refund.failed', $refund,
                ['status' => $from], ['status' => $to->value, 'orderNumber' => $order->order_number, 'providerRefundId' => $refundRef]);
            if (! $processed) {
                app(RefundNotificationPlanner::class)->failed($order, $refund);

                return;
            }

            $current = OrderStatus::from((string) DB::table('orders')->where('id', $order->id)->value('status'));
            $currency = $data['currency'] ?? 'GHS';
            if ($amount !== null && $amount !== (int) $order->total_paid_minor || $currency !== 'GHS') {
                $this->flag($order, $refund, $event, 'AMOUNT_MISMATCH');
            } elseif (! $current->acceptsCancellationRequest()) {
                $this->flag($order, $refund, $event, 'ORDER_NOT_REFUNDABLE:'.$current->value);
            } else {
                $this->machine->transitionForRefund($order, OrderStatus::REFUNDED, null, 'Refund confirmed by Paystack');
                app(RefundNotificationPlanner::class)->completed($order, $refund);
            }
        }, 3);
    }

    private function flag(Order $order, ?RefundRequest $refund, PaymentEvent $event, string $code): void
    {
        Log::error('Refund confirmed by provider but the order was not moved to REFUNDED.', ['order' => $order->order_number, 'code' => $code]);
        $this->audit->record(null, 'refund.reconciliation_required', $refund ?? $order, [], [
            'orderNumber' => $order->order_number, 'reason' => $code, 'paymentEventId' => $event->id,
        ]);
    }
}
