<?php

namespace App\Modules\Notifications\Sms\SmsOnlineGh;

interface SmsOnlineGhClient
{
    /** One SMS to one destination. Never throws on SDK failures; they come back as codes. */
    public function send(string $host, string $apiKey, string $sender, string $to, string $message): SmsOnlineGhOutcome;
}
