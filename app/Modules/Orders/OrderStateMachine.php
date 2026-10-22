<?php

namespace App\Modules\Orders;

use App\Modules\Notifications\Planners\OrderNotificationPlanner;
use App\Modules\Orders\Actions\RecordOrderStatusEvent;
use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The only code path allowed to write orders.status after creation
 * (website-spec §14.2, product-spec §5.3).
 */
final class OrderStateMachine
{
    /** @var list<OrderStatus> */
    private const PATH = [
        OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH,
        OrderStatus::DISPATCHED, OrderStatus::DELIVERED,
    ];

    /** Refund/cancellation is prohibited once dispatched (website-spec §14.2, §16.1). */
    private const REFUNDABLE_FROM = [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::READY_FOR_DISPATCH];

    /** Normal staff transition; one-step-back corrections are Admin-only and need a reason. */
    public function transition(Order $order, OrderStatus $to, ?StaffUser $actor, ?string $reason = null): Order
    {
        return $this->apply($order, $to, $actor, $reason, false);
    }

    /** Refund-flow entry point: the only way to reach CANCELLED or REFUNDED. */
    public function transitionForRefund(Order $order, OrderStatus $to, ?StaffUser $actor = null, ?string $reason = null): Order
    {
        return $this->apply($order, $to, $actor, $reason, true);
    }

    /**
     * Statuses a transition call may currently reach.
     *
     * @return list<OrderStatus>
     */
    public function allowedNext(OrderStatus $from, bool $includeCorrection = false): array
    {
        $index = array_search($from, self::PATH, true);
        if ($index === false) {
            return [];
        }
        $allowed = isset(self::PATH[$index + 1]) ? [self::PATH[$index + 1]] : [];
        if ($includeCorrection && $index > 0) {
            $allowed[] = self::PATH[$index - 1];
        }

        return $allowed;
    }

    private function apply(Order $order, OrderStatus $to, ?StaffUser $actor, ?string $reason, bool $refundFlow): Order
    {
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        return DB::transaction(function () use ($order, $to, $actor, $reason, $refundFlow): Order {
            $current = $this->currentStatus($order);
            $staff = $actor === null ? null : $this->activeStaff($actor);
            $isAdmin = $staff?->role === StaffRole::ADMIN;
            $isCorrection = false;

            if ($refundFlow) {
                $valid = in_array($to, [OrderStatus::CANCELLED, OrderStatus::REFUNDED], true)
                    && in_array($current, self::REFUNDABLE_FROM, true);
                $allowed = in_array($current, self::REFUNDABLE_FROM, true) ? [OrderStatus::CANCELLED, OrderStatus::REFUNDED] : [];
            } else {
                $allowed = $this->allowedNext($current, true);
                $valid = in_array($to, $allowed, true);
                $isCorrection = $valid && $to !== ($this->allowedNext($current)[0] ?? null);
                if (! $isAdmin) {
                    $allowed = $this->allowedNext($current);
                }
            }
            if (! $valid) {
                throw $this->invalid($current, $to, $allowed);
            }
            // website-spec §16.2: once a refund is approved or processing, goods must not leave. Same IMMEDIATE
            // transaction as the status update, so approve and dispatch serialize. Open REQUESTED/UNDER_REVIEW
            // requests do not block (§16.1: a request does not itself cancel or refund the order).
            if (! $refundFlow && $to === OrderStatus::DISPATCHED && RefundRequest::query()->where('order_id', $order->getKey())
                ->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::inFlight()))->exists()) {
                throw new ApiException(409, 'REFUND_IN_PROGRESS', 'This order has an approved refund in progress and cannot be dispatched.');
            }
            if ($isCorrection) {
                if (! $isAdmin) {
                    throw new AuthorizationException;
                }
                if ($reason === null) {
                    throw new ApiException(422, 'VALIDATION_ERROR', 'A reason is required for a status correction.', ['reason' => ['Provide a reason.']]);
                }
            }

            $changed = Order::withoutEvents(static fn (): int => DB::table('orders')
                ->where('id', $order->getKey())->where('status', $current->value)
                ->update(['status' => $to->value, 'updated_at' => now()]));
            if ($changed !== 1) {
                throw $this->invalid($this->currentStatus($order), $to, $this->allowedNext($this->currentStatus($order), $isAdmin));
            }

            $actorType = $staff === null ? OrderActorType::SYSTEM : OrderActorType::STAFF;
            app(RecordOrderStatusEvent::class)->record($order, $current, $to, $actorType, $staff?->getKey(), $reason);
            $order->forceFill(['status' => $to])->syncOriginalAttribute('status');
            app(AuditLogger::class)->record($staff, $isCorrection ? 'order.status.corrected' : 'order.status.changed', $order,
                ['status' => $current->value],
                ['status' => $to->value, 'orderNumber' => $order->order_number, 'reason' => $reason]);
            if (! $refundFlow && ! $isCorrection) {
                app(OrderNotificationPlanner::class)->statusReached($order, $to);
            }
            Event::dispatch(new OrderStatusChanged($order, $current, $to, $actorType, $staff?->getKey(), $reason));

            return $order;
        }, 3);
    }

    private function currentStatus(Order $order): OrderStatus
    {
        $raw = DB::table('orders')->where('id', $order->getKey())->value('status');
        if (! is_string($raw)) {
            throw new \InvalidArgumentException('Order does not exist.');
        }

        return OrderStatus::from($raw);
    }

    private function activeStaff(StaffUser $actor): StaffUser
    {
        $current = StaffUser::query()->find($actor->getKey());
        if ($current === null || ! $current->is_active) {
            throw new AuthorizationException;
        }

        return $current;
    }

    /** @param list<OrderStatus> $allowed */
    private function invalid(OrderStatus $from, OrderStatus $to, array $allowed): ApiException
    {
        return new ApiException(409, 'INVALID_ORDER_TRANSITION',
            "An order cannot move from {$from->value} to {$to->value}.",
            ['allowedStatuses' => array_map(static fn (OrderStatus $s): string => $s->value, $allowed)]);
    }
}
