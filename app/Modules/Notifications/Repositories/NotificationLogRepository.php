<?php

namespace App\Modules\Notifications\Repositories;

use App\Modules\Notifications\Models\NotificationLog;

/** Insert-only access to notification_logs. */
final class NotificationLogRepository
{
    /** @param array<string, mixed> $attributes */
    public function insert(array $attributes): NotificationLog
    {
        return NotificationLog::query()->create($attributes + ['created_at' => now()]);
    }

    public function find(int $id): ?NotificationLog
    {
        return NotificationLog::query()->find($id);
    }
}
