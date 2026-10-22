<?php

namespace App\Modules\Notifications\Mail;

final class RefundCompleted extends NotificationMailable
{
    public static function template(): string
    {
        return 'refund-completed';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Refund completed for order '.$orderNumber;
    }
}
