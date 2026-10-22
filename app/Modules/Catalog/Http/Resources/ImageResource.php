<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Media;
use App\Modules\Catalog\Models\Product;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** @mixin Media */
final class ImageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variants = $this->variants_json ?? [];
        ksort($variants, SORT_NUMERIC);
        $owner = $this->owner;

        return [
            'url' => Storage::disk('media')->url($this->original_path),
            'urls' => $this->urls($variants),
            'alt' => $owner instanceof Product || $owner instanceof Bundle ? $owner->image_alt ?? '' : '',
            'widths' => array_map('intval', array_keys($variants)),
        ];
    }

    /** @param array<int|string, string> $variants */
    private function urls(array $variants): object
    {
        $urls = [];
        foreach ($variants as $width => $path) {
            $urls[(string) $width] = Storage::disk('media')->url($path);
        }

        return (object) $urls;
    }
}
