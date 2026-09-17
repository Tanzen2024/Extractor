<?php

namespace App\Services;

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Export\CsvRowWriter;
use App\Services\Export\XlsxRowWriter;
use Config\Oracle as OracleConfig;
use RuntimeException;

/**
 * Exports the CUSTOMERS_LIST data set (CMS_RFC.TB_CUSTOMERS_LIST, ~3.28M
 * rows) to CSV or XLSX without ever holding the full result set in memory.
 *
 * The 25-column SELECT is fixed (SQL_SELECT); the only thing that varies is
 * an optional WHERE clause built — via the shared QueryBuilder — from the
 * exact same FilterCriteria that drives the dashboard, so an export always
 * contains precisely the population the dashboard was showing. Filter values
 * travel as oci_bind_by_name placeholders; no request text is concatenated.
 *
 * Both formats stream row-by-row from Oracle (OracleExtractionService::stream(),
 * with the existing OCI8 prefetch tuning) straight into an open file/writer,
 * so PHP's memory stays flat regardless of row count:
 *   - CSV:  fputcsv() into a file handle (CsvRowWriter).
 *   - XLSX: OpenSpout streaming writer (XlsxRowWriter) — no in-memory
 *           workbook, no per-cell object graph, no SQLite cell cache.
 *           Excel's 1,048,576-rows-per-sheet ceiling forces CustomerList_1,
 *           CustomerList_2, ... — see XlsxRowWriter.
 *
 * Both writers take only a filesystem path, never the HTTP response, so the
 * whole pipeline can move behind a queued background job later without
 * touching the writers.
 */
class CustomerListExportService
{
    /** The source table — single source of truth, reused by QueryBuilder. */
    public const SQL_TABLE = 'CMS_RFC.TB_CUSTOMERS_LIST';

    /**
     * The fixed 25-column SELECT (no WHERE) — QueryBuilder appends the shared
     * filter clause.
     *
     * NIU_TO_RECLASS and UPDATED_AT (2026-09 addition) are selected bare, no
     * alias — confirmed as the real column names in CMS_RFC.TB_CUSTOMERS_LIST.
     * Note NIU_TO_RECLASS uses the "NIU" letter order, same as the free-text
     * "NUI ... RECLASSER" value already carried by NUI_QC but NOT the same
     * order as the NUI_QC column name itself — this project's source schema
     * is not internally consistent about "NIU" vs "NUI", so don't assume one
     * implies the other; if this ever raises ORA-00904, double-check the
     * spelling against Oracle's data dictionary before guessing.
     *
     * METER (2026-09-17 diagnostic, DASH-20260917-96667): the previous
     * DASH-20260916-31939 comment claimed the column had been renamed to
     * METER_TECHNOLOGY and aliased it back to METER here — that column does
     * not exist in CMS_RFC.TB_CUSTOMERS_LIST (ORA-00904 on every export and
     * every dashboard endpoint). Re-verified live against ALL_TAB_COLUMNS on
     * 2026-09-17: the real column is the bare METER, unchanged. Selected
     * without alias, same as every other bare column here.
     */
    public const SQL_SELECT = <<<'SQL'
        SELECT
            REGION,
            DIVISION,
            AGENCE,
            COD_UNICOM,
            COD_CLI,
            CONTRACT,
            STATUS,
            METER_NO,
            CUST_NAME,
            PHONE_NUMBERS,
            E_MAIL,
            REF_GEO,
            DATE_AB,
            DATE_RESILIATION,
            VOLTAGE,
            SEGMENT_TRESOR,
            METER,
            NIU_RIGHT,
            NIU_TO_RECLASS,
            NUI_QC,
            LAST_VC_DATE,
            SEGMENT_RFM_2,
            POSTPAID_PROFILE_DATE,
            SEGMENTATION,
            UPDATED_AT
        FROM CMS_RFC.TB_CUSTOMERS_LIST
        SQL;

    /** @var list<string> Fixed column order — identical for CSV and XLSX. */
    public const COLUMNS = [
        'REGION', 'DIVISION', 'AGENCE', 'COD_UNICOM', 'COD_CLI', 'CONTRACT', 'STATUS',
        'METER_NO', 'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'REF_GEO', 'DATE_AB',
        'DATE_RESILIATION', 'VOLTAGE', 'SEGMENT_TRESOR', 'METER', 'NIU_RIGHT', 'NIU_TO_RECLASS', 'NUI_QC',
        'LAST_VC_DATE', 'SEGMENT_RFM_2', 'POSTPAID_PROFILE_DATE', 'SEGMENTATION', 'UPDATED_AT',
    ];

    private const LOG_CHECKPOINT_EVERY = 100_000;

