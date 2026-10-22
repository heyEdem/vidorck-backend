<?php

namespace App\Modules\Notifications\Mail;

final class RefundRejected extends NotificationMailable
{
    public static function template(): string
    {
        return 'refund-rejected';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Update on your refund request for order '.$orderNumber;
    }
}
