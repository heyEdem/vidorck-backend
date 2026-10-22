<?php

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\SendResult;

/**
 * Provider adapter, implemented in 5.2 (SMS) and 5.3 (email) and tagged `notification.senders`.
 * Throw PermanentNotificationException for unrecoverable rejections; anything else is retried.
 */
interface NotificationSender
{
    public function channel(): NotificationChannel;

    public function send(string $recipient, NotificationPayload $payload): SendResult;
}
