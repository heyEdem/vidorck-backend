<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE staff_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) COLLATE NOCASE NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('ADMIN', 'SALES_ORDERS')),
                is_active BOOLEAN NOT NULL DEFAULT 1,
                must_change_password BOOLEAN NOT NULL DEFAULT 0,
                session_version INTEGER NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                last_login_ip VARCHAR(45) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
    }
};
