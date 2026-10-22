<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->unique('parent_attempt_id', 'checkout_attempts_parent_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_attempts', function (Blueprint $table): void {
            $table->dropUnique('checkout_attempts_parent_attempt_unique');
        });
    }
};
