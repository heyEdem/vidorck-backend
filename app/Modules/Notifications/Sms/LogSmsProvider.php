<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Support\RecipientIdentity;
use Illuminate\Support\Facades\Log;

/** Local/staging driver: no network, and the log carries only the masked recipient and the message length. */
final class LogSmsProvider implements SmsProvider
{
    public function send(string $toE164, string $message): SmsResult
    {
        Log::info('SMS (log driver) not sent.', [
            'to' => RecipientIdentity::for(NotificationChannel::SMS, $toE164)->masked,
            'length' => mb_strlen($message),
        ]);

        return new SmsResult('log');
    }
}
