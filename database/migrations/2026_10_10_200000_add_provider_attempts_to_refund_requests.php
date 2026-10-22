<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only one process may POST a provider refund at a time: a claim stamps provider_call_started_at (cleared when the
 * call returns; a claim older than the HTTP timeout window is treated as a crashed attempt). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table): void {
            $table->unsignedInteger('provider_attempts')->default(0);
            $table->timestamp('provider_call_started_at')->nullable();
        });
    }

    public function down(): void
    {
        // Additive; the column is harmless on rollback.
    }
};
