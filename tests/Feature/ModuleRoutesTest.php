<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('loads module public and admin routes with versioned prefixes', function () {
    $directory = sys_get_temp_dir().'/vhi-routes-'.bin2hex(random_bytes(8));
    $appPath = app_path();
    File::ensureDirectoryExists($directory.'/Modules/Probe/routes');
    File::put($directory.'/Modules/Probe/routes/api.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("probe", fn () => ["scope" => "public"]);');
    File::put($directory.'/Modules/Probe/routes/admin.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("probe", fn () => ["scope" => "admin"]);');

    try {
        $this->app->useAppPath($directory);
        Route::prefix('api')->middleware('api')->group(base_path('routes/api.php'));
        $this->get('/api/v1/probe')->assertOk()->assertJsonPath('scope', 'public');
        $this->get('/api/v1/admin/probe')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    } finally {
        $this->app->useAppPath($appPath);
        File::deleteDirectory($directory);
    }
});
