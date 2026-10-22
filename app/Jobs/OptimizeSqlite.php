<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

final class OptimizeSqlite
{
    public function __invoke(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA optimize');
        }
    }
}
