<?php

use App\Modules\Catalog\Import\CatalogImporter;
use App\Modules\Catalog\Import\CatalogSnapshot;
use App\Modules\Catalog\Import\ImportReport;
use App\Modules\Catalog\Import\WorkbookReader;
use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Media;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogWorkbook as W;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media');
    Bus::fake([RevalidateStorefront::class]);
    $this->snapshots = new class extends CatalogSnapshot
    {
        public int $calls = 0;

        public function create(): string
        {
            $this->calls++;

            return 'fake';
        }
    };
    app()->instance(CatalogSnapshot::class, $this->snapshots);
    Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    Category::create(['name' => 'Styling', 'slug' => 'styling']);
    HairType::create(['name' => 'Natural Hair', 'slug' => 'natural-hair']);
    HairType::create(['name' => 'Permed Hair', 'slug' => 'permed-hair']);
    $this->dir = sys_get_temp_dir().'/vhi-import-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

function importWorkbook(string $file, bool $apply, ?string $images = null): ImportReport
{
    return app(CatalogImporter::class)->run(app(WorkbookReader::class)->read($file), $apply, $images);
}

function workbook(array $products, array $bundles = []): string
{
    return W::write(test()->dir.'/book.xlsx', $products, $bundles);
}

function png(string $path): void
{
    $image = imagecreatetruecolor(40, 40);
    imagepng($image, $path);
}

it('dry_run_does_not_mutate', function () {
    $this->artisan('catalog:import', ['file' => base_path('tests/Fixtures/catalog/valid.xlsx'), '--dry-run' => true])
        ->expectsOutputToContain('nothing was written')->assertExitCode(0);

    expect(Product::count())->toBe(0)->and(ProductVariant::count())->toBe(0)->and(Bundle::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0)->and($this->snapshots->calls)->toBe(0);
    Bus::assertNothingDispatched();

    $report = importWorkbook(base_path('tests/Fixtures/catalog/valid.xlsx'), false);
    expect($report->counts()['variant']['created'])->toBe(4)->and($report->counts()['bundle']['created'])->toBe(1)
        ->and($report->totals()['error'])->toBe(0);
});

it('bad_row_does_not_block_good_rows', function () {
    $rows = [];
    foreach (range(1, 10) as $i) {
        $rows[] = W::product("Product {$i}", '100ml', $i === 4 ? null : 10 + $i, ['display_order' => $i]);
    }
    $report = importWorkbook(workbook($rows), true);

    expect(ProductVariant::count())->toBe(9)->and($report->counts()['variant']['created'])->toBe(9)
        ->and($report->totals()['error'])->toBe(1)->and($report->issues())->toHaveCount(1)
        ->and($report->issues()[0])->toMatchArray(['sheet' => 'Products', 'row' => 5, 'field' => 'price_ghs']);
});

