<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;

it('prohibits destructive database commands when the application is production', function () {
    $previousEnvironment = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        (new AppServiceProvider($this->app))->boot();

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'] as $command) {
            $this->artisan($command, ['--force' => true])->assertExitCode(1);
        }
    } finally {
        DB::prohibitDestructiveCommands(false);
        $this->app['env'] = $previousEnvironment;
    }
});
