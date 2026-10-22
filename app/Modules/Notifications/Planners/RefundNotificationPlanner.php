<?php

namespace App\Modules\Notifications\Planners;

use App\Modules\Notifications\Sms\SmsMessage;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RefundRequest;

/**
 * Customer refund emails and SMS. Payloads never carry the decision reason or provider references.
 * Keys use the refund request id, so retries and replays never send twice.
 */
final class RefundNotificationPlanner extends NotificationPlanner
{
    private const SMS_TEMPLATES = [
        'approved' => SmsMessage::TEMPLATE_CUSTOMER_REFUND_APPROVED,
        'rejected' => SmsMessage::TEMPLATE_CUSTOMER_REFUND_REJECTED,
        'failed' => SmsMessage::TEMPLATE_CUSTOMER_REFUND_FAILED,
        'completed' => SmsMessage::TEMPLATE_CUSTOMER_REFUND_COMPLETED,
    ];

    public function decided(Order $order, RefundRequest $refund, bool $approved): void
    {
        $this->send($order, $refund, $approved ? 'approved' : 'rejected');
    }

    public function failed(Order $order, RefundRequest $refund): void
    {
        $this->send($order, $refund, 'failed');
    }

    public function completed(Order $order, RefundRequest $refund): void
    {
        $this->send($order, $refund, 'completed');
    }

    private function send(Order $order, RefundRequest $refund, string $outcome): void
    {
        $this->guard('refund-'.$outcome, function () use ($order, $refund, $outcome): void {
            $occurrence = 'refund-'.$outcome.':'.$refund->id;
            $data = $this->orderData($order);
            $data['amountMinor'] = (int) $order->total_paid_minor;
            $this->email($occurrence, $this->customerEmail($order), 'refund-'.$outcome, $data, 'refund_request', $refund->id);
            $phone = $this->customerPhone($order);
            if ($phone !== null) {
                // The SMS carries only the order number and (approved/completed) the same amount the email shows.
                $this->sms($occurrence, $phone, self::SMS_TEMPLATES[$outcome], ['orderNumber' => $order->order_number, 'amountMinor' => $data['amountMinor']], 'refund_request', $refund->id);
            }
        });
    }
}
