<?php

namespace App\Commands;

use App\Services\CustomerListExportService;
use App\Services\OracleExtractionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Ad-hoc performance harness for the CUSTOMERS_LIST export pipeline.
 *
 *   php spark export:bench csv 100000
 *   php spark export:bench xlsx 500000
 *   php spark export:bench xlsx 0          (0 = the whole table)
 *
 * Runs the real CustomerListExportService against the real Oracle source,
 * but caps the row count with an outer ROWNUM filter so a partial run can be
 * measured without pulling all ~3.28M rows every time. Prints rows/s, wall
 * time, peak memory and output file size, then deletes the generated file.
 *
 * Dev tooling only — never wired into a route or a scheduled task.
 */
class ExportBenchmark extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'export:bench';
    protected $description  = 'Benchmark the CUSTOMERS_LIST CSV/XLSX export against the real Oracle source.';
    protected $usage        = 'export:bench <csv|xlsx> <maxRows|0>';
    protected $arguments    = [
        'format'  => 'csv or xlsx (default: csv)',
        'maxRows' => 'Row cap, 0 for the whole table (default: 50000)',
    ];

    public function run(array $params): int
    {
        $format  = strtolower($params[0] ?? 'csv');
        $maxRows = (int) ($params[1] ?? 50000);

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            CLI::error("format must be 'csv' or 'xlsx'");

            return EXIT_ERROR;
        }

        $service = new CustomerListExportService(new BenchmarkOracleExtractionService($maxRows));

        CLI::write(sprintf('Running %s export (cap: %s rows)...', strtoupper($format), $maxRows === 0 ? 'none' : number_format($maxRows)));

        $wallStart = microtime(true);
        $meta      = $format === 'xlsx' ? $service->exportXlsx() : $service->exportCsv();
        $wall      = microtime(true) - $wallStart;

        CLI::write('');
        CLI::table([
            ['Rows', number_format($meta['rows'])],
            ['Sheets', (string) $meta['sheets']],
            ['Wall time', sprintf('%.1f s', $wall)],
            ['Throughput', sprintf('%s rows/s', number_format((int) ($meta['rows'] / max($wall, 0.001))))],
            ['SQL (first row)', sprintf('%.0f ms', $meta['sqlDurationMs'])],
            ['Fetch', sprintf('%.0f ms', $meta['fetchDurationMs'])],
            ['Write', sprintf('%.0f ms', $meta['writeDurationMs'])],
            ['File size', sprintf('%.1f MB', $meta['fileSize'] / 1048576)],
            ['Peak memory', sprintf('%.0f MB', $meta['peakMemoryMb'])],
        ], ['Metric', 'Value']);

        if (is_file($meta['path'])) {
            @unlink($meta['path']);
        }

        return EXIT_SUCCESS;
    }
}

/**
 * Wraps stream() with an outer ROWNUM cap so a benchmark can stop early
 * without the production service knowing anything about row limits.
 */
class BenchmarkOracleExtractionService extends OracleExtractionService
{
    public function __construct(private readonly int $maxRows)
    {
        parent::__construct();
    }

    public function stream(string $sql, callable $onRow, array $binds = [], ?int $prefetch = null): int
    {
        if ($this->maxRows > 0) {
            $sql = "SELECT * FROM (\n{$sql}\n) WHERE ROWNUM <= {$this->maxRows}";
        }

        return parent::stream($sql, $onRow, $binds, $prefetch);
    }
}
