<?php

namespace App\Modules\Notifications\Mail;

/** Admin changed the order's items (admin spec §7a). `amount` is the new items total; `total` stays what was paid. */
final class OrderUpdated extends NotificationMailable
{
    public static function template(): string
    {
        return 'order-updated';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Order '.$orderNumber.' has been updated';
    }
}
