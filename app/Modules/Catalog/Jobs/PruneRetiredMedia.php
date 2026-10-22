<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Deletes files first, then the row; any file failure keeps the row so the next run retries. */
final class PruneRetiredMedia
{
    public function __invoke(): void
    {
        $disk = Storage::disk('media');
        Media::query()->whereNotNull('retired_at')->where('retired_at', '<', now()->subDays(30))
            ->chunkById(100, function ($items) use ($disk): void {
                foreach ($items as $media) {
                    try {
                        $paths = [$media->original_path, ...array_values((array) $media->getAttribute('variants_json'))];
                        if (! $disk->delete($paths)) {
                            throw new \RuntimeException('Could not delete media files.');
                        }
                        Media::query()->whereKey($media->id)->whereNotNull('retired_at')->delete();
                    } catch (Throwable $exception) {
                        Log::warning('Retired media cleanup failed.', ['media_id' => $media->public_id, 'exception' => $exception::class]);
                    }
                }
            });
    }
}
