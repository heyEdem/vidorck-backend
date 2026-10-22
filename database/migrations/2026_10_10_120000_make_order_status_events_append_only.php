<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS order_status_events_no_update BEFORE UPDATE ON order_status_events
            BEGIN SELECT RAISE(ABORT, 'order status events are append-only'); END;
            CREATE TRIGGER IF NOT EXISTS order_status_events_no_delete BEFORE DELETE ON order_status_events
            BEGIN SELECT RAISE(ABORT, 'order status events are append-only'); END;
            SQL);
    }

    public function down(): void
    {
        // The table is an immutable audit history; rolling back this additive migration
        // must never remove its database enforcement.
    }
};
