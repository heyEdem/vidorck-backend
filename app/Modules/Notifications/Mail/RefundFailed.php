<?php

namespace App\Modules\Notifications\Mail;

final class RefundFailed extends NotificationMailable
{
    public static function template(): string
    {
        return 'refund-failed';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Problem with your refund for order '.$orderNumber;
    }
}
