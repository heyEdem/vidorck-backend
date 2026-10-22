<?php

use App\Modules\Catalog\Import\CatalogSnapshot;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('takes a real VACUUM INTO snapshot of a file-backed database', function () {
    $directory = sys_get_temp_dir().'/vhi-snapshot-'.Str::random(12);
    mkdir($directory, 0700, true);
    $database = $directory.'/live.sqlite';
    touch($database);
    $previous = [config('database.default'), config('database.connections.sqlite.database'), storage_path()];
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
    app()->useStoragePath($directory);
    DB::purge('sqlite');

    try {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);

        DB::beginTransaction();
        expect(fn () => (new CatalogSnapshot)->create())->toThrow(RuntimeException::class, 'inside a transaction');
        DB::rollBack();

        $path = (new CatalogSnapshot)->create();
        expect($path)->toStartWith($directory.'/backups/catalog-')->toEndWith('.sqlite');
        expect((new CatalogSnapshot)->create())->not->toBe($path);
        $copy = new PDO('sqlite:'.$path);
        expect($copy->query('select name from categories')->fetchColumn())->toBe('Hair Care');
        $copy = null;
    } finally {
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        config(['database.default' => $previous[0], 'database.connections.sqlite.database' => $previous[1]]);
        app()->useStoragePath($previous[2]);
        foreach (array_merge(glob($directory.'/backups/*') ?: [], glob($directory.'/*') ?: []) as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($directory.'/backups');
        @rmdir($directory);
    }
});
