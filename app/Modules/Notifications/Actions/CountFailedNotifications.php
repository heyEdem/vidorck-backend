<?php

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Enums\NotificationAttemptEvent;
use App\Modules\Notifications\Models\NotificationAttempt;

/** Notifications whose latest attempt is FAILED or EXHAUSTED (a later retry or success clears them). */
final class CountFailedNotifications
{
    public function execute(): int
    {
        return NotificationAttempt::query()
            ->whereIn('event_type', [NotificationAttemptEvent::FAILED->value, NotificationAttemptEvent::EXHAUSTED->value])
            ->whereNotExists(static function ($q): void {
                $q->selectRaw('1')->from('notification_attempts as later')
                    ->whereColumn('later.notification_log_id', 'notification_attempts.notification_log_id')
                    ->whereColumn('later.id', '>', 'notification_attempts.id');
            })->count();
    }
}
