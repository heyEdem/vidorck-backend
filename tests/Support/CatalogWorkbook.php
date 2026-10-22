<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CatalogWorkbook
{
    public const PRODUCT_HEADERS = ['product_name', 'size', 'price_ghs', 'category', 'hair_types', 'is_published', 'display_order', 'is_new_look', 'image_key', 'product_code'];

    public const BUNDLE_HEADERS = ['bundle_name', 'fixed_price_ghs', 'product_name', 'size', 'quantity', 'display_order', 'bundle_code', 'is_published'];

    /** @param array<string, mixed> $overrides */
    public static function product(string $name, string $size, mixed $price, array $overrides = []): array
    {
        return $overrides + ['product_name' => $name, 'size' => $size, 'price_ghs' => $price, 'category' => 'Hair Care',
            'hair_types' => 'Natural Hair', 'is_published' => 1, 'display_order' => 1, 'is_new_look' => 0];
    }

    /** @param array<string, mixed> $overrides */
    public static function bundleRow(string $name, mixed $price, string $product, string $size, int $quantity = 1, array $overrides = []): array
    {
        return $overrides + ['bundle_name' => $name, 'fixed_price_ghs' => $price, 'product_name' => $product, 'size' => $size,
            'quantity' => $quantity, 'display_order' => 0];
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>} */
    public static function validRows(): array
    {
        return [[
            self::product('Daily Shampoo', '50ml', 25.5, ['hair_types' => 'Natural Hair;Permed Hair']),
            self::product('Daily Shampoo', '250ml', '45.50', ['hair_types' => 'Natural Hair;Permed Hair']),
            self::product('Edge Control', '30ml', 30, ['category' => 'Styling', 'display_order' => 2, 'is_new_look' => 1]),
            self::product('Leave-in Conditioner', '100ml', 19.99, ['display_order' => 3, 'hair_types' => 'Permed Hair']),
        ], [
            self::bundleRow('Starter Set', 70, 'Daily Shampoo', '250ml'),
            self::bundleRow('Starter Set', 70, 'Edge Control', '30ml', 2, ['display_order' => 1]),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $bundles
     * @param  list<string>|null  $productHeaders
     */
    public static function write(string $path, array $products, array $bundles = [], ?array $productHeaders = null): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Products');
        self::fill($book->getActiveSheet(), $productHeaders ?? self::PRODUCT_HEADERS, $products, $productHeaders !== null);
        self::fill($book->createSheet()->setTitle('Bundles'), self::BUNDLE_HEADERS, $bundles);
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** @param list<string> $headers */
    private static function fill($sheet, array $headers, array $rows, bool $positional = false): void
    {
        $sheet->fromArray($headers, null, 'A1', true);
        foreach ($rows as $i => $row) {
            $cells = $positional ? array_values($row) : array_map(fn (string $h): mixed => $row[$h] ?? null, $headers);
            $sheet->fromArray($cells, null, 'A'.($i + 2), true);
        }
    }
}
