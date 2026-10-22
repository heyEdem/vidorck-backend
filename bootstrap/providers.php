<?php

use App\Modules\Notifications\NotificationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    NotificationsServiceProvider::class,
];
