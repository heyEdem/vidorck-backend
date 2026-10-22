<?php

namespace App\Modules\Notifications\Sms;

final readonly class SmsResult
{
    public function __construct(public string $provider, public ?string $messageId = null) {}
}
