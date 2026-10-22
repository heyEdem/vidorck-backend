<?php

namespace App\Modules\Notifications\Enums;

enum NotificationAttemptEvent: string
{
    case STARTED = 'STARTED';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case EXHAUSTED = 'EXHAUSTED';
    case SKIPPED = 'SKIPPED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SENT, self::SKIPPED, self::EXHAUSTED], true);
    }
}
