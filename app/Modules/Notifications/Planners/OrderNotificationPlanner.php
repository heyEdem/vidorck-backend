<?php

namespace App\Modules\Notifications\Planners;

use App\Modules\Notifications\Sms\SmsMessage;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderContentRevision;
use App\Modules\Orders\Models\RefundRequest;

/** Order paid, milestone and cancellation-request notifications. Called inside the business transaction. */
final class OrderNotificationPlanner extends NotificationPlanner
{
    /** Milestone status => [email template, customer SMS template]. */
    private const MILESTONES = [
        'READY_FOR_DISPATCH' => ['ready-for-dispatch', SmsMessage::TEMPLATE_CUSTOMER_READY_FOR_DISPATCH],
        'DISPATCHED' => ['dispatched', SmsMessage::TEMPLATE_CUSTOMER_DISPATCHED],
        'DELIVERED' => ['delivered', SmsMessage::TEMPLATE_CUSTOMER_DELIVERED],
    ];

    public function orderPaid(Order $order): void
    {
        $this->guard('order-paid', function () use ($order): void {
            $data = $this->orderData($order);
            $occurrence = 'order-paid:'.$order->order_number;
            $this->email($occurrence.':customer', $this->customerEmail($order), 'order-confirmation', $data, 'order', $order->id);
            $phone = $this->customerPhone($order);
            if ($phone !== null) {
                $this->sms($occurrence.':customer', $phone, SmsMessage::TEMPLATE_CUSTOMER_ORDER_PAID,
                    ['orderNumber' => $order->order_number, 'amountMinor' => (int) $order->total_paid_minor, 'firstName' => $this->customerFirstName($order)], 'order', $order->id);
            }
        });
    }

    /** Forward milestone transitions only; the caller never passes corrections. */
    public function statusReached(Order $order, OrderStatus $to): void
    {
        [$template, $smsTemplate] = self::MILESTONES[$to->value] ?? [null, null];
        if ($template === null) {
            return;
        }
        $this->guard('order-status', function () use ($order, $to, $template, $smsTemplate): void {
            $occurrence = 'order-status:'.$order->order_number.':'.$to->value;
            $this->email($occurrence, $this->customerEmail($order), $template, $this->orderData($order), 'order', $order->id);
            $phone = $this->customerPhone($order);
            if ($phone !== null) {
                $this->sms($occurrence, $phone, $smsTemplate, ['orderNumber' => $order->order_number, 'firstName' => $this->customerFirstName($order)], 'order', $order->id);
            }
        });
    }

    public function cancellationRequested(Order $order, RefundRequest $request): void
    {
        $this->guard('cancellation', function () use ($order, $request): void {
            $occurrence = 'cancellation:'.$request->id;
            $data = $this->orderData($order);
            $this->email($occurrence.':customer', $this->customerEmail($order), 'cancellation-received', $data, 'refund_request', $request->id);
            $phone = $this->customerPhone($order);
            if ($phone !== null) {
                $this->sms($occurrence.':customer', $phone, SmsMessage::TEMPLATE_CUSTOMER_CANCELLATION_RECEIVED, ['orderNumber' => $order->order_number], 'refund_request', $request->id);
            }
        });
    }

    /** Admin changed the items (admin spec §7a): customer email + SMS, once per revision. */
    public function contentsUpdated(Order $order, OrderContentRevision $revision): void
    {
        $this->guard('order-updated', function () use ($order, $revision): void {
            $occurrence = 'order-updated:'.$order->order_number.':'.$revision->id;
            $data = $this->orderData($order) + ['amountMinor' => $revision->after_subtotal_minor];
            $this->email($occurrence.':customer', $this->customerEmail($order), 'order-updated', $data, 'order', $order->id);
            $phone = $this->customerPhone($order);
            if ($phone !== null) {
                $this->sms($occurrence.':customer', $phone, SmsMessage::TEMPLATE_CUSTOMER_ORDER_UPDATED,
                    ['orderNumber' => $order->order_number, 'amountMinor' => $revision->after_subtotal_minor, 'items' => $data['items']], 'order', $order->id);
            }
        });
    }
}
