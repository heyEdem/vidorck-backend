<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackRefundRejected;
use App\Modules\Checkout\Paystack\PaystackUnavailable;
use App\Modules\Notifications\Planners\RefundNotificationPlanner;
use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Enums\RefundTransactionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Admin decision on a cancellation/refund request (website-spec section 16.2, product-spec 6.8).
 *
 * The order status is never touched here: it becomes REFUNDED only after provider (webhook) or manual confirmation.
 * The decision commits first; Paystack HTTP runs outside any write transaction and is never retried automatically.
 */
final class DecideRefund
{
    /** A provider-call claim older than this is a crashed attempt. Must exceed 2 x PaystackClient::MONETARY_TIMEOUT_SECONDS. */
    public const CLAIM_WINDOW_SECONDS = 60;

    public function __construct(private readonly AuditLogger $audit, private readonly PaystackClient $paystack) {}

    public function execute(int $refundId, StaffUser $actor, string $decision, ?string $reason): RefundRequest
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $approve = $decision === 'APPROVE';
        if (! $approve && $reason === null) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'A reason is required to reject a request.', ['reason' => ['Provide a reason.']]);
        }

        $online = DB::transaction(function () use ($refundId, $actor, $approve, $reason): ?array {
            $refund = RefundRequest::query()->findOrFail($refundId);
            $order = Order::query()->findOrFail($refund->order_id);
            // Re-read at decision time; only approval is prohibited once dispatched (website-spec 14.2, 16.2).
            $current = OrderStatus::from((string) DB::table('orders')->where('id', $order->id)->value('status'));
            if ($approve && ! $current->acceptsCancellationRequest()) {
                throw new ApiException(409, 'REFUND_ORDER_NOT_REFUNDABLE', 'This order can no longer be refunded.');
            }
            $to = $approve ? RefundStatus::APPROVED : RefundStatus::REJECTED;
            $claimed = RefundRequest::query()->whereKey($refund->id)->whereNull('decided_at')
                ->whereIn('status', [RefundStatus::REQUESTED->value, RefundStatus::UNDER_REVIEW->value])
                ->update([
                    'status' => $to->value, 'decided_by_staff_id' => $actor->id, 'decided_at' => now(),
                    'decision_reason' => $reason, 'provider_attempts' => $approve ? 1 : 0,
                    'provider_call_started_at' => $approve && $order->source === OrderSource::ONLINE ? now() : null, 'updated_at' => now(),
                ]);
            if ($claimed !== 1) {
                throw new ApiException(409, 'REFUND_NOT_DECIDABLE', 'This request has already been decided.');
            }
            $this->audit->record($actor, $approve ? 'refund.approved' : 'refund.rejected', $refund,
                ['status' => $refund->status->value],
                ['status' => $to->value, 'orderNumber' => $order->order_number, 'reason' => $reason, 'source' => $order->source->value]);

            app(RefundNotificationPlanner::class)->decided($order, $refund, $approve);

            return $approve && $order->source === OrderSource::ONLINE
                ? ['reference' => (string) $order->payment_reference, 'amount' => (int) $order->total_paid_minor, 'orderNumber' => $order->order_number]
                : null;
        }, 3);

        if ($online !== null) {
            $this->callProvider($refundId, $actor, $online);
        }

        return RefundRequest::query()->findOrFail($refundId);
    }

    /**
     * Re-attempt the provider call for an APPROVED online request that never got a transaction row (outcome unknown
     * or the call never left). Same outcome handling as the decision. The claim stamps provider_call_started_at, so
     * concurrent retries cannot both POST; a claim older than the HTTP timeout window is treated as a crashed attempt.
     */
    public function retry(int $refundId, StaffUser $actor): RefundRequest
    {
        $online = DB::transaction(function () use ($refundId, $actor): array {
            $refund = RefundRequest::query()->findOrFail($refundId);
            $order = Order::query()->findOrFail($refund->order_id);
            $notRetryable = static fn (string $why): ApiException => new ApiException(409, 'REFUND_NOT_RETRYABLE', $why);
            if ($refund->status !== RefundStatus::APPROVED || $order->source !== OrderSource::ONLINE) {
                throw $notRetryable('Only an approved online refund can be retried.');
            }
            if (RefundTransaction::query()->where('refund_request_id', $refund->id)->exists()) {
                throw $notRetryable('The provider already recorded a result for this refund.');
            }
            $current = OrderStatus::from((string) DB::table('orders')->where('id', $order->id)->value('status'));
            if (! $current->acceptsCancellationRequest()) {
                throw new ApiException(409, 'REFUND_ORDER_NOT_REFUNDABLE', 'This order can no longer be refunded.');
            }
            $claimed = RefundRequest::query()->whereKey($refund->id)->where('status', RefundStatus::APPROVED->value)
                ->where(static fn ($q) => $q->whereNull('provider_call_started_at')->orWhere('provider_call_started_at', '<', now()->subSeconds(self::CLAIM_WINDOW_SECONDS)))
                ->update(['provider_attempts' => $refund->provider_attempts + 1, 'provider_call_started_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                throw $notRetryable('Another attempt is already in progress.');
            }
            $this->audit->record($actor, 'refund.retry.requested', $refund, [], ['orderNumber' => $order->order_number, 'attempt' => $refund->provider_attempts + 1]);

            return ['reference' => (string) $order->payment_reference, 'amount' => (int) $order->total_paid_minor, 'orderNumber' => $order->order_number];
        }, 3);
        $this->callProvider($refundId, $actor, $online, true);

        return RefundRequest::query()->findOrFail($refundId);
    }

    /** @param array{reference: string, amount: int, orderNumber: string} $order */
    private function callProvider(int $refundId, StaffUser $actor, array $order, bool $lookupFirst = false): void
    {
        try {
            $this->submit($refundId, $actor, $order, $lookupFirst);
        } finally {
            RefundRequest::query()->whereKey($refundId)->update(['provider_call_started_at' => null]);
        }
    }

    /** @param array{reference: string, amount: int, orderNumber: string} $order */
    private function submit(int $refundId, StaffUser $actor, array $order, bool $lookupFirst): void
    {
        if ($lookupFirst) {
            // A timeout or crash may mean Paystack already created the refund: look before any re-POST.
            try {
                $existing = $this->existingRefund($order['reference']);
            } catch (PaystackUnavailable) {
                throw $this->unavailable($refundId, $actor, $order);
            }
            if ($existing !== null) {
                $this->adopt($refundId, $actor, $order, $existing);

                return;
            }
        }
        try {
            $data = $this->paystack->refund([
                'transaction' => $order['reference'], 'amount' => $order['amount'], 'currency' => 'GHS',
                'merchant_note' => 'Refund request #'.$refundId.' for order '.$order['orderNumber'],
            ]);
        } catch (PaystackRefundRejected $e) {
            DB::transaction(function () use ($refundId, $actor, $order, $e): void {
                $refund = RefundRequest::query()->findOrFail($refundId);
                $this->append($refundId, RefundTransactionStatus::FAILED, null, $order['amount'], ['message' => $e->getMessage()]);
                $failed = RefundRequest::query()->whereKey($refundId)->where('status', RefundStatus::APPROVED->value)
                    ->update(['status' => RefundStatus::FAILED->value, 'updated_at' => now()]);
                if ($failed === 1) {
                    app(RefundNotificationPlanner::class)->failed(Order::query()->findOrFail($refund->order_id), $refund);
                }
                $this->audit->record($actor, 'refund.provider.rejected', RefundRequest::query()->findOrFail($refundId),
                    ['status' => RefundStatus::APPROVED->value], ['status' => RefundStatus::FAILED->value, 'message' => $e->getMessage()]);
            }, 3);
            throw $this->failure(502, 'REFUND_REJECTED_BY_PROVIDER', 'The payment provider rejected the refund.');
        } catch (PaystackUnavailable) {
            throw $this->unavailable($refundId, $actor, $order);
        }

        $providerRef = isset($data['id']) && (is_string($data['id']) || is_int($data['id'])) ? (string) $data['id'] : null;
        $safe = array_filter([
            'id' => $data['id'] ?? null, 'status' => $data['status'] ?? null,
            'currency' => $data['currency'] ?? null, 'amount' => $data['amount'] ?? null,
        ], static fn ($v): bool => is_string($v) || is_int($v));
        DB::transaction(function () use ($refundId, $actor, $order, $providerRef, $safe): void {
            $this->append($refundId, RefundTransactionStatus::PENDING, $providerRef, $order['amount'], $safe);
            // A fast webhook may already have completed the request: the conditional update then matches nothing.
            RefundRequest::query()->whereKey($refundId)->where('status', RefundStatus::APPROVED->value)
                ->update(['status' => RefundStatus::PROCESSING->value, 'updated_at' => now()]);
            $this->audit->record($actor, 'refund.provider.accepted', RefundRequest::query()->findOrFail($refundId),
                [], ['orderNumber' => $order['orderNumber'], 'providerRefundId' => $providerRef, 'amountMinor' => $order['amount']]);
        }, 3);
    }

    /**
     * Outcome unknown: money may have moved. Stay APPROVED (still blocks dispatch); a retry looks the refund up first.
     *
     * @param  array{reference: string, amount: int, orderNumber: string}  $order
     */
    private function unavailable(int $refundId, StaffUser $actor, array $order): HttpResponseException
    {
        DB::transaction(fn () => $this->audit->record($actor, 'refund.provider.unavailable', RefundRequest::query()->findOrFail($refundId),
            [], ['status' => RefundStatus::APPROVED->value, 'orderNumber' => $order['orderNumber']]), 3);

        return $this->failure(503, 'REFUND_PROVIDER_UNAVAILABLE', 'The payment provider did not confirm the refund request. The approval is recorded. Retry later: the retry first checks whether Paystack already created the refund.');
    }

    /**
     * A refund for this charge that is not FAILED, or null when none exists. A listed refund that does not identify its
     * charge cannot be matched safely, so it blocks the POST rather than being guessed at.
     *
     * @return array<string, mixed>|null
     */
    private function existingRefund(string $chargeReference): ?array
    {
        foreach ($this->paystack->listRefunds($chargeReference) as $item) {
            $transaction = $item['transaction'] ?? null;
            $ref = $item['transaction_reference'] ?? (is_array($transaction) ? ($transaction['reference'] ?? null) : null);
            if (! is_string($ref)) {
                throw new PaystackUnavailable;
            }
            if ($ref === $chargeReference && strtolower((string) ($item['status'] ?? '')) !== 'failed') {
                return $item;
            }
        }

        return null;
    }

    /**
     * Take over a refund Paystack already holds: no POST, one PENDING row, request to PROCESSING.
     *
     * @param  array{reference: string, amount: int, orderNumber: string}  $order
     * @param  array<string, mixed>  $existing
     */
    private function adopt(int $refundId, StaffUser $actor, array $order, array $existing): void
    {
        $providerRef = isset($existing['id']) && (is_string($existing['id']) || is_int($existing['id'])) ? (string) $existing['id'] : null;
        $safe = array_filter(['id' => $existing['id'] ?? null, 'status' => $existing['status'] ?? null, 'currency' => $existing['currency'] ?? null, 'amount' => $existing['amount'] ?? null],
            static fn ($v): bool => is_string($v) || is_int($v));
        DB::transaction(function () use ($refundId, $actor, $order, $providerRef, $safe): void {
            $this->append($refundId, RefundTransactionStatus::PENDING, $providerRef, $order['amount'], $safe);
            RefundRequest::query()->whereKey($refundId)->where('status', RefundStatus::APPROVED->value)
                ->update(['status' => RefundStatus::PROCESSING->value, 'updated_at' => now()]);
            $this->audit->record($actor, 'refund.provider.adopted', RefundRequest::query()->findOrFail($refundId),
                [], ['orderNumber' => $order['orderNumber'], 'providerRefundId' => $providerRef]);
        }, 3);
    }

    private function failure(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code, 'errors' => (object) []], $status));
    }

    /** @param array<string, mixed> $response */
    private function append(int $refundId, RefundTransactionStatus $status, ?string $providerRef, int $amount, array $response): void
    {
        RefundTransaction::query()->create([
            'refund_request_id' => $refundId, 'method' => RefundMethod::PAYSTACK, 'provider_reference' => $providerRef,
            'amount_minor' => $amount, 'status' => $status, 'response_redacted_json' => $response, 'created_at' => now(),
        ]);
    }
}
