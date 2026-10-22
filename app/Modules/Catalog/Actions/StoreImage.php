<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Media;
use App\Modules\Catalog\Models\Product;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

final class StoreImage
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_EDGE = 6000;

    private const UPLOAD_MEMORY_CEILING = 512 * 1024 * 1024;

    private const VARIANT_WIDTHS = [400, 800, 1200];

    public function __construct(private AuditLogger $audit, private CatalogInvalidation $invalidation) {}

    public function store(Product|Bundle $owner, UploadedFile $file, StaffUser $actor, ?string $alt = null): Media
    {
        $media = $this->persist($owner, $file, $actor, $alt);
        $freshOwner = $owner->newQuery()->findOrFail($owner->getKey());
        if ($freshOwner instanceof Product) {
            $this->invalidation->product($freshOwner);
        } else {
            $this->invalidation->bundle($freshOwner);
        }

        return $media;
    }

    /** System entry point for the catalogue import: no actor, and the caller owns cache invalidation. */
    public function storeAsSystem(Product|Bundle $owner, UploadedFile $file, ?string $alt = null): Media
    {
        return $this->persist($owner, $file, null, $alt);
    }

    private function persist(Product|Bundle $owner, UploadedFile $file, ?StaffUser $actor, ?string $alt): Media
    {
        [$mime, $width, $height, $originalBytes, $variants] = $this->process($file);
        $publicId = (string) Str::ulid();
        $directory = 'media/'.$publicId;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };
        $paths = ['original' => $directory.'/original.'.$extension];
        foreach (self::VARIANT_WIDTHS as $variantWidth) {
            $paths[(string) $variantWidth] = $directory.'/'.$variantWidth.'.webp';
        }

        $disk = Storage::disk('media');
        try {
            $payloads = ['original' => $originalBytes];
            foreach ($variants as $variantWidth => $variantBytes) {
                $payloads[$variantWidth] = $variantBytes;
            }
            foreach ($paths as $name => $path) {
                if (! $disk->put($path, $payloads[$name])) {
                    throw new \RuntimeException('Could not write the processed image.');
                }
            }

            $media = DB::transaction(function () use ($owner, $actor, $alt, $mime, $width, $height, $originalBytes, $paths, $publicId): Media {
                $freshActor = null;
                if ($actor !== null) {
                    CatalogActorGuard::assertAdmin($actor);
                    $freshActor = StaffUser::query()->findOrFail($actor->getKey());
                }

                $freshOwner = $owner->newQuery()->lockForUpdate()->findOrFail($owner->getKey());
                $beforeAlt = $freshOwner->image_alt;
                $retiredAt = now();
                $freshOwner->media()->whereNull('retired_at')->update(['retired_at' => $retiredAt]);

                $media = new Media([
                    'public_id' => $publicId,
                    'original_path' => $paths['original'],
                    'variants_json' => array_intersect_key($paths, array_flip(array_map('strval', self::VARIANT_WIDTHS))),
                    'width' => $width,
                    'height' => $height,
                    'mime' => $mime,
                    'bytes' => strlen($originalBytes),
                    'uploaded_by' => $freshActor?->getKey(),
                ]);
                $freshOwner->media()->save($media);
                if ($alt !== null) {
                    $freshOwner->image_alt = $alt;
                    $freshOwner->save();
                }

                $this->audit->record($freshActor, 'catalogue.image_replaced', $freshOwner,
                    before: ['image_alt' => $beforeAlt],
                    after: ['public_id' => $publicId, 'mime' => $mime, 'width' => $width, 'height' => $height,
                        'bytes' => strlen($originalBytes), 'image_alt' => $freshOwner->image_alt]);

                return $media;
            }, 3);
        } catch (Throwable $exception) {
            try {
                $disk->deleteDirectory($directory);
            } catch (Throwable) {
                // Keep the original storage or transaction failure as the reported cause.
            }
            throw $exception;
        }

        return $media;
    }

    /** @return array{'image/jpeg'|'image/png'|'image/webp', int, int, string, array<int|string, string>} */
    private function process(UploadedFile $file): array
    {
        $bytes = $file->get();
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['image' => 'The image may not be larger than 8 MB.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['image' => 'The image must be a JPEG, PNG, or WebP file.']);
        }

        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false || $dimensions['mime'] !== $mime) {
            throw ValidationException::withMessages(['image' => 'The uploaded file is not a valid image.']);
        }

        $width = $dimensions[0];
        $height = $dimensions[1];
        if (max($width, $height) > self::MAX_EDGE) {
            throw ValidationException::withMessages(['image' => 'The image dimensions may not exceed 6000 pixels on either edge.']);
        }
        $this->assertMemoryBudget($width, $height);

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->read($bytes)->orient();
            unset($bytes);
            $width = $image->width();
            $height = $image->height();
            $originalBytes = match ($mime) {
                'image/jpeg' => $image->encode(new JpegEncoder(quality: 90))->toString(),
                'image/png' => $image->encode(new PngEncoder)->toString(),
                'image/webp' => $image->encode(new WebpEncoder(quality: 90))->toString(),
            };

            $variants = [];
            foreach ([1200, 800, 400] as $variantWidth) {
                // Preserve small source dimensions instead of upscaling into blurry renditions.
                if ($image->width() > $variantWidth) {
                    $image->scaleDown(width: $variantWidth);
                }
                $variants[(string) $variantWidth] = $image->encode(new WebpEncoder(quality: 82))->toString();
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['image' => 'The uploaded file could not be processed.']);
        }

        return [$mime, $width, $height, $originalBytes, $variants];
    }

    private function assertMemoryBudget(int $width, int $height): void
    {
        $memoryLimit = $this->memoryLimitBytes();
        if ($memoryLimit === null) {
            return;
        }

        // GD truecolor decodes at 4 bytes/pixel; 5 leaves room for the scaled copy.
        $pixels = $width * $height;
        $decodeBudget = $pixels > intdiv(PHP_INT_MAX, 5) ? PHP_INT_MAX : $pixels * 5;
        $overhead = 16 * 1024 * 1024;
        $currentUsage = memory_get_usage(true);
        $required = $decodeBudget > PHP_INT_MAX - $overhead - $currentUsage
            ? PHP_INT_MAX
            : $currentUsage + $decodeBudget + $overhead;

        // Lift a low FPM default for this request only, so a 6000px upload stays reachable.
        if ($required > $memoryLimit && $required <= self::UPLOAD_MEMORY_CEILING
            && ini_set('memory_limit', (string) self::UPLOAD_MEMORY_CEILING) !== false) {
            return;
        }

        if ($required > $memoryLimit) {
            throw ValidationException::withMessages(['image' => 'The image dimensions exceed the available memory budget.']);
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $setting = trim((string) ini_get('memory_limit'));
        if ($setting === '-1') {
            return null;
        }
        if (! preg_match('/^(\d+)\s*([KMG]?)$/i', $setting, $matches)) {
            return 0;
        }

        $value = (int) $matches[1];
        $multiplier = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return $value > intdiv(PHP_INT_MAX, $multiplier) ? PHP_INT_MAX : $value * $multiplier;
    }
}
