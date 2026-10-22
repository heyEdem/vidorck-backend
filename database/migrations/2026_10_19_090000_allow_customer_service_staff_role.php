<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->rebuildStaffUsers("'ADMIN', 'SALES_ORDERS', 'CUSTOMER_SERVICE'");
    }

    public function down(): void
    {
        if (DB::table('staff_users')->where('role', 'CUSTOMER_SERVICE')->exists()) {
            throw new RuntimeException('Cannot remove CUSTOMER_SERVICE while staff accounts use that role.');
        }

        $this->rebuildStaffUsers("'ADMIN', 'SALES_ORDERS'");
    }

    private function rebuildStaffUsers(string $roles): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('The staff role constraint migration currently supports SQLite.');
        }

        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'staff_users' AND sql IS NOT NULL");
        $foreignKeysWereEnabled = (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1;
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::transaction(function () use ($indexes, $roles): void {
                DB::unprepared(<<<SQL
                CREATE TABLE staff_users_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) COLLATE NOCASE NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    role TEXT NOT NULL CHECK (role IN ($roles)),
                    is_active BOOLEAN NOT NULL DEFAULT 1,
                    must_change_password BOOLEAN NOT NULL DEFAULT 0,
                    session_version INTEGER NOT NULL DEFAULT 1,
                    last_login_at DATETIME NULL,
                    last_login_ip VARCHAR(45) NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
                SQL);
                DB::statement('INSERT INTO staff_users_new SELECT * FROM staff_users');
                DB::statement('DROP TABLE staff_users');
                DB::statement('ALTER TABLE staff_users_new RENAME TO staff_users');

                foreach ($indexes as $index) {
                    DB::unprepared($index->sql);
                }

                $violations = DB::select('PRAGMA foreign_key_check');
                if ($violations !== []) {
                    throw new RuntimeException('The staff role table rebuild produced foreign key violations.');
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = '.($foreignKeysWereEnabled ? 'ON' : 'OFF'));
        }
    }
};
