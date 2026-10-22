<?php

namespace App\Modules\Notifications\Mail;

final class ComplaintReceived extends NotificationMailable
{
    public static function template(): string
    {
        return 'complaint-received';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'We received your message';
    }
}
