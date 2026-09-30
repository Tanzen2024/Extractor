<?php

namespace App\Services;

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Export\CsvRowWriter;
use App\Services\Export\ExportCancelledException;
use App\Services\Export\ReportsProgress;
use App\Services\Export\ReportsStreamStats;
use App\Services\Export\OracleRowSource;
use App\Services\Export\RowSource;
use App\Services\Export\XlsxRowWriter;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotStore;
use Config\Oracle as OracleConfig;
use Config\Snapshot as SnapshotConfig;
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
 * Rows come from a RowSource (see the constructor): by default the local
 * validated snapshot (SnapshotRowSource — no Oracle at all, filters applied
 * by RowMatcher, the PHP twin of QueryBuilder::where()), or the historical
 * live Oracle SELECT (OracleRowSource) when Config\Snapshot::$exportSource is
 * 'oracle'. Either way rows stream one by one straight into an open
 * file/writer, so PHP's memory stays flat regardless of row count:
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
     * CSV rows are copied to the file in ~1 MB chunks instead of one write()
     * per row (see CsvRowWriter) — same bytes, measured 1.75x faster writes.
     */
    private const CSV_WRITE_BUFFER_BYTES = 1 << 20;

    /**
     * Upper bound on waiting for this export's (already emptied) OpenSpout
     * scratch folder to disappear — see removeScratchDir().
     */
    private const SCRATCH_RMDIR_BUDGET_MS = 5_000;

    /**
     * Generated files older than this are swept before each export. Covers
     * both orphans from an interrupted/crashed export AND completed
     * asynchronous export-job files the requester never came back to
     * download — 24h gives a comfortable window for the latter while still
     * bounding disk use. A job whose file has been swept reports "expiré" on
     * download (ExportJobController).
     */
    private const ORPHAN_MAX_AGE_SECONDS = 24 * 3600;

    /**
     * OpenSpout scratch folders still present after this long belong to an
     * export that died without reaching its own cleanup (fatal error, PHP
     * timeout, killed worker). Far above exportTimeLimitSeconds, so a folder
     * still in use by a running export is never swept.
     */
    private const TEMP_ORPHAN_MAX_AGE_SECONDS = 6 * 3600;

    private RowSource $rowSource;
    private OracleConfig $config;

    /** Final export files only (customer_list_*.csv/.xlsx). */
    private string $exportDir;

    /** Parent of the per-export OpenSpout scratch folders — never $exportDir. */
    private string $openSpoutTempDir;

    /**
     * Row source resolution:
     *   1. $rowSource if given;
     *   2. else an explicitly injected $oracle / $queryBuilder -> Oracle
     *      (callers that deliberately target Oracle, e.g. export:bench);
     *   3. else Config\Snapshot::$exportSource: 'snapshot' (default) -> the
     *      active local snapshot, 'oracle' -> live Oracle.
     * With 'snapshot' and no valid snapshot, this throws
     * SnapshotUnavailableException — there is no silent Oracle fallback.
     */
    public function __construct(
        ?OracleExtractionService $oracle = null,
        ?OracleConfig $config = null,
        ?string $exportDir = null,
        ?QueryBuilder $queryBuilder = null,
        ?string $openSpoutTempDir = null,
        ?RowSource $rowSource = null,
        ?SnapshotConfig $snapshotConfig = null,
    ) {
        $this->config           = $config ?? new OracleConfig();
        $snapshotConfig       ??= new SnapshotConfig();
        $this->rowSource        = $rowSource ?? (
            $oracle !== null || $queryBuilder !== null || ! $snapshotConfig->usesSnapshot()
                ? new OracleRowSource($oracle, $this->config, $queryBuilder)
                : new SnapshotRowSource(store: new SnapshotStore($snapshotConfig))
        );
        $this->exportDir        = rtrim($exportDir ?? WRITEPATH . 'uploads/exports', '/\\') . DIRECTORY_SEPARATOR;
        $this->openSpoutTempDir = rtrim($openSpoutTempDir ?? WRITEPATH . 'tmp/openspout', '/\\') . DIRECTORY_SEPARATOR;

        if (! is_dir($this->exportDir) && ! mkdir($this->exportDir, 0755, true) && ! is_dir($this->exportDir)) {
            throw new RuntimeException("Impossible de créer le répertoire d'export {$this->exportDir}.");
        }

        $this->sweepOrphanFiles();
    }

    /**
     * @return array{path:string, filename:string, format:string, rows:int, sheets:int,
     *     fileSize:int, sqlDurationMs:float, fetchDurationMs:float, writeDurationMs:float,
     *     totalDurationMs:float, peakMemoryMb:float}
     *
     * @param (callable(int $processed, int $total, int $exported): void)|null $onProgress
     *     Scan progress (see Export\ReportsProgress), batched — never per row.
     *     Only sources implementing ReportsProgress (the snapshot) report it;
     *     with any other source it is simply never called.
     */
    public function exportCsv(?FilterCriteria $filters = null, ?callable $onProgress = null): array
    {
        // The whole operation's PHP time budget is decided here, once, at the
        // top level — a finite ceiling (not set_time_limit(0)), never
        // silently reduced by a lower layer. See OracleExtractionService
        // for why raise-only matters.
        OracleExtractionService::ensurePhpTimeLimitAtLeast($this->config->exportTimeLimitSeconds);

        $filters ??= FilterCriteria::none();

        $filename = $this->buildFilename('csv');
        $path     = $this->exportDir . $filename;
        // Rows go to <final>.tmp; the final name only ever appears, by an
        // atomic rename, once the file is complete, flushed, closed and
        // checked — a download URL or export job can never point at a
        // partial file. On any failure the .tmp is deleted.
        $tmpPath  = $path . '.tmp';
        $source   = $this->rowSource->label();
        $filtersLog = json_encode($filters->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        log_message('info', '[CSV EXPORT] started export={export} source={source} filters={filters}', [
            'export' => $filename, 'source' => $source, 'filters' => $filtersLog,
        ]);

        $t0         = microtime(true);
        $handle     = null;
        $firstRowAt = null;
        $written    = 0;
        $writeTime  = 0.0;

        try {
            $handle = @fopen($tmpPath, 'wb');
            if ($handle === false) {
                $handle = null;

                throw new RuntimeException("Impossible de créer le fichier d'export CSV.");
            }

            $writer = new CsvRowWriter($handle, self::COLUMNS, true, self::CSV_WRITE_BUFFER_BYTES);

            $reportsProgress = $onProgress !== null && $this->rowSource instanceof ReportsProgress;
            if ($reportsProgress) {
                $this->rowSource->setProgressListener($onProgress);
            }

            try {
                $rowCount = $this->rowSource->stream($filters, function (array $row) use ($writer, &$written, &$firstRowAt, &$writeTime, $t0): void {
                    if ($firstRowAt === null) {
                        $firstRowAt = microtime(true);
                    }

                    $tw = microtime(true);
                    $writer->writeRow($row);
                    $writeTime += microtime(true) - $tw;

                    $this->logCheckpoint('csv', ++$written, 1, $t0);
                });
            } finally {
                if ($reportsProgress) {
                    $this->rowSource->setProgressListener(null);
                }
            }

            $tw = microtime(true);
            $writer->flush();
            $flushed = fflush($handle);
            $writeTime += microtime(true) - $tw;

            $closed = fclose($handle);
            $handle = null;

            if (! $flushed || ! $closed) {
                throw new RuntimeException("Finalisation du fichier d'export CSV impossible (disque plein ?).");
            }
            if ($written !== $rowCount) {
                throw new RuntimeException("Export CSV incohérent : {$written} lignes écrites pour {$rowCount} lignes retenues.");
            }

            clearstatcache(true, $tmpPath);
            if ((int) @filesize($tmpPath) === 0) {
                throw new RuntimeException("Fichier d'export CSV vide après écriture.");
            }
            if (! @rename($tmpPath, $path)) {
                throw new RuntimeException("Publication du fichier d'export CSV impossible.");
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            @unlink($tmpPath);

            if ($e instanceof ExportCancelledException) {
                log_message('info', '[CSV EXPORT] cancelled export={export} source={source} rows_exported_before_cancel={rows} elapsed_ms={ms} partial_file_deleted={deleted}', [
                    'export'  => $filename,
                    'source'  => $source,
                    'rows'    => $written,
                    'ms'      => round((microtime(true) - $t0) * 1000),
                    'deleted' => is_file($tmpPath) ? 'no' : 'yes',
                ]);

                throw $e;
            }

            log_message('error', '[CSV EXPORT] failed export={export} source={source} filters={filters} rows_exported_before_failure={rows} elapsed_ms={ms} error={error}', [
                'export'  => $filename,
                'source'  => $source,
                'filters' => $filtersLog,
                'rows'    => $written,
                'ms'      => round((microtime(true) - $t0) * 1000),
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }

        $tEnd  = microtime(true);
        $stats = $this->rowSource instanceof ReportsStreamStats ? $this->rowSource->lastStreamStats() : null;

        $meta = $this->buildMeta($path, $filename, 'csv', $rowCount, 1, [
            'sql'   => ($firstRowAt ?? $tEnd) - $t0,
            'fetch' => ($tEnd - ($firstRowAt ?? $tEnd)) - $writeTime,
            'write' => $writeTime,
            'total' => $tEnd - $t0,
        ]);

        // Snapshot: the source reports its own read/filter split. Oracle:
        // the database filters, so every fetched row was a matching row.
        $meta['rowsRead']         = $stats['rows_read'] ?? $rowCount;
        $meta['rowsMatched']      = $stats['rows_matched'] ?? $rowCount;
        $meta['rowsExported']     = $written;
        $meta['readDurationMs']   = $stats !== null ? round($stats['read_s'] * 1000, 1) : $meta['fetchDurationMs'];
        $meta['filterDurationMs'] = $stats !== null ? round($stats['filter_s'] * 1000, 1) : 0.0;

        log_message('info', '[CSV EXPORT] completed export={export} source={source} filters={filters} rows_read={read} rows_matched={matched} rows_exported={exported} read_ms={readMs} filter_ms={filterMs} write_ms={writeMs} total_ms={totalMs} file_size={size} peak_memory_mb={mem}', [
            'export'   => $filename,
            'source'   => $source,
            'filters'  => $filtersLog,
            'read'     => $meta['rowsRead'],
            'matched'  => $meta['rowsMatched'],
            'exported' => $meta['rowsExported'],
            'readMs'   => $meta['readDurationMs'],
            'filterMs' => $meta['filterDurationMs'],
            'writeMs'  => $meta['writeDurationMs'],
            'totalMs'  => $meta['totalDurationMs'],
            'size'     => $meta['fileSize'],
            'mem'      => $meta['peakMemoryMb'],
        ]);

        return $meta;
    }

    /**
     * @return array{path:string, filename:string, format:string, rows:int, sheets:int,
     *     fileSize:int, sqlDurationMs:float, fetchDurationMs:float, writeDurationMs:float,
     *     totalDurationMs:float, peakMemoryMb:float}
     */
    public function exportXlsx(?FilterCriteria $filters = null): array
    {
        OracleExtractionService::ensurePhpTimeLimitAtLeast($this->config->exportTimeLimitSeconds);

        $filters ??= FilterCriteria::none();

        $filename = $this->buildFilename('xlsx');
        $path     = $this->exportDir . $filename;

        $t0 = microtime(true);

        // OpenSpout's scratch files go to a folder private to this export,
        // outside $exportDir, so final files and scratch never mix and this
        // export's cleanup can never touch a concurrent one's.
        $scratchDir = $this->createOpenSpoutScratchDir();

        try {
            try {
                $writer = new XlsxRowWriter(
                    $path,
                    self::COLUMNS,
                    $this->config->xlsxMaxRowsPerSheet,
                    'CustomerList',
                    $scratchDir,
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
                $rowCount = $this->rowSource->stream($filters, function (array $row) use ($writer, &$written, &$firstRowAt, &$writeTime, $t0): void {
                    if ($firstRowAt === null) {
                        $firstRowAt = microtime(true);
                    }

                    $tw = microtime(true);
                    $writer->writeRow($row);
                    $writeTime += microtime(true) - $tw;

                    $this->logCheckpoint('xlsx', ++$written, $writer->sheetCount(), $t0);
                });

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

            foreach ($writer->cleanupWarnings() as $warning) {
                log_message('warning', '[EXPORT] nettoyage temporaire OpenSpout: {warning}', ['warning' => $warning]);
            }
        } finally {
            // Reached only once the writer is closed (or never opened): OpenSpout
            // normally empties this folder itself on close(); this removes the
            // folder and whatever a failed close() left behind. Anything still
            // locked is picked up later by sweepOrphanFiles().
            $this->removeScratchDir($scratchDir);
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
            'source'          => $this->rowSource->label(),
        ];

        $rowsPerSecond = $durations['total'] > 0 ? round($rows / $durations['total']) : 0;

        log_message('info', '[EXPORT] completed source={source} format={format} rows={rows} sheets={sheets} rows_per_s={rps} total_ms={total} sql_ms={sql} write_ms={write} file_size={size} peak_memory_mb={mem}', [
            'format' => $format,
            'rows'   => $rows,
            'sheets' => $sheets,
            'rps'    => $rowsPerSecond,
            'total'  => $meta['totalDurationMs'],
            'sql'    => $meta['sqlDurationMs'],
            'write'  => $meta['writeDurationMs'],
            'size'   => $fileSize,
            'mem'    => $meta['peakMemoryMb'],
            'source' => $meta['source'],
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
     * Covers the generated output files (customer_list_*.csv/.xlsx) in
     * $exportDir, and the per-export OpenSpout scratch folders (export_*) in
     * $openSpoutTempDir that an export killed before its own finally block
     * left behind. The xlsx* glob on $exportDir only collects scratch folders
     * from before OpenSpout was moved out of it.
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

        $tempCutoff = time() - self::TEMP_ORPHAN_MAX_AGE_SECONDS;

        foreach (glob($this->openSpoutTempDir . 'export_*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $tempCutoff) {
                $this->deleteDirectory($dir);
            }
        }
    }

    /**
     * Creates (under $openSpoutTempDir) the scratch folder dedicated to one
     * XLSX export. Only called by exportXlsx(), so CSV exports never depend
     * on this directory.
     */
    private function createOpenSpoutScratchDir(): string
    {
        // @: a concurrent export may create the parent at the same moment.
        if (! is_dir($this->openSpoutTempDir) && ! @mkdir($this->openSpoutTempDir, 0755, true) && ! is_dir($this->openSpoutTempDir)) {
            throw new RuntimeException("Impossible de créer le répertoire temporaire OpenSpout {$this->openSpoutTempDir}.");
        }
        if (! is_writable($this->openSpoutTempDir)) {
            throw new RuntimeException("Le répertoire temporaire OpenSpout {$this->openSpoutTempDir} n'est pas accessible en écriture.");
        }

        $dir = $this->openSpoutTempDir . 'export_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0755)) {
            throw new RuntimeException("Impossible de créer le dossier temporaire d'export {$dir}.");
        }

        return $dir;
    }

    /**
     * Deletes this export's scratch folder, retrying briefly: on Windows the
     * multi-GB sheet files OpenSpout just unlinked can stay "delete pending"
     * while antivirus/EDR still holds them, so the first rmdir() fails even
     * though nothing is left to delete (observed 2026-09-27 on a full 3.3M-row
     * export: folder empty but still present). Bounded; a folder still there
     * is logged and left to sweepOrphanFiles(). Never throws (finally block).
     */
    private function removeScratchDir(string $dir): void
    {
        $waitedMs = 0;
        $delayMs  = 50;

        while (true) {
            $this->deleteDirectory($dir);
            clearstatcache(true, $dir);
            if (! is_dir($dir)) {
                return;
            }
            if ($waitedMs >= self::SCRATCH_RMDIR_BUDGET_MS) {
                break;
            }
            usleep($delayMs * 1000);
            $waitedMs += $delayMs;
            $delayMs   = min($delayMs * 2, 1000);
        }

        log_message('warning', '[EXPORT] dossier temporaire OpenSpout non supprimé après {ms} ms (fichier encore verrouillé par un autre processus ?) : {dir} — sera purgé par le balayage des orphelins', [
            'ms'  => $waitedMs,
            'dir' => $dir,
        ]);
    }

    private function deleteDirectory(string $dir): void
    {
        // Best effort, and called from finally blocks: never raise a warning
        // (CodeIgniter would turn it into an exception masking the real one).
        if (! is_dir($dir)) {
            return;
        }

        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
