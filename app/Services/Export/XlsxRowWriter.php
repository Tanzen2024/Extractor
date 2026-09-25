<?php

namespace App\Services\Export;

use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Streams CUSTOMERS_LIST rows straight into an .xlsx file via OpenSpout,
 * one row at a time — no in-memory workbook, no per-cell object graph, no
 * SQLite cell cache. Memory stays flat (O(1) per row) regardless of the row
 * count, which is what makes a multi-million-row export viable at all;
 * PhpSpreadsheet was measured at ~341 rows/s plus an 86s save() for only
 * 10k rows (hours for the full ~3.28M) and is no longer on this path.
 *
 * Sheets are split automatically into CustomerList_1, CustomerList_2, ...
 * once $maxRowsPerSheet data rows have been written to the current one —
 * Excel itself refuses more than 1,048,576 rows (header included) per sheet.
 *
 * Every value is written as an explicit string cell (never left to type
 * auto-detection): a long numeric CONTRACT/COD_CLI/METER_NO or a
 * leading-zero PHONE_NUMBERS must survive verbatim, and a value that happens
 * to start with '=' must never be treated as a formula. Empty/NULL becomes
 * an empty cell. This keeps the XLSX byte-for-byte comparable to the CSV
 * export for the same rows.
 *
 * HTTP-agnostic by design: it is handed a filesystem path and nothing else,
 * so the same writer can later run inside a queued background job.
 */
class XlsxRowWriter
{
    /**
     * Upper bound on how long one close() waits for an external process to
     * release a file OpenSpout has already unlinked — see close(). Only ever
     * spent on that failure path.
     */
    private const RMDIR_RETRY_BUDGET_MS = 10_000;

    private Writer $writer;
    private int $sheetIndex = 0;
    private int $rowInSheet = 0;
    private bool $closed = false;
    private ?string $tempFolder = null;

    /** @var list<string> Scratch-folder rmdir() failures tolerated during close(). */
    private array $cleanupWarnings = [];

    /**
     * @param string       $path            Destination .xlsx path (created/overwritten).
     * @param list<string>  $columns         Column names, in the exact order to write them.
     * @param int          $maxRowsPerSheet Data rows per sheet before splitting to the next.
     * @param string       $sheetBaseName   Sheet name prefix ("CustomerList" => CustomerList_1, ...).
     * @param string|null  $tempFolder      Writable folder for OpenSpout's scratch files (defaults to the
     *                                      system temp dir). Must be dedicated to this writer: close() treats
     *                                      everything under it as this writer's own scratch.
     */
    public function __construct(
        private readonly string $path,
        private readonly array $columns,
        private readonly int $maxRowsPerSheet,
        private readonly string $sheetBaseName = 'CustomerList',
        ?string $tempFolder = null,
    ) {
        $options = new Options();
        if ($tempFolder !== null) {
            // Fail loudly rather than silently falling back to the system
            // temp dir: the caller chose this folder on purpose.
            if (! is_dir($tempFolder) || ! is_writable($tempFolder)) {
                throw new RuntimeException("Dossier temporaire OpenSpout inaccessible en écriture : {$tempFolder}");
            }
            $this->tempFolder = rtrim(realpath($tempFolder) ?: $tempFolder, '/\\');
            $options->setTempFolder($this->tempFolder);
        }
        // Inline strings (the default) keep memory flat: each string is
        // written into the sheet XML as it arrives instead of being held in
        // a workbook-wide shared-strings table until close().
        $options->SHOULD_USE_INLINE_STRINGS = true;

        $this->writer = new Writer($options);
        $this->writer->openToFile($path);

        $this->startSheet();
    }

    /**
     * @param array<string, mixed> $row Associative row keyed by column name, as returned by oci_fetch_assoc().
     */
    public function writeRow(array $row): void
    {
        if ($this->rowInSheet >= $this->maxRowsPerSheet) {
            $this->startSheet();
        }

        $cells = [];
        foreach ($this->columns as $column) {
            $value    = $row[$column] ?? null;
            $cells[]  = ($value === null || $value === '')
                ? new EmptyCell(null, null)
                : new StringCell((string) $value, null);
        }

        $this->writer->addRow(new Row($cells));
        $this->rowInSheet++;
    }

