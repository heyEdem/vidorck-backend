<?php

namespace App\Modules\Catalog\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

final class WorkbookReader
{
    public const PRODUCTS = [
        'required' => ['product_name', 'size', 'price_ghs', 'category', 'hair_types', 'is_published', 'display_order', 'is_new_look'],
        'optional' => ['image_key', 'product_code'],
    ];

    public const BUNDLES = [
        'required' => ['bundle_name', 'fixed_price_ghs', 'product_name', 'size', 'quantity', 'display_order'],
        'optional' => ['bundle_code', 'is_published'],
    ];

    /**
     * @return array{Products: list<array{row: int, cells: array<string, mixed>}>, Bundles: list<array{row: int, cells: array<string, mixed>}>}
     *
     * @throws RuntimeException when the file is unreadable or a header row does not match the contract
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Workbook not found: '.$path);
        }
        try {
            $book = IOFactory::load($path, IReader::READ_DATA_ONLY);
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not read the workbook: '.$exception->getMessage(), 0, $exception);
        }
        $products = $book->getSheetByName('Products');
        if ($products === null) {
            throw new RuntimeException("Missing required sheet 'Products'. Found: ".implode(', ', $book->getSheetNames()));
        }
        $bundles = $book->getSheetByName('Bundles');

        // Check both headers before returning anything so a mismatch stops the run.
        [$productHeaders, $productRows] = $this->sheet($products);
        $problems = $this->mismatch('Products', $productHeaders, self::PRODUCTS);
        $bundleHeaders = $bundleRows = [];
        if ($bundles !== null) {
            [$bundleHeaders, $bundleRows] = $this->sheet($bundles);
            $problems = [...$problems, ...$this->mismatch('Bundles', $bundleHeaders, self::BUNDLES)];
        }
        if ($problems !== []) {
            throw new RuntimeException(implode("\n", $problems));
        }

        return [
            'Products' => $this->rows($productHeaders, $productRows, self::PRODUCTS),
            'Bundles' => $this->rows($bundleHeaders, $bundleRows, self::BUNDLES),
        ];
    }

    /** @return array{list<string>, list<array{int, list<mixed>}>} */
    private function sheet(Worksheet $sheet): array
    {
        $grid = $sheet->toArray(null, true, false, false);
        $headers = array_map(fn (mixed $cell): string => trim((string) $cell), array_shift($grid) ?? []);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        $rows = [];
        foreach ($grid as $index => $cells) {
            if (array_filter($cells, fn (mixed $cell): bool => $cell !== null && trim((string) $cell) !== '') !== []) {
                $rows[] = [$index + 2, array_values($cells)];
            }
        }

        return [$headers, $rows];
    }

    /**
     * @param  list<string>  $found
     * @param  array{required: list<string>, optional: list<string>}  $contract
     * @return list<string>
     */
    private function mismatch(string $sheet, array $found, array $contract): array
    {
        $allowed = [...$contract['required'], ...$contract['optional']];
        $missing = array_values(array_diff($contract['required'], $found));
        $unknown = array_values(array_diff($found, $allowed));
        $duplicates = array_keys(array_filter(array_count_values($found), fn (int $n): bool => $n > 1));
        if ($missing === [] && $unknown === [] && $duplicates === []) {
            return [];
        }
        $mapping = [];
        $free = $missing;
        foreach ($unknown as $header) {
            $best = null;
            $bestScore = 0.0;
            foreach (array_values(array_diff([...$free, ...array_diff($contract['optional'], $found)], $found)) as $candidate) {
                similar_text($this->squash($header), $this->squash($candidate), $score);
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$candidate, $score];
                }
            }
            if ($best !== null && $bestScore >= 50.0) {
                $mapping[] = "'{$header}' => '{$best}'";
                $free = array_values(array_diff($free, [$best]));
            }
        }

        $lines = [
            "Sheet '{$sheet}' headers do not match the contract; nothing was read or written.",
            '  expected: '.implode(', ', $contract['required']).' (optional: '.implode(', ', $contract['optional']).')',
            '  found:    '.implode(', ', $found),
            $missing === [] ? null : '  missing:  '.implode(', ', $missing),
            $unknown === [] ? null : '  unknown:  '.implode(', ', $unknown),
            $duplicates === [] ? null : '  duplicate: '.implode(', ', $duplicates),
            '  proposed mapping (rename these headers after review): '.($mapping === [] ? 'none' : implode('; ', $mapping)),
        ];

        return array_values(array_filter($lines, fn (?string $line): bool => $line !== null));
    }

    private function squash(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array{int, list<mixed>}>  $rows
     * @param  array{required: list<string>, optional: list<string>}  $contract
     * @return list<array{row: int, cells: array<string, mixed>}>
     */
    private function rows(array $headers, array $rows, array $contract): array
    {
        $out = [];
        foreach ($rows as [$number, $cells]) {
            $named = [];
            foreach ([...$contract['required'], ...$contract['optional']] as $name) {
                $position = array_search($name, $headers, true);
                $named[$name] = $position === false ? null : ($cells[$position] ?? null);
            }
            $out[] = ['row' => $number, 'cells' => $named];
        }

        return $out;
    }
}
