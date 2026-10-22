<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\SendResult;

final class SmsNotificationSender implements NotificationSender
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::SMS;
    }

    public function send(string $recipient, NotificationPayload $payload): SendResult
    {
        $result = $this->provider->send($recipient, SmsMessage::forPayload($payload));

        return new SendResult($result->provider, $result->messageId);
    }
}
