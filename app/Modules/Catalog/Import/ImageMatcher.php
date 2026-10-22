<?php

namespace App\Modules\Catalog\Import;

use App\Modules\Catalog\Support\CatalogNormalizer;

final class ImageMatcher
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string, list<string>> normalised key => absolute paths */
    private array $files = [];

    /** @var array<string, string> */
    private array $overrides = [];

    /** @param array<string, string> $overrides normalised name or image_key => filename in $directory */
    public function __construct(private string $directory, array $overrides = [])
    {
        $this->directory = rtrim($directory, '/');
        foreach ($overrides as $key => $file) {
            $this->overrides[self::key((string) $key)] = $file;
        }
        foreach (scandir($this->directory) ?: [] as $name) {
            $path = $this->directory.'/'.$name;
            if (is_file($path) && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                $this->files[self::key($name)][] = $path;
            }
        }
    }

    /** @return array{path: ?string, reason: ?string, fix: ?string} */
    public function match(string $productName, ?string $imageKey): array
    {
        $needle = $imageKey !== null && $imageKey !== '' ? $imageKey : $productName;
        $key = self::key($needle);
        $override = $this->overrides[$key] ?? $this->overrides[self::key($productName)] ?? null;
        if ($override !== null) {
            $path = $this->directory.'/'.basename($override);

            return is_file($path) ? ['path' => $path, 'reason' => null, 'fix' => null]
                : ['path' => null, 'reason' => "Override file '{$override}' does not exist", 'fix' => 'Fix config/catalog_image_overrides.php or add the file'];
        }
        $matches = $this->files[$key] ?? [];
        if (count($matches) === 1) {
            return ['path' => $matches[0], 'reason' => null, 'fix' => null];
        }
        if ($matches === []) {
            return ['path' => null, 'reason' => "No image file matches '{$needle}'", 'fix' => 'Add a file named after the product, set image_key, or add an override'];
        }

        return ['path' => null, 'reason' => 'Several files match: '.implode(', ', array_map('basename', $matches)),
            'fix' => 'Remove duplicates or add an override in config/catalog_image_overrides.php'];
    }

    /** Lowercase, drop extension and safe suffixes, collapse punctuation and whitespace. */
    public static function key(string $value): string
    {
        $value = preg_replace('/\.(jpe?g|png|webp)$/i', '', trim($value)) ?? $value;
        $value = CatalogNormalizer::normalize($value);
        $value = preg_replace('/[\s_-]*(\(\d+\)|@2x|final|copy|web|edited|compressed)$/', '', $value) ?? $value;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value);
    }
}
