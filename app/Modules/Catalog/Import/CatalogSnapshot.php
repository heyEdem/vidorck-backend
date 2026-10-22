<?php

namespace App\Modules\Catalog\Import;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CatalogSnapshot
{
    /** Writes a consistent copy of the SQLite database and returns its path. */
    public function create(): string
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('A snapshot cannot be taken inside a transaction.');
        }
        $directory = storage_path('backups');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the backup directory.');
        }
        $path = $directory.'/catalog-'.now()->format('Ymd-His-u').'.sqlite';
        try {
            DB::connection()->getPdo()->prepare('VACUUM INTO ?')->execute([$path]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Snapshot failed: '.$exception->getMessage(), 0, $exception);
        }
        if (! is_file($path)) {
            throw new RuntimeException('Snapshot file was not created.');
        }
        @chmod($path, 0600);

        return $path;
    }
}
