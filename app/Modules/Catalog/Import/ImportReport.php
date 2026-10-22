<?php

namespace App\Modules\Catalog\Import;

use RuntimeException;

final class ImportReport
{
    public const STATUSES = ['created', 'updated', 'unchanged', 'skipped', 'error'];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var list<array{sheet: string, row: int|string, field: string, reason: string, fix: string}> */
    private array $issues = [];

    /** @var list<array{sheet: string, row: int|string, field: string, reason: string, fix: string}> */
    private array $notes = [];

    public function note(string $sheet, int|string $row, string $field, string $reason, string $fix = ''): void
    {
        $this->notes[] = ['sheet' => $sheet, 'row' => $row, 'field' => $field, 'reason' => $reason, 'fix' => $fix];
    }

    /** @return list<array{sheet: string, row: int|string, field: string, reason: string, fix: string}> informational lines, not errors */
    public function notes(): array
    {
        return $this->notes;
    }

    public function count(string $entity, string $status, int $by = 1): void
    {
        $this->counts[$entity][$status] = ($this->counts[$entity][$status] ?? 0) + $by;
    }

    public function issue(string $sheet, int|string $row, string $field, string $reason, string $fix = ''): void
    {
        $this->issues[] = ['sheet' => $sheet, 'row' => $row, 'field' => $field, 'reason' => $reason, 'fix' => $fix];
    }

    /** @return array<string, array<string, int>> entity => status => n */
    public function counts(): array
    {
        $out = [];
        foreach ($this->counts as $entity => $statuses) {
            foreach (self::STATUSES as $status) {
                $out[$entity][$status] = $statuses[$status] ?? 0;
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($this->counts as $statuses) {
            foreach ($statuses as $status => $n) {
                $totals[$status] += $n;
            }
        }

        return $totals;
    }

    /** @return list<array{sheet: string, row: int|string, field: string, reason: string, fix: string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function writeCsv(string $path): void
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write the report to '.$path);
        }
        fputcsv($handle, ['sheet', 'row', 'field', 'reason', 'suggested fix'], escape: '');
        foreach ([...$this->issues, ...array_map(fn (array $n): array => ['reason' => 'INFO: '.$n['reason']] + $n, $this->notes)] as $issue) {
            // Neutralise spreadsheet formulas coming from workbook cell text.
            fputcsv($handle, array_map(fn (mixed $cell): string => preg_match('/^[=+\-@\t\r]/', (string) $cell) === 1 ? "'".$cell : (string) $cell,
                array_values($issue)), escape: '');
        }
        fclose($handle);
    }
}