    /**
     * Generated files older than this are swept before each export. Covers
     * both orphans from an interrupted/crashed export AND completed
     * asynchronous export-job files the requester never came back to
     * download — 24h gives a comfortable window for the latter while still
     * bounding disk use. A job whose file has been swept reports "expiré" on
     * download (ExportJobController).
     */
    private const ORPHAN_MAX_AGE_SECONDS = 24 * 3600;

    private OracleExtractionService $oracle;
    private OracleConfig $config;
    private QueryBuilder $queryBuilder;
    private string $exportDir;

    public function __construct(
        ?OracleExtractionService $oracle = null,
        ?OracleConfig $config = null,
        ?string $exportDir = null,
        ?QueryBuilder $queryBuilder = null,
    ) {
        $this->config       = $config ?? new OracleConfig();
        $this->oracle       = $oracle ?? new OracleExtractionService($this->config);
        $this->queryBuilder = $queryBuilder ?? new QueryBuilder($this->config);
        $this->exportDir    = rtrim($exportDir ?? WRITEPATH . 'uploads/exports', '/\\') . DIRECTORY_SEPARATOR;

        if (! is_dir($this->exportDir) && ! mkdir($this->exportDir, 0755, true) && ! is_dir($this->exportDir)) {
            throw new RuntimeException("Impossible de créer le répertoire d'export {$this->exportDir}.");
        }

        $this->sweepOrphanFiles();
    }

    /**
     * @return array{path:string, filename:string, format:string, rows:int, sheets:int,
     *     fileSize:int, sqlDurationMs:float, fetchDurationMs:float, writeDurationMs:float,
     *     totalDurationMs:float, peakMemoryMb:float}
     */
    public function exportCsv(?FilterCriteria $filters = null): array
    {
        // The whole operation's PHP time budget is decided here, once, at the
        // top level — a finite ceiling (not set_time_limit(0)), never
        // silently reduced by a lower layer. See OracleExtractionService
        // for why raise-only matters.
        OracleExtractionService::ensurePhpTimeLimitAtLeast($this->config->exportTimeLimitSeconds);

        $statement = $this->queryBuilder->exportStatement($filters ?? FilterCriteria::none());

        $filename = $this->buildFilename('csv');
        $path     = $this->exportDir . $filename;

        $t0 = microtime(true);

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Impossible de créer le fichier d'export CSV.");
        }

        $writer      = new CsvRowWriter($handle, self::COLUMNS);
        $firstRowAt  = null;
        $written     = 0;
        $writeTime   = 0.0;

        try {
            $rowCount = $this->oracle->stream($statement['sql'], function (array $row) use ($writer, &$written, &$firstRowAt, &$writeTime, $t0): void {
                if ($firstRowAt === null) {
                    $firstRowAt = microtime(true);
                }

                $tw = microtime(true);
                $writer->writeRow($row);
                $writeTime += microtime(true) - $tw;

                $this->logCheckpoint('csv', ++$written, 1, $t0);
            }, $statement['binds'], prefetch: $this->config->exportPrefetchRows);
        } catch (\Throwable $e) {
            fclose($handle);
            // Don't leave a header-only (or partially written) file behind
            // for a failed attempt — the controller never gets to request a
            // download for it, so nothing else will clean it up before the
            // next export's orphan sweep, up to two hours later.
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        $tEnd = microtime(true);

        return $this->buildMeta($path, $filename, 'csv', $rowCount, 1, [
            'sql'   => ($firstRowAt ?? $tEnd) - $t0,
            'fetch' => ($tEnd - ($firstRowAt ?? $tEnd)) - $writeTime,
            'write' => $writeTime,
            'total' => $tEnd - $t0,
        ]);
    }

