<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/** Only applies to the database session driver; other drivers expire their own storage. */
final class PruneExpiredSessions
{
    public function __invoke(): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
        $connection = DB::connection(config('session.connection'));
        $table = (string) config('session.table', 'sessions');
        do {
            $ids = $connection->table($table)->where('last_activity', '<', $cutoff)->limit(500)->pluck('id');
            $connection->table($table)->whereIn('id', $ids)->where('last_activity', '<', $cutoff)->delete();
        } while ($ids->count() === 500);
    }
}
