<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('applies SQLite pragmas on fresh connections and reconnects', function () {
    $path = tempnam(sys_get_temp_dir(), 'vhi-sqlite-');
    config(['database.connections.pragma_test' => array_replace(
        config('database.connections.sqlite'), ['database' => $path, 'url' => null],
    )]);

    try {
        foreach (range(1, 2) as $attempt) {
            $connection = DB::connection('pragma_test');
            foreach (['journal_mode' => 'wal', 'foreign_keys' => 1, 'busy_timeout' => 5000,
                'synchronous' => 1, 'temp_store' => 2] as $pragma => $value) {
                expect(array_values((array) $connection->selectOne("PRAGMA {$pragma}"))[0])->toBe($value);
            }
            DB::purge('pragma_test');
        }
    } finally {
        DB::purge('pragma_test');
        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

it('migrates the database session queue cache and Sanctum tables', function () {
    $this->artisan('migrate')->assertSuccessful();
    foreach (['sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'personal_access_tokens'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
