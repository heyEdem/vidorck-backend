<?php

namespace App\Modules\Notifications\Support;

final readonly class SendResult
{
    public function __construct(public string $provider, public ?string $messageId = null) {}
}
