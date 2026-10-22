<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * product-spec §5.3 / website-spec §16. refund_requests is the mutable workflow row;
 * refund_transactions and all notes are append-only facts (DB triggers; SQLite TRUNCATE compiles to DELETE).
 */
return new class extends Migration
{
    private const OPEN = "'REQUESTED','UNDER_REVIEW','APPROVED','PROCESSING'";

    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('channel', 10);
            $table->text('reason');
            $table->string('status', 20)->default('REQUESTED')->index();
            $table->string('requester_name')->nullable();
            $table->string('requester_phone_e164', 20)->nullable();
            $table->foreignId('decided_by_staff_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_request_id')->constrained()->restrictOnDelete();
            $table->string('method', 10);
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20);
            $table->json('response_redacted_json')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('refund_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
        });

        Schema::create('order_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
        });

        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
        });

        $open = self::OPEN;
        DB::unprepared(<<<SQL
            CREATE UNIQUE INDEX refund_requests_one_open_per_order ON refund_requests (order_id) WHERE status IN ({$open});
            CREATE TRIGGER refund_requests_valid_insert BEFORE INSERT ON refund_requests
            WHEN NEW.status NOT IN ('REQUESTED','UNDER_REVIEW','APPROVED','REJECTED','PROCESSING','COMPLETED','FAILED')
                OR NEW.channel NOT IN ('WEB','WHATSAPP','STAFF')
            BEGIN SELECT RAISE(ABORT, 'invalid refund request status or channel'); END;
            CREATE TRIGGER refund_requests_valid_update BEFORE UPDATE ON refund_requests
            WHEN NEW.status NOT IN ('REQUESTED','UNDER_REVIEW','APPROVED','REJECTED','PROCESSING','COMPLETED','FAILED')
            BEGIN SELECT RAISE(ABORT, 'invalid refund request status'); END;
            CREATE TRIGGER refund_requests_immutable_origin BEFORE UPDATE OF order_id, channel, reason, requester_name, requester_phone_e164, created_at ON refund_requests
            BEGIN SELECT RAISE(ABORT, 'refund request origin is immutable'); END;
            CREATE TRIGGER refund_requests_no_delete BEFORE DELETE ON refund_requests
            BEGIN SELECT RAISE(ABORT, 'refund requests are never deleted'); END;
            SQL);

        foreach (['refund_transactions', 'refund_notes', 'order_notes', 'customer_notes'] as $name) {
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
        // Same precedent as audit_logs: rollback is dev/test-only (the tables and their triggers go together).
        foreach (['customer_notes', 'order_notes', 'refund_notes', 'refund_transactions', 'refund_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
