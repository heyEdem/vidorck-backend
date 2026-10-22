<?php

namespace App\Modules\Notifications\Sms\SmsOnlineGh;

/** Codes only, never provider text. http 0 = no usable response; handshake null = none received. */
final readonly class SmsOnlineGhOutcome
{
    public function __construct(
        public int $http,
        public ?int $handshake = null,
        public ?int $statusId = null,
        public ?string $messageId = null,
    ) {}
}
