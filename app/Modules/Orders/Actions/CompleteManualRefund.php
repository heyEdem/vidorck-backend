<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Notifications\Planners\RefundNotificationPlanner;
use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Enums\RefundTransactionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** website-spec 16.2: Admin-recorded refund of a MANUAL (externally paid) order; full amount only. */
final class CompleteManualRefund
{
    public function __construct(private readonly AuditLogger $audit, private readonly OrderStateMachine $machine) {}

    public function execute(int $refundId, StaffUser $actor, string $method, string $reference, int $amountMinor): RefundRequest
    {
        $reference = trim($reference);

        return DB::transaction(function () use ($refundId, $actor, $method, $reference, $amountMinor): RefundRequest {
            $refund = RefundRequest::query()->findOrFail($refundId);
            $order = Order::query()->findOrFail($refund->order_id);
            if ($order->source !== OrderSource::MANUAL) {
                throw new ApiException(409, 'REFUND_NOT_MANUAL', 'Online-payment refunds are completed through the payment provider.');
            }
            $existing = RefundTransaction::query()->whereRaw('lower(provider_reference) = ?', [mb_strtolower($reference)])->first();
            if ($refund->status === RefundStatus::COMPLETED) {
                // Idempotent replay of the same completion; anything else is a conflict.
                if ($existing !== null && $existing->refund_request_id === $refund->id) {
                    return $refund;
                }
                throw new ApiException(409, 'REFUND_NOT_COMPLETABLE', 'This refund has already been completed.');
            }
            if ($refund->status !== RefundStatus::APPROVED) {
                throw new ApiException(409, 'REFUND_NOT_COMPLETABLE', 'Only an approved refund can be completed manually.');
            }
            if ($existing !== null) {
                throw new ApiException(409, 'DUPLICATE_REFUND_REFERENCE', 'This refund reference has already been used.', ['reference' => ['This reference has already been used.']]);
            }
            if ($amountMinor !== (int) $order->total_paid_minor) {
                throw new ApiException(422, 'AMOUNT_MISMATCH', 'The refund amount must equal the order total (full refunds only).', [
                    'amountMinor' => ["The order total is {$order->total_paid_minor} pesewas."],
                ]);
            }
            $current = OrderStatus::from((string) DB::table('orders')->where('id', $order->id)->value('status'));
            if (! $current->acceptsCancellationRequest()) {
                throw new ApiException(409, 'REFUND_ORDER_NOT_REFUNDABLE', 'This order can no longer be refunded.');
            }
            $claimed = RefundRequest::query()->whereKey($refund->id)->where('status', RefundStatus::APPROVED->value)
                ->update(['status' => RefundStatus::COMPLETED->value, 'updated_at' => now()]);
            if ($claimed !== 1) {
                throw new ApiException(409, 'REFUND_NOT_COMPLETABLE', 'This refund can no longer be completed.');
            }
            RefundTransaction::query()->create([
                'refund_request_id' => $refund->id, 'method' => RefundMethod::MANUAL, 'provider_reference' => $reference,
                'amount_minor' => $amountMinor, 'status' => RefundTransactionStatus::PROCESSED,
                'response_redacted_json' => ['method' => $method, 'recordedByStaffId' => $actor->id], 'created_at' => now(),
            ]);
            $this->machine->transitionForRefund($order, OrderStatus::REFUNDED, $actor, 'Manual refund recorded');
            app(RefundNotificationPlanner::class)->completed($order, $refund);
            $this->audit->record($actor, 'refund.manual_completed', $refund, ['status' => RefundStatus::APPROVED->value], [
                'status' => RefundStatus::COMPLETED->value, 'orderNumber' => $order->order_number,
                'method' => $method, 'reference' => $reference, 'amountMinor' => $amountMinor,
            ]);

            return RefundRequest::query()->findOrFail($refund->id);
        }, 3);
    }
}