    /**
     * @return array{path:string, filename:string, format:string, rows:int, sheets:int,
     *     fileSize:int, sqlDurationMs:float, fetchDurationMs:float, writeDurationMs:float,
     *     totalDurationMs:float, peakMemoryMb:float}
     */
    public function exportXlsx(?FilterCriteria $filters = null): array
    {
        OracleExtractionService::ensurePhpTimeLimitAtLeast($this->config->exportTimeLimitSeconds);

        $statement = $this->queryBuilder->exportStatement($filters ?? FilterCriteria::none());

        $filename = $this->buildFilename('xlsx');
        $path     = $this->exportDir . $filename;

        $t0 = microtime(true);

        try {
            $writer = new XlsxRowWriter(
                $path,
                self::COLUMNS,
                $this->config->xlsxMaxRowsPerSheet,
                'CustomerList',
                rtrim($this->exportDir, '/\\'),
            );
        } catch (\Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw $e;
        }

        $firstRowAt = null;
        $written    = 0;
        $writeTime  = 0.0; // Time spent handing rows to the writer, measured inside the fetch loop.
        $saveTime   = 0.0; // Time spent finalising the zip (writer->close()), measured after the loop.

        try {
            $rowCount = $this->oracle->stream($statement['sql'], function (array $row) use ($writer, &$written, &$firstRowAt, &$writeTime, $t0): void {
                if ($firstRowAt === null) {
                    $firstRowAt = microtime(true);
                }

                $tw = microtime(true);
                $writer->writeRow($row);
                $writeTime += microtime(true) - $tw;

                $this->logCheckpoint('xlsx', ++$written, $writer->sheetCount(), $t0);
            }, $statement['binds'], prefetch: $this->config->exportPrefetchRows);

            $tFetchEnd = microtime(true);

            $writer->close();
            $saveTime = microtime(true) - $tFetchEnd;
        } catch (\Throwable $e) {
            // Release the writer's file handles (best effort), then drop the
            // partial workbook — same rationale as exportCsv().
            try {
                $writer->close();
            } catch (\Throwable) {
                // ignore — we are already unwinding a failure
            }
            if (is_file($path)) {
                @unlink($path);
            }

            throw $e;
        }

        $sheets = $writer->sheetCount();
        $tEnd   = microtime(true);

        return $this->buildMeta($path, $filename, 'xlsx', $rowCount, $sheets, [
            'sql'   => ($firstRowAt ?? $tEnd) - $t0,
            'fetch' => ($tFetchEnd - ($firstRowAt ?? $tFetchEnd)) - $writeTime,
            'write' => $writeTime + $saveTime,
            'total' => $tEnd - $t0,
        ]);
    }

    private function logCheckpoint(string $format, int $rowsSoFar, int $sheets, float $t0): void
    {
        if ($rowsSoFar % self::LOG_CHECKPOINT_EVERY !== 0) {
            return;
        }

        log_message('info', '[EXPORT] checkpoint format={format} rows={rows} sheets={sheets} elapsed_s={elapsed} mem_peak_mb={memPeak}', [
            'format'  => $format,
            'rows'    => $rowsSoFar,
            'sheets'  => $sheets,
            'elapsed' => round(microtime(true) - $t0, 1),
            'memPeak' => round(memory_get_peak_usage(true) / 1048576, 2),
        ]);
    }

    /**
     * @param array{sql: float, fetch: float, write: float, total: float} $durations Seconds.
     *
     * @return array{path:string, filename:string, format:string, rows:int, sheets:int,
     *     fileSize:int, sqlDurationMs:float, fetchDurationMs:float, writeDurationMs:float,
     *     totalDurationMs:float, peakMemoryMb:float}
     */
    private function buildMeta(string $path, string $filename, string $format, int $rows, int $sheets, array $durations): array
    {
        $fileSize = is_file($path) ? filesize($path) : 0;

        $meta = [
            'path'            => $path,
            'filename'        => $filename,
            'format'          => $format,
            'rows'            => $rows,
            'sheets'          => $sheets,
            'fileSize'        => $fileSize,
            'sqlDurationMs'   => round(max(0, $durations['sql']) * 1000, 1),
            'fetchDurationMs' => round(max(0, $durations['fetch']) * 1000, 1),
            'writeDurationMs' => round(max(0, $durations['write']) * 1000, 1),
            'totalDurationMs' => round(max(0, $durations['total']) * 1000, 1),
            'peakMemoryMb'    => round(memory_get_peak_usage(true) / 1048576, 2),
        ];

        $rowsPerSecond = $durations['total'] > 0 ? round($rows / $durations['total']) : 0;

        log_message('info', '[EXPORT] completed format={format} rows={rows} sheets={sheets} rows_per_s={rps} total_ms={total} sql_ms={sql} write_ms={write} file_size={size} peak_memory_mb={mem}', [
            'format' => $format,
            'rows'   => $rows,
            'sheets' => $sheets,
            'rps'    => $rowsPerSecond,
            'total'  => $meta['totalDurationMs'],
            'sql'    => $meta['sqlDurationMs'],
            'write'  => $meta['writeDurationMs'],
            'size'   => $fileSize,
            'mem'    => $meta['peakMemoryMb'],
        ]);

        return $meta;
    }

    private function buildFilename(string $extension): string
    {
        return sprintf('customer_list_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $extension);
    }

    /**
     * Best-effort cleanup for temp artefacts left behind by a crashed or
     * interrupted export (normal completions delete their own files right
     * after the download — see ExportJobController). Runs before
     * every new export rather than on a cron, keeping this self-contained.
     *
     * Covers both the generated output files (customer_list_*.csv/.xlsx) and
     * OpenSpout's own scratch folders (xlsx<uniqid>/), which it writes into
     * this same directory and normally removes itself on close().
     */
    private function sweepOrphanFiles(): void
    {
        $cutoff = time() - self::ORPHAN_MAX_AGE_SECONDS;

        foreach (glob($this->exportDir . 'customer_list_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }

        foreach (glob($this->exportDir . 'xlsx*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $cutoff) {
                $this->deleteDirectory($dir);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