it('import_is_idempotent', function () {
    [$p, $b] = W::validRows();
    $file = workbook($p, $b);
    importWorkbook($file, true);
    $variants = ProductVariant::pluck('product_code', 'id')->all();
    $second = importWorkbook($file, true);

    expect($second->totals())->toMatchArray(['created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0])
        ->and($second->totals()['unchanged'])->toBe(12)
        ->and(ProductVariant::pluck('product_code', 'id')->all())->toBe($variants)
        ->and(Product::count())->toBe(3)->and(Bundle::count())->toBe(1);
});

it('bundle_with_invalid_component_is_skipped', function () {
    [$p] = W::validRows();
    $report = importWorkbook(workbook($p, [
        W::bundleRow('Broken Set', 50, 'Daily Shampoo', '250ml'),
        W::bundleRow('Broken Set', 50, 'Daily Shampoo', '999ml', 1, ['display_order' => 1]),
    ]), true);

    expect(Bundle::count())->toBe(0)->and($report->counts()['bundle']['error'])->toBe(1)
        ->and($report->issues()[0])->toMatchArray(['sheet' => 'Bundles', 'row' => 3])
        ->and($report->issues()[0]['reason'])->toContain('Unknown size');
});

it('stops on a header mismatch before any write', function () {
    $headers = W::PRODUCT_HEADERS;
    $headers[0] = 'Product Name';
    $row = array_values(W::product('Daily Shampoo', '50ml', 10) + array_fill_keys(W::PRODUCT_HEADERS, null));
    $file = W::write($this->dir.'/bad.xlsx', [$row], [], $headers);

    $this->artisan('catalog:import', ['file' => $file, '--apply' => true])
        ->expectsOutputToContain("'Product Name' => 'product_name'")->assertExitCode(1);
    expect(Product::count())->toBe(0)->and($this->snapshots->calls)->toBe(0)->and(AuditLog::count())->toBe(0);
});

it('requires exactly one mode', function () {
    $this->artisan('catalog:import', ['file' => base_path('tests/Fixtures/catalog/valid.xlsx')])->assertExitCode(1);
});

it('derives prices exactly and applies the markup only to variants', function () {
    [$p, $b] = W::validRows();
    importWorkbook(workbook([...$p, W::product('Float Trap', '1ml', 0.1 + 0.2, ['display_order' => 9]), W::product('Too Precise', '1ml', '12.345')], $b), true);

    $variant = fn (string $size, string $name) => ProductVariant::where('normalized_size', $size)
        ->whereHas('product', fn ($q) => $q->where('name', $name))->firstOrFail();
    expect($variant('250ml', 'Daily Shampoo')->only(['source_price_minor', 'price_minor']))->toBe(['source_price_minor' => 4550, 'price_minor' => 4750])
        ->and($variant('100ml', 'Leave-in Conditioner')->only(['source_price_minor', 'price_minor']))->toBe(['source_price_minor' => 1999, 'price_minor' => 2199])
        ->and($variant('1ml', 'Float Trap')->source_price_minor)->toBe(30)
        ->and(Product::where('name', 'Too Precise')->exists())->toBeFalse()
        ->and(Bundle::firstOrFail()->price_minor)->toBe(7000)
        ->and(Bundle::firstOrFail()->items)->toHaveCount(2);
});

it('writes exactly one audit row and queues one revalidation per apply', function () {
    [$p, $b] = W::validRows();
    $file = workbook($p, $b);
    importWorkbook($file, true);

    $audit = AuditLog::where('action', 'catalog.import.applied')->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->actor_type)->toBe('SYSTEM')->and($audit[0]->after['totals']['created'])->toBe(8)
        ->and($this->snapshots->calls)->toBe(1);
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);
    Bus::assertDispatched(RevalidateStorefront::class, fn ($job) => in_array('product:daily-shampoo', $job->tags, true) && in_array('bundle:starter-set', $job->tags, true));

    importWorkbook($file, true);
    expect(AuditLog::where('action', 'catalog.import.applied')->count())->toBe(2);
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 2);
});

it('preserves existing codes and generates deterministic ones', function () {
    [$p] = W::validRows();
    importWorkbook(workbook($p), true);
    $code = ProductVariant::orderBy('id')->firstOrFail()->product_code;
    expect($code)->toStartWith('VHI-');
    ProductVariant::orderBy('id')->firstOrFail()->update(['product_code' => 'KEEP-1']);

    importWorkbook(workbook($p), true);
    expect(ProductVariant::orderBy('id')->firstOrFail()->product_code)->toBe('KEEP-1');
});

it('does not delete or unpublish items missing from the workbook', function () {
    [$p, $b] = W::validRows();
    importWorkbook(workbook($p, $b), true);
    importWorkbook(workbook([$p[2]]), true);

    expect(Product::count())->toBe(3)->and(Bundle::count())->toBe(1)->and(Product::where('is_published', false)->count())->toBe(0);
});

it('reports an ambiguous image as an error row and uploads nothing', function () {
    png($this->dir.'/daily-shampoo.png');
    png($this->dir.'/Daily Shampoo-final.png');
    [$p] = W::validRows();
    $report = importWorkbook(workbook([$p[0], $p[1]]), true, $this->dir);

    expect(Media::count())->toBe(0)->and($report->counts()['image']['error'])->toBe(1)->and(Product::count())->toBe(1)
        ->and(collect($report->issues())->firstWhere('field', 'image_key')['reason'])->toContain('Several files match');
});

it('imports a matched image once as a system upload with a single revalidation', function () {
    png($this->dir.'/Daily_Shampoo.png');
    [$p] = W::validRows();
    $file = workbook([$p[0], $p[1], $p[2]]);
    $report = importWorkbook($file, true, $this->dir);

    $media = Media::firstOrFail();
    expect($report->counts()['image'])->toMatchArray(['created' => 1, 'error' => 1])
        ->and($media->uploaded_by)->toBeNull()->and(Product::where('name', 'Daily Shampoo')->firstOrFail()->image_alt)->toBe('Daily Shampoo')
        ->and(AuditLog::where('actor_type', 'SYSTEM')->where('action', 'catalogue.image_replaced')->count())->toBe(1);
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);

    $again = importWorkbook($file, true, $this->dir);
    expect($again->counts()['image']['unchanged'])->toBe(1)->and(Media::count())->toBe(1);
});

