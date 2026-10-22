<?php

use App\Modules\Catalog\Import\CatalogImporter;
use App\Modules\Catalog\Import\CatalogSnapshot;
use App\Modules\Catalog\Import\WorkbookReader;
use App\Modules\Catalog\Models\Category;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogWorkbook as W;

uses(RefreshDatabase::class);

it('commits the revalidation job together with the audit row', function () {
    config(['queue.default' => 'database']);
    app()->instance(CatalogSnapshot::class, new class extends CatalogSnapshot
    {
        public function create(): string
        {
            return 'fake';
        }
    });
    Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $file = W::write(sys_get_temp_dir().'/vhi-atomic-'.bin2hex(random_bytes(4)).'.xlsx', [W::product('A', '1ml', 10, ['hair_types' => ''])]);
    $sheets = app(WorkbookReader::class)->read($file);

    AuditLog::creating(fn () => throw new RuntimeException('audit failed'));
    expect(fn () => app(CatalogImporter::class)->run($sheets, true))->toThrow(RuntimeException::class, 'audit failed');
    expect(DB::table('jobs')->count())->toBe(0);

    AuditLog::flushEventListeners();
    app(CatalogImporter::class)->run($sheets, true);
    expect(DB::table('jobs')->count())->toBe(1);
    unlink($file);
});
