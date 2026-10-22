<?php

use App\Modules\Catalog\Jobs\RevalidateStorefront;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Media;
use App\Modules\Catalog\Models\Product;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    Storage::fake('media');
    Bus::fake([RevalidateStorefront::class]);

    $this->admin = StaffUser::create(['name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
    $this->sales = StaffUser::create(['name' => 'Sales', 'email' => 'sales@example.com',
        'password' => 'Long-Staff-Password9!', 'role' => StaffRole::SALES_ORDERS]);
    $category = Category::create(['name' => 'Hair Care', 'slug' => 'hair-care']);
    $this->product = Product::create(['name' => 'Daily Shampoo', 'slug' => 'daily-shampoo', 'category_id' => $category->id]);

    $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
});

it('stores a 3000px JPEG with metadata-free original and WebP renditions', function () {
    $source = UploadedFile::fake()->createWithContent('source.jpg', jpegWithExif(3000, 2000));
    expect(@exif_read_data($source->getPathname())['ImageDescription'])->toBe('Sample');

    $response = $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
        'image' => $source,
        'alt' => 'Daily shampoo bottle',
    ])->assertCreated();

    $media = Media::sole();
    $image = $response->json('data.image');
    expect($media->width)->toBe(3000)
        ->and($media->height)->toBe(2000)
        ->and($media->owner_type)->toBe(Product::class)
        ->and($media->variants_json)->toHaveKeys(['400', '800', '1200'])
        ->and($image['alt'])->toBe('Daily shampoo bottle')
        ->and($image['widths'])->toBe([400, 800, 1200])
        ->and($image['url'])->toBe(Storage::disk('media')->url($media->original_path))
        ->and($image['urls'][400])->toBe(Storage::disk('media')->url($media->variants_json[400]))
        ->and(AuditLog::where('action', 'catalogue.image_replaced')->count())->toBe(1);

    $metadata = exif_read_data(Storage::disk('media')->path($media->original_path));
    expect($metadata['ImageDescription'] ?? null)->toBeNull()
        ->and($metadata['SectionsFound'] ?? '')->not->toContain('EXIF');
    foreach ($media->variants_json as $width => $path) {
        Storage::disk('media')->assertExists($path);
        $details = getimagesize(Storage::disk('media')->path($path));
        expect(exif_imagetype(Storage::disk('media')->path($path)))->toBe(IMAGETYPE_WEBP)
            ->and($details[0])->toBe((int) $width);
    }
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);
});

it('retains replaced media and its files while marking it retired', function () {
    $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('first.jpg', jpegBytes(900, 600)),
    ])->assertCreated();
    $old = Media::sole();

    $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('second.png', pngBytes(320, 200)),
    ])->assertCreated();

    expect($old->fresh()->retired_at)->not->toBeNull()
        ->and(Media::whereNull('retired_at')->count())->toBe(1);
    Storage::disk('media')->assertExists($old->original_path);
    foreach ($old->variants_json as $path) {
        Storage::disk('media')->assertExists($path);
    }
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 2);
});

it('rejects a disguised script, corrupt image bytes, oversized dimensions, and uploads over 8 MB', function () {
    foreach ([
        UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "not an image";'),
        UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00broken"),
        UploadedFile::fake()->createWithContent('too-wide.jpg', jpegBytes(6001, 2)),
        UploadedFile::fake()->createWithContent('too-large.jpg', jpegBytes(1, 1).str_repeat('x', 8 * 1024 * 1024)),
    ] as $file) {
        $this->post('/api/v1/admin/products/'.$this->product->id.'/image', ['image' => $file])->assertUnprocessable();
    }

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(AuditLog::where('action', 'catalogue.image_replaced')->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('accepts a 12MP phone photo under the default 128M PHP memory limit', function () {
    $oldLimit = ini_get('memory_limit');
    ini_set('memory_limit', '128M');

    try {
        $response = $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
            'image' => UploadedFile::fake()->createWithContent('phone.jpg', jpegBytes(4032, 3024)),
        ]);
    } finally {
        ini_set('memory_limit', (string) $oldLimit);
    }

    $response->assertCreated();
    expect(Media::sole()->width)->toBe(4032);
});

it('accepts a 6000 by 4000 image when the PHP memory budget is sufficient', function () {
    $oldLimit = ini_get('memory_limit');
    ini_set('memory_limit', '512M');

    try {
        $response = $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
            'image' => UploadedFile::fake()->createWithContent('large.jpg', jpegBytes(6000, 4000)),
        ]);
    } finally {
        ini_set('memory_limit', (string) $oldLimit);
    }

    $response->assertCreated();
    expect(Media::sole()->width)->toBe(6000)
        ->and(Media::sole()->height)->toBe(4000);
});

it('rolls back media and removes processed files when the audit write fails', function () {
    $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('first.jpg', jpegBytes(900, 600)),
    ])->assertCreated();
    $old = Media::sole();
    $oldPath = $old->original_path;

    DB::statement("CREATE TRIGGER reject_image_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'catalogue.image_replaced' BEGIN SELECT RAISE(ABORT, 'audit rejected'); END");
    try {
        $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
            'image' => UploadedFile::fake()->createWithContent('replacement.png', pngBytes(320, 200)),
        ])->assertServerError();
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS reject_image_audit');
    }

    expect(Media::count())->toBe(1)
        ->and($old->fresh()->retired_at)->toBeNull()
        ->and(Media::whereNull('retired_at')->sole()->getKey())->toBe($old->getKey());
    Storage::disk('media')->assertExists($oldPath);
    expect(Storage::disk('media')->allFiles())->toHaveCount(4);
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 1);
});

it('allows only admins to upload product and bundle images', function () {
    $bundle = Bundle::create(['name' => 'Hair Set', 'slug' => 'hair-set', 'product_code' => 'SET-1', 'price_minor' => 1000]);

    $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('product.jpg', jpegBytes(10, 10)),
    ])->assertCreated();
    $this->post('/api/v1/admin/bundles/'.$bundle->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('bundle.jpg', jpegBytes(10, 10)),
    ])->assertCreated();

    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'sales@example.com', 'password' => 'Long-Staff-Password9!'])->assertOk();
    $this->post('/api/v1/admin/products/'.$this->product->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('denied.jpg', jpegBytes(10, 10)),
    ])->assertForbidden();
    $this->post('/api/v1/admin/bundles/'.$bundle->id.'/image', [
        'image' => UploadedFile::fake()->createWithContent('denied.jpg', jpegBytes(10, 10)),
    ])->assertForbidden();

    expect(Media::count())->toBe(2);
    Bus::assertDispatchedTimes(RevalidateStorefront::class, 2);
});

function jpegBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 180, 50, 90));
    ob_start();
    imagejpeg($image, null, 90);
    imagedestroy($image);

    return (string) ob_get_clean();
}

function jpegWithExif(int $width, int $height): string
{
    $jpeg = jpegBytes($width, $height);
    $tiff = 'II'.pack('v', 42).pack('V', 8)
        .pack('v', 1).pack('v', 0x010E).pack('v', 2).pack('V', 6).pack('V', 26)
        .pack('V', 0).'Sample'."\0";
    $exif = "Exif\0\0".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

function pngBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}
