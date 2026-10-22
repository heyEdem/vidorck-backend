<?php

namespace App\Modules\Notifications\Mail;

final class ReadyForDispatch extends NotificationMailable
{
    public static function template(): string
    {
        return 'ready-for-dispatch';
    }

    protected function subjectLine(string $orderNumber): string
    {
        return 'Your order '.$orderNumber.' is packed and ready 📦';
    }
}
