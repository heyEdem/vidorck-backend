<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action')->index();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id', 128)->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['target_type', 'target_id']);
        });

        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::statement("CREATE TRIGGER audit_logs_no_{$operation} BEFORE {$operation} ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs are append-only'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
