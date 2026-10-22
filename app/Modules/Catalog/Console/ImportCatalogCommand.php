<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Import\CatalogImporter;
use App\Modules\Catalog\Import\ImportReport;
use App\Modules\Catalog\Import\WorkbookReader;
use Illuminate\Console\Command;
use Throwable;

final class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import {file : Path to the .xlsx workbook} {--dry-run : Validate and report without writing}
        {--apply : Snapshot the database, then write} {--images= : Directory of product images} {--report= : Write the issue table to this CSV}';

    protected $description = 'Import the catalogue workbook (see docs/catalogue-import.md). Exit codes: 0 clean, 2 completed with row errors, 1 fatal';

    public function handle(WorkbookReader $reader, CatalogImporter $importer): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Choose exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }
        $images = $this->option('images');
        if (is_string($images) && $images !== '' && ! is_dir($images)) {
            $this->error('Images directory not found: '.$images);

            return self::FAILURE;
        }
        try {
            $sheets = $reader->read((string) $this->argument('file'));
            $report = $importer->run($sheets, (bool) $this->option('apply'), is_string($images) && $images !== '' ? $images : null);
            $reportPath = $this->option('report');
            if (is_string($reportPath) && $reportPath !== '') {
                $report->writeCsv($reportPath);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->print($report);

        return $report->totals()['error'] > 0 ? 2 : self::SUCCESS;
    }

    private function print(ImportReport $report): void
    {
        $this->info($this->option('apply') ? 'Applied.' : 'Dry run: nothing was written.');
        $rows = [];
        foreach ($report->counts() as $entity => $counts) {
            $rows[] = [$entity, ...array_values($counts)];
        }
        $rows[] = ['total', ...array_values($report->totals())];
        $this->table(['', ...ImportReport::STATUSES], $rows);
        if ($report->notes() !== []) {
            $this->table(['sheet', 'row', 'field', 'info', 'note'], array_map('array_values', $report->notes()));
        }
        if ($report->issues() !== []) {
            $this->table(['sheet', 'row', 'field', 'reason', 'suggested fix'], array_map('array_values', $report->issues()));
        }
    }
}
