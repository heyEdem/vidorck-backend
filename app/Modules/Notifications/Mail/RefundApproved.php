<?php

namespace App\Modules\Notifications\Mail;

final class RefundApproved extends NotificationMailable
{
    public static function template(): string
    {
        return 'refund-approved';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Refund approved for order '.$orderNumber;
    }
}
