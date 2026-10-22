<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Notifications\Planners\OrderNotificationPlanner;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundChannel;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Events\CancellationRequested;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Actions\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** product-spec §6.5, website-spec §16.1. Never writes orders.status. */
final class RequestCancellation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(string $orderNumber, string $phone, string $reason): RefundRequest
    {
        try {
            $e164 = PhoneNumber::fromGhanaInput($phone)->e164;
        } catch (\InvalidArgumentException) {
            $this->notFound();
        }

        try {
            return DB::transaction(function () use ($orderNumber, $e164, $reason): RefundRequest {
                $order = Order::query()->with('customer')->where('order_number', strtoupper(trim($orderNumber)))->first();
                // Same body for unknown order and wrong phone: no enumeration.
                if ($order === null || $order->customer->phone_e164 !== $e164) {
                    $this->notFound();
                }
                $this->assertRequestable($order);

                $request = RefundRequest::query()->create([
                    'order_id' => $order->id, 'channel' => RefundChannel::WEB, 'reason' => $reason,
                    'status' => RefundStatus::REQUESTED, 'requester_name' => $order->customer->full_name,
                    'requester_phone_e164' => $e164,
                ]);
                $this->audit->record(null, 'refund.requested', $request, [], [
                    'orderNumber' => $order->order_number, 'channel' => RefundChannel::WEB->value, 'status' => RefundStatus::REQUESTED->value,
                ]);
                app(OrderNotificationPlanner::class)->cancellationRequested($order, $request);
                CancellationRequested::dispatch($request);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            $this->duplicate();
        }
    }

    private function assertRequestable(Order $order): void
    {
        $status = $order->getAttribute('status');
        if (! $status instanceof OrderStatus || ! $status->acceptsCancellationRequest()) {
            throw new ApiException(409, 'CANCELLATION_NOT_ALLOWED', 'This order can no longer be cancelled.');
        }
        if (RefundRequest::query()->where('order_id', $order->id)->whereIn('status', RefundStatus::open())->exists()) {
            $this->duplicate();
        }
    }

    private function duplicate(): never
    {
        throw new ApiException(409, 'CANCELLATION_ALREADY_REQUESTED', 'A cancellation request is already open for this order.');
    }

    private function notFound(): never
    {
        throw new ApiException(404, 'ORDER_NOT_FOUND', 'We could not find an order matching those details.');
    }
}
