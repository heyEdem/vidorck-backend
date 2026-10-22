<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_content_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->json('before_items_json');
            $table->json('after_items_json');
            $table->unsignedBigInteger('before_subtotal_minor');
            $table->unsignedBigInteger('after_subtotal_minor');
            $table->bigInteger('difference_minor');
            $table->string('settlement_method', 10);
            $table->string('settlement_note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'id']);
        });

        // SQLite has no TRUNCATE; its DELETE trigger also blocks DELETE FROM without a WHERE clause.
        // INSERT OR REPLACE can otherwise silently delete the conflicting row when recursive triggers are off.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER order_content_revisions_valid_insert BEFORE INSERT ON order_content_revisions
            WHEN NEW.settlement_method NOT IN ('NONE','CASH','MOMO','BANK','OTHER')
                OR NEW.before_subtotal_minor < 0
                OR NEW.after_subtotal_minor < 0
                OR NEW.difference_minor != NEW.after_subtotal_minor - NEW.before_subtotal_minor
                OR (NEW.difference_minor != 0 AND (NEW.settlement_method = 'NONE' OR NEW.settlement_note IS NULL OR trim(NEW.settlement_note) = ''))
                OR trim(NEW.reason) = ''
            BEGIN SELECT RAISE(ABORT, 'invalid order content revision'); END;
            CREATE TRIGGER order_content_revisions_no_replace BEFORE INSERT ON order_content_revisions
            WHEN NEW.id IS NOT NULL AND EXISTS (SELECT 1 FROM order_content_revisions WHERE id = NEW.id)
            BEGIN SELECT RAISE(ABORT, 'order content revisions are append-only'); END;
            CREATE TRIGGER order_content_revisions_no_update BEFORE UPDATE ON order_content_revisions
            BEGIN SELECT RAISE(ABORT, 'order content revisions are append-only'); END;
            CREATE TRIGGER order_content_revisions_no_delete BEFORE DELETE ON order_content_revisions
            BEGIN SELECT RAISE(ABORT, 'order content revisions are append-only'); END;
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Order content revision history is immutable and cannot be rolled back.');
    }
};
