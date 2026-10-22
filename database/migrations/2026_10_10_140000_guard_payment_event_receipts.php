<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS payment_events_receipt_facts_immutable
            BEFORE UPDATE ON payment_events
            WHEN OLD.id IS NOT NEW.id
              OR OLD.provider IS NOT NEW.provider
              OR OLD.provider_event_id IS NOT NEW.provider_event_id
              OR OLD.event_type IS NOT NEW.event_type
              OR OLD.reference IS NOT NEW.reference
              OR OLD.signature_valid IS NOT NEW.signature_valid
              OR OLD.payload_redacted_json IS NOT NEW.payload_redacted_json
              OR OLD.created_at IS NOT NEW.created_at
              OR OLD.processed_at IS NOT NULL
              OR NEW.processed_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, 'payment event receipt facts are immutable');
            END;

            CREATE TRIGGER IF NOT EXISTS payment_events_no_delete
            BEFORE DELETE ON payment_events
            BEGIN
                SELECT RAISE(ABORT, 'payment event receipts cannot be deleted');
            END;
            SQL);
    }

    public function down(): void
    {
        // Inbox receipt facts remain protected after rollback; there is no correction path.
    }
};
