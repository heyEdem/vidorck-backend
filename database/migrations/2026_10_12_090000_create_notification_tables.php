<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.1. Both tables are append-only (DB triggers; SQLite TRUNCATE compiles to DELETE).
 * notification_logs is the immutable intent; notification_attempts holds immutable delivery facts.
 * No raw recipient, body or provider response is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('channel', 5);
            $table->string('template', 100);
            $table->string('recipient_masked');
            $table->char('recipient_hash', 64)->index();
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('notification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_log_id')->constrained('notification_logs')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('event_type', 10);
            $table->string('provider', 50)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_summary', 255)->nullable();
            $table->timestamp('created_at');
            $table->unique(['notification_log_id', 'attempt_number', 'event_type'], 'notification_attempts_claim_unique');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER notification_logs_valid_insert BEFORE INSERT ON notification_logs
            WHEN NEW.channel NOT IN ('EMAIL','SMS')
            BEGIN SELECT RAISE(ABORT, 'invalid notification channel'); END;
            CREATE TRIGGER notification_attempts_valid_insert BEFORE INSERT ON notification_attempts
            WHEN NEW.event_type NOT IN ('STARTED','SENT','FAILED','EXHAUSTED','SKIPPED')
            BEGIN SELECT RAISE(ABORT, 'invalid notification attempt event'); END;
            SQL);

        foreach (['notification_logs', 'notification_attempts'] as $name) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}_no_update BEFORE UPDATE ON {$name}
                BEGIN SELECT RAISE(ABORT, '{$name} are append-only'); END;
                CREATE TRIGGER {$name}_no_delete BEFORE DELETE ON {$name}
                BEGIN SELECT RAISE(ABORT, '{$name} are append-only'); END;
                SQL);
        }
    }

    public function down(): void
    {
        // Same precedent as audit_logs: rollback is dev/test-only (tables and triggers go together).
        Schema::dropIfExists('notification_attempts');
        Schema::dropIfExists('notification_logs');
    }
};
