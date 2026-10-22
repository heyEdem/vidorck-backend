<?php

namespace App\Modules\Notifications\Mail;

final class CancellationReceived extends NotificationMailable
{
    public static function template(): string
    {
        return 'cancellation-received';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'We received your cancellation request for order '.$orderNumber;
    }
}
