<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Str;

final class CatalogNormalizer
{
    public static function normalize(string $value): string
    {
        return Str::squish(mb_strtolower(Str::ascii($value)));
    }
}
