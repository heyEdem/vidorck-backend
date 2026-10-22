<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->timestamp('scheduled_verified_at')->nullable();
            $table->index(['status', 'scheduled_verified_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_verified_at', 'created_at']);
            $table->dropColumn('scheduled_verified_at');
        });
    }
};
