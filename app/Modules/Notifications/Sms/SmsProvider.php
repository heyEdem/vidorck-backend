<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;

interface SmsProvider
{
    /**
     * @throws PermanentNotificationException invalid recipient or credentials
     * @throws TransientNotificationException rate limit, 5xx, timeout or unreadable reply
     */
    public function send(string $toE164, string $message): SmsResult;
}