it('does not check images without --images', function () {
    [$p] = W::validRows();
    $report = importWorkbook(workbook($p), true);

    expect($report->counts())->not->toHaveKey('image');
});

it('aborts an apply when the snapshot fails', function () {
    app()->instance(CatalogSnapshot::class, new class extends CatalogSnapshot
    {
        public function create(): string
        {
            throw new RuntimeException('disk full');
        }
    });
    $this->artisan('catalog:import', ['file' => base_path('tests/Fixtures/catalog/valid.xlsx'), '--apply' => true])
        ->expectsOutputToContain('disk full')->assertExitCode(1);

    expect(Product::count())->toBe(0)->and(AuditLog::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('writes the issue table to a CSV report', function () {
    $file = workbook([W::product('A', '1ml', null), W::product('=B', '1ml', 5, ['is_published' => 'maybe'])]);
    $this->artisan('catalog:import', ['file' => $file, '--dry-run' => true, '--report' => $this->dir.'/r.csv'])->assertExitCode(2);

    $csv = file_get_contents($this->dir.'/r.csv');
    expect($csv)->toContain('sheet,row,field,reason,"suggested fix"')->toContain('price_ghs')->toContain('is not 0 or 1');
});

it('resolves bundle components without name/size key collisions', function () {
    $report = importWorkbook(workbook([
        W::product('Pipe|Name', 'Size', 10),
        W::product('Pipe', 'Name|Size', 20, ['display_order' => 2]),
    ], [W::bundleRow('Pipes', 30, 'Pipe', 'Name|Size')]), true);

    $variant = ProductVariant::where('size_label', 'Name|Size')->firstOrFail();
    expect($report->totals()['error'])->toBe(0)->and(Bundle::firstOrFail()->items->pluck('product_variant_id')->all())->toBe([$variant->id]);
});

it('rejects a product whose invalid row disagrees on a product-level value', function () {
    $report = importWorkbook(workbook([
        W::product('Conflicted', '1ml', 10),
        W::product('Conflicted', '2ml', null, ['category' => 'Styling']),
    ]), true);

    expect(Product::count())->toBe(0)->and($report->counts()['product']['error'])->toBe(1)
        ->and(collect($report->issues())->pluck('field'))->toContain('category');
});

it('releases product codes when a product is rejected', function () {
    Product::create(['name' => 'Taken', 'slug' => 'x-item', 'category_id' => Category::first()->id]);
    $report = importWorkbook(workbook([
        W::product('X Item', '1ml', 10, ['product_code' => 'SHARED-1']),
        W::product('Other', '1ml', 10, ['product_code' => 'SHARED-1', 'display_order' => 2]),
    ]), true);

    expect($report->counts()['product'])->toMatchArray(['created' => 1, 'error' => 1])
        ->and(ProductVariant::where('product_code', 'SHARED-1')->count())->toBe(1);
});

it('treats duplicate hair type tokens as one so re-runs stay unchanged', function () {
    $file = workbook([W::product('Dup Hair', '1ml', 10, ['hair_types' => 'Natural Hair;natural-hair'])]);
    importWorkbook($file, true);

    expect(importWorkbook($file, true)->totals())->toMatchArray(['created' => 0, 'updated' => 0, 'error' => 0, 'unchanged' => 4]);
});

it('releases bundle codes when a bundle is rejected', function () {
    [$p] = W::validRows();
    $report = importWorkbook(workbook($p, [
        W::bundleRow('Bad Set', 50, 'Daily Shampoo', '250ml', 1, ['bundle_code' => 'BSHARED']),
        W::bundleRow('Bad Set', 50, 'Daily Shampoo', '999ml', 1, ['bundle_code' => 'BSHARED', 'display_order' => 1]),
        W::bundleRow('Good Set', 60, 'Edge Control', '30ml', 1, ['bundle_code' => 'BSHARED']),
    ]), true);

    expect($report->counts()['bundle'])->toMatchArray(['created' => 1, 'error' => 1])->and(Bundle::firstOrFail()->product_code)->toBe('BSHARED');
});

it('keeps numeric-looking product codes distinct', function () {
    $report = importWorkbook(workbook([
        W::product('Num A', '1ml', 10, ['product_code' => '123']),
        W::product('Num B', '1ml', 10, ['product_code' => '123', 'display_order' => 2]),
        W::product('Num C', '1ml', 10, ['product_code' => '0', 'display_order' => 3]),
    ]), false);

    expect($report->counts()['variant'])->toMatchArray(['created' => 2, 'error' => 1])->and($report->issues()[0]['row'])->toBe(3);
});

it('creates unknown categories and hair types once, reusing them across products', function () {
    $report = importWorkbook(workbook([
        W::product('New A', '1ml', 10, ['category' => ' Brand New ', 'hair_types' => 'Coily; Natural Hair']),
        W::product('New B', '1ml', 10, ['category' => 'brand new', 'hair_types' => 'coily', 'display_order' => 2]),
    ]), true);

    $category = Category::where('slug', 'brand-new')->firstOrFail();
    expect(Category::where('name', 'Brand New')->count())->toBe(1)->and($category->is_active)->toBeTrue()->and($category->display_order)->toBe(1)
        ->and(HairType::where('slug', 'coily')->count())->toBe(1)
        ->and(Product::where('category_id', $category->id)->count())->toBe(2)
        ->and($report->counts()['category'])->toMatchArray(['created' => 1, 'unchanged' => 0])
        ->and($report->counts()['hair_type'])->toMatchArray(['created' => 1, 'unchanged' => 1])
        ->and(collect($report->notes())->pluck('reason')->all())->toBe(["Created category 'Brand New'", "Created hair type 'Coily'"])
        ->and($report->totals()['error'])->toBe(0)
        ->and(AuditLog::where('action', 'catalog.import.applied')->firstOrFail()->after['counts']['category']['created'])->toBe(1);

    $again = importWorkbook(workbook([W::product('New A', '1ml', 10, ['category' => 'Brand New', 'hair_types' => 'Coily; Natural Hair'])]), true);
    expect($again->totals())->toMatchArray(['created' => 0, 'updated' => 0, 'error' => 0]);
});

it('would create lookups in a dry run without writing them', function () {
    $report = importWorkbook(workbook([
        W::product('New A', '1ml', 10, ['category' => 'Brand New', 'hair_types' => 'Coily']),
        W::product('New B', '1ml', 10, ['category' => 'Brand New', 'hair_types' => 'Coily', 'display_order' => 2]),
    ]), false);

    expect(Category::count())->toBe(2)->and(HairType::count())->toBe(2)
        ->and($report->counts()['category']['created'])->toBe(1)->and($report->counts()['hair_type']['created'])->toBe(1)
        ->and($report->notes()[0]['reason'])->toStartWith('Would create');
});

it('leaves no orphan lookups when the product write rolls back', function () {
    DB::unprepared("CREATE TRIGGER boom BEFORE INSERT ON product_variants BEGIN SELECT RAISE(ABORT, 'boom'); END");
    $book = workbook([W::product('New A', '1ml', 10, ['category' => 'Brand New', 'hair_types' => 'Coily'])]);
    $failed = importWorkbook($book, true);

    expect($failed->totals()['error'])->toBe(1)->and(Category::count())->toBe(2)->and(HairType::count())->toBe(2)
        ->and($failed->counts())->not->toHaveKey('category');

    DB::unprepared('DROP TRIGGER boom');
    $ok = importWorkbook($book, true);
    expect($ok->counts()['category']['created'])->toBe(1)->and(Category::count())->toBe(3)->and(HairType::count())->toBe(3);
});

it('exits 0 when clean, 2 with row errors, 1 when fatal', function () {
    $this->artisan('catalog:import', ['file' => base_path('tests/Fixtures/catalog/valid.xlsx'), '--dry-run' => true])->assertExitCode(0);
    $this->artisan('catalog:import', ['file' => workbook([W::product('A', '1ml', null), W::product('B', '1ml', 5)]), '--apply' => true])->assertExitCode(2);
    expect(Product::where('name', 'B')->exists())->toBeTrue();
    $this->artisan('catalog:import', ['file' => $this->dir.'/missing.xlsx', '--apply' => true])->assertExitCode(1);
});
