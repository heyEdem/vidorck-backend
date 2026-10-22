<?php

namespace App\Modules\Notifications\Repositories;

use App\Modules\Notifications\Enums\NotificationAttemptEvent;
use App\Modules\Notifications\Models\NotificationAttempt;
use App\Modules\Notifications\Support\ErrorSanitizer;
use Illuminate\Database\UniqueConstraintViolationException;

/** Insert-only access to notification_attempts. Current status is the latest row. */
final class NotificationAttemptRepository
{
    public function latest(int $logId): ?NotificationAttempt
    {
        return NotificationAttempt::query()->where('notification_log_id', $logId)->orderByDesc('id')->first();
    }

    /**
     * Appends a fact. Returns null when the unique (log, number, event) row already exists,
     * which makes STARTED the claim and keeps EXHAUSTED/SENT idempotent.
     */
    public function append(
        int $logId,
        int $number,
        NotificationAttemptEvent $event,
        ?string $provider = null,
        ?string $messageId = null,
        ?string $errorCode = null,
        ?string $errorSummary = null,
    ): ?NotificationAttempt {
        try {
            return NotificationAttempt::query()->create([
                'notification_log_id' => $logId,
                'attempt_number' => $number,
                'event_type' => $event,
                'provider' => $provider,
                'provider_message_id' => $messageId,
                'error_code' => $errorCode === null ? null : mb_substr($errorCode, 0, 100),
                'error_summary' => $errorSummary === null ? null : ErrorSanitizer::clean($errorSummary),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
