<?php

use App\Modules\Staff\Http\Middleware\EnsureStaffSession;
use App\Modules\Staff\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

foreach (glob(app_path('Modules/*/routes/api.php')) ?: [] as $routes) {
    Route::prefix('v1')->group($routes);
}

foreach (glob(app_path('Modules/*/routes/admin.php')) ?: [] as $routes) {
    Route::prefix('v1/admin')->middleware(['auth:sanctum', EnsureStaffSession::class, RequirePasswordChange::class])->group($routes);
}
