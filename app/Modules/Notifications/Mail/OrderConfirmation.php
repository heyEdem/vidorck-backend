<?php

namespace App\Modules\Notifications\Mail;

final class OrderConfirmation extends NotificationMailable
{
    public static function template(): string
    {
        return 'order-confirmation';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Payment received! Order '.$orderNumber.' is confirmed 🎉';
    }
}
