<?php

use App\Jobs\OptimizeSqlite;
use App\Jobs\PruneExpiredSessions;
use App\Modules\Catalog\Jobs\PruneRetiredMedia;
use App\Modules\Checkout\Jobs\ExpireStaleCheckoutAttempts;
use App\Modules\Checkout\Jobs\PruneFailedCheckoutAttempts;
use App\Modules\Checkout\Jobs\ReverifyPendingCheckoutAttempts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('backups:check-litestream', function () {
    $this->info('Litestream freshness check not configured (Phase 7.4)');
})->purpose('Check Litestream replication freshness (placeholder until Phase 7.4)');

Schedule::call(new ExpireStaleCheckoutAttempts)->name('checkout:expire-stale-attempts')->hourly()->withoutOverlapping();
Schedule::call(new ReverifyPendingCheckoutAttempts)->name('checkout:reverify-pending-attempts')->everyFifteenMinutes()->withoutOverlapping();
Schedule::call(new PruneRetiredMedia)->name('catalog:prune-retired-media')->daily()->withoutOverlapping();
Schedule::call(new PruneExpiredSessions)->name('sessions:prune-expired')->daily()->withoutOverlapping();
Schedule::call(new PruneFailedCheckoutAttempts)->name('checkout:prune-failed-attempts')->daily()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=24')->daily()->withoutOverlapping();
Schedule::call(new OptimizeSqlite)->name('sqlite:optimize')->daily()->withoutOverlapping();
Schedule::command('backups:check-litestream')->hourly()->withoutOverlapping();
