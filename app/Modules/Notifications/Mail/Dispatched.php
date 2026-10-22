<?php

namespace App\Modules\Notifications\Mail;

final class Dispatched extends NotificationMailable
{
    public static function template(): string
    {
        return 'dispatched';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Your order '.$orderNumber.' is on its way 🚚';
    }
}
