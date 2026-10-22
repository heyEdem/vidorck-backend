<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 16)->nullable()->unique();
            $table->string('name', 100);
            $table->string('topic', 30);
            $table->text('message');
            $table->string('contact_method', 20);
            $table->string('phone_e164', 20)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('order_number_entered')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('NEW');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();
            $table->index(['status', 'assigned_staff_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('complaint_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('body')->nullable();
            $table->timestamp('created_at');
            $table->index(['complaint_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER complaints_valid_values_insert BEFORE INSERT ON complaints
            WHEN NEW.topic NOT IN ('ORDER_PROBLEM','PRODUCT_QUESTION','OTHER') OR NEW.contact_method NOT IN ('CALL','WHATSAPP','EMAIL') OR NEW.status NOT IN ('NEW','IN_PROGRESS','RESOLVED')
            BEGIN SELECT RAISE(ABORT, 'invalid complaint values'); END;
            CREATE TRIGGER complaints_valid_values_update BEFORE UPDATE OF topic, contact_method, status ON complaints
            WHEN NEW.topic NOT IN ('ORDER_PROBLEM','PRODUCT_QUESTION','OTHER') OR NEW.contact_method NOT IN ('CALL','WHATSAPP','EMAIL') OR NEW.status NOT IN ('NEW','IN_PROGRESS','RESOLVED')
            BEGIN SELECT RAISE(ABORT, 'invalid complaint values'); END;
            CREATE TRIGGER complaint_events_valid_values_insert BEFORE INSERT ON complaint_events
            WHEN NEW.type NOT IN ('SUBMITTED','CLAIMED','NOTE','STATUS_CHANGED') OR (NEW.from_status IS NOT NULL AND NEW.from_status NOT IN ('NEW','IN_PROGRESS','RESOLVED')) OR (NEW.to_status IS NOT NULL AND NEW.to_status NOT IN ('NEW','IN_PROGRESS','RESOLVED'))
            BEGIN SELECT RAISE(ABORT, 'invalid complaint event values'); END;
            CREATE TRIGGER complaint_events_no_update BEFORE UPDATE ON complaint_events
            BEGIN SELECT RAISE(ABORT, 'complaint events are append-only'); END;
            CREATE TRIGGER complaint_events_no_delete BEFORE DELETE ON complaint_events
            BEGIN SELECT RAISE(ABORT, 'complaint events are append-only'); END;
            SQL);
    }

    public function down(): void
    {
        // Complaint history is immutable; rolling back this migration must never remove it.
    }
};
