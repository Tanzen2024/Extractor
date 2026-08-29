<?php

namespace App\Services\Export;

/**
 * Writes CUSTOMERS_LIST rows to an already-open file handle as CSV.
 *
 * All quoting/escaping (embedded delimiters, double quotes, embedded
 * newlines) is delegated to fputcsv() — never manual string concatenation —
 * so a value like `Dupont; "Le Grand"` in CUST_NAME can never corrupt the
 * file structure. Every row is written and the handle is flushed to disk
 * immediately; nothing is buffered in a PHP array.
 */
class CsvRowWriter
{
    private const DELIMITER = ';';
    private const ENCLOSURE = '"';
    private const ESCAPE    = '\\';

    /** @var resource */
    private $handle;

    /** @var list<string> */
    private array $columns;

    /**
     * @param resource      $handle   Open, writable file handle.
     * @param list<string>  $columns  Column names, in the exact order to write them.
     */
    public function __construct($handle, array $columns, bool $writeBom = true)
    {
        $this->handle  = $handle;
        $this->columns = $columns;

        if ($writeBom) {
            // Excel (especially fr-FR builds) only reliably auto-detects
            // UTF-8 CSV when a BOM is present; without it, accented
            // characters (CUST_NAME, AGENCE, ...) render as mojibake.
            fwrite($this->handle, "\xEF\xBB\xBF");
        }

        fputcsv($this->handle, $this->columns, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
    }

    /**
     * @param array<string, mixed> $row Associative row keyed by column name, as returned by oci_fetch_assoc().
     */
    public function writeRow(array $row): void
    {
        $line = [];

        foreach ($this->columns as $column) {
            $value  = $row[$column] ?? null;
            $line[] = $value === null ? '' : (string) $value;
        }

        fputcsv($this->handle, $line, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
    }
}
