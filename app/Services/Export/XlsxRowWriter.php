<?php

namespace App\Services\Export;

use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

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
    private Writer $writer;
    private int $sheetIndex = 0;
    private int $rowInSheet = 0;
    private bool $closed = false;

    /**
     * @param string       $path            Destination .xlsx path (created/overwritten).
     * @param list<string>  $columns         Column names, in the exact order to write them.
     * @param int          $maxRowsPerSheet Data rows per sheet before splitting to the next.
     * @param string       $sheetBaseName   Sheet name prefix ("CustomerList" => CustomerList_1, ...).
     * @param string|null  $tempFolder      Writable folder for OpenSpout's scratch files (defaults to the system temp dir).
     */
    public function __construct(
        string $path,
        private readonly array $columns,
        private readonly int $maxRowsPerSheet,
        private readonly string $sheetBaseName = 'CustomerList',
        ?string $tempFolder = null,
    ) {
        $options = new Options();
        if ($tempFolder !== null && is_dir($tempFolder)) {
            $options->setTempFolder($tempFolder);
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
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->writer->close();
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
}