    /**
     * Finalises the workbook (writes the zip central directory). Idempotent —
     * safe to call from both the happy path and a cleanup/finally block.
     *
     * Windows race handled here (EXP-20260924-12337): OpenSpout's close()
     * unlinks its scratch sheetN.xml files and immediately rmdir()s their
     * folder. When another process — typically antivirus/EDR scanning the
     * large file that was just written — still holds a handle opened with
     * FILE_SHARE_DELETE, unlink() succeeds but the entry stays listed
     * ("delete pending") until that handle is released, so rmdir() warns
     * "Directory not empty". OpenSpout ignores rmdir()'s return value, but
     * CodeIgniter turns the warning into an ErrorException that aborts
     * close() before the zip is written. The entry must also be gone before
     * the zip step, which walks the whole scratch tree and asserts realpath()
     * on each item (false for a delete-pending file).
     *
     * So, for the duration of close() only, rmdir() failures inside this
     * writer's own dedicated temp folder are intercepted:
     *   - worksheets-temp (the only folder removed before the zip step) is
     *     retried with a short backoff, bounded by RMDIR_RETRY_BUDGET_MS, until
     *     the external handle goes away; if it never does, the original
     *     warning is passed on and close() fails as it always did;
     *   - any other folder is only removed after the archive has been
     *     written, so its failure is recorded and ignored (the caller deletes
     *     the whole temp folder afterwards).
     * Whenever something was intercepted, the produced archive is checked
     * before being accepted. Any other warning — including a failed unlink()
     * — still goes to the previous handler untouched.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->tempFolder === null) {
            $this->writer->close();

            return;
        }

        $retryBudgetMs = self::RMDIR_RETRY_BUDGET_MS;
        $previous      = null;
        $previous      = set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) use (&$previous, &$retryBudgetMs): bool {
            if ($this->handleScratchRmdirFailure($message, $retryBudgetMs)) {
                return true;
            }

            return $previous !== null && (bool) $previous($severity, $message, $file, $line);
        });

        try {
            $this->writer->close();
        } catch (Throwable $e) {
            if ($this->cleanupWarnings !== []) {
                throw new RuntimeException(
                    'Finalisation XLSX impossible après verrouillage de fichiers temporaires OpenSpout par un autre processus (' . implode(' | ', $this->cleanupWarnings) . ')',
                    0,
                    $e,
                );
            }

            throw $e;
        } finally {
            restore_error_handler();
        }

        if ($this->cleanupWarnings !== []) {
            $this->assertArchiveIsComplete();
        }
    }

    /**
     * Scratch-folder rmdir() failures tolerated (and possibly recovered) during
     * close(), for the caller to log. Empty on a normal run.
     *
     * @return list<string>
     */
    public function cleanupWarnings(): array
    {
        return $this->cleanupWarnings;
    }

    /** Number of sheets created so far (>= 1). */
    public function sheetCount(): int
    {
        return $this->sheetIndex;
    }

    /** Total data rows written across every sheet (header rows excluded). */
    public function totalRowsWritten(): int
    {
        return ($this->sheetIndex - 1) * $this->maxRowsPerSheet + $this->rowInSheet;
    }

    private function startSheet(): void
    {
        $this->sheetIndex++;

        // OpenSpout always opens with one ready sheet — use it for the first
        // one rather than creating a spare that would need removing.
        $sheet = $this->sheetIndex === 1
            ? $this->writer->getCurrentSheet()
            : $this->writer->addNewSheetAndMakeItCurrent();

        $sheet->setName($this->sheetBaseName . '_' . $this->sheetIndex);

        $header = array_map(static fn (string $name) => new StringCell($name, null), $this->columns);
        $this->writer->addRow(new Row($header));

        $this->rowInSheet = 0;
    }

    /**
     * @return bool True when the warning was an rmdir() failure inside this
     *              writer's temp folder and has been dealt with; false hands
     *              it on to the previous handler.
     */
    private function handleScratchRmdirFailure(string $message, int &$retryBudgetMs): bool
    {
        if (preg_match('/^rmdir\((.+?)\): /s', $message, $m) !== 1) {
            return false;
        }

        $dir = realpath($m[1]);
        if ($dir === false || ! str_starts_with($dir, $this->tempFolder . DIRECTORY_SEPARATOR)) {
            return false;
        }

        // Every other scratch folder is removed by OpenSpout's final cleanup,
        // after the archive has been written: nothing to wait for, whatever
        // is left goes with the caller's own cleanup.
        if (basename($dir) !== 'worksheets-temp') {
            $this->cleanupWarnings[] = "{$message} — après écriture de l'archive, ignoré";

            return true;
        }

        // worksheets-temp is removed *before* the zip step, which must not
        // see it: wait (bounded) for the external handle to go away.
        $waitedMs = 0;
        $delayMs  = 50;
        while ($retryBudgetMs > 0) {
            $step = min($delayMs, $retryBudgetMs);
            usleep($step * 1000);
            $waitedMs      += $step;
            $retryBudgetMs -= $step;

            if (@rmdir($dir)) {
                $this->cleanupWarnings[] = "{$message} — libéré après {$waitedMs} ms";

                return true;
            }

            $delayMs = min($delayMs * 2, 1000);
        }

        // Still locked: let the original warning abort close() before the zip
        // step, exactly as without this handler.
        $this->cleanupWarnings[] = "{$message} — toujours verrouillé après {$waitedMs} ms";

        return false;
    }

    /**
     * Only reached when close() had to tolerate a scratch cleanup failure:
     * make sure the archive is a consistent zip holding a workbook and that
     * no scratch entry leaked into it.
     */
    private function assertArchiveIsComplete(): void
    {
        $zip    = new ZipArchive();
        $status = $zip->open($this->path, ZipArchive::CHECKCONS);
        if ($status !== true) {
            throw new RuntimeException("Archive XLSX invalide après finalisation (code ZipArchive {$status}).");
        }

        try {
            if ($zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('Archive XLSX incomplète : xl/workbook.xml absent.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_starts_with((string) $zip->getNameIndex($i), 'worksheets-temp/')) {
                    throw new RuntimeException('Archive XLSX invalide : fichiers temporaires OpenSpout inclus.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
