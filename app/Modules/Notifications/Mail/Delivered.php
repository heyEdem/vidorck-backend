<?php

namespace App\Modules\Notifications\Mail;

final class Delivered extends NotificationMailable
{
    public static function template(): string
    {
        return 'delivered';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Your order '.$orderNumber.' has been delivered 💛';
    }
}
