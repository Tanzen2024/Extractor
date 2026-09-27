<?php

namespace App\Services\Export;

use RuntimeException;

/**
 * Writes CUSTOMERS_LIST rows to an already-open file handle as CSV.
 *
 * All quoting/escaping (embedded delimiters, double quotes, embedded
 * newlines) is delegated to fputcsv() — never manual string concatenation —
 * so a value like `Dupont; "Le Grand"` in CUST_NAME can never corrupt the
 * file structure. Nothing is ever held in a PHP array.
 *
 * By default every row goes straight to the handle. With $bufferBytes > 0,
 * rows are formatted by the same fputcsv() into a bounded in-memory stream
 * and copied to the handle in chunks of about that size: a plain-file
 * handle has no write buffering in PHP, so the default costs one write()
 * system call per row (measured 2026-09-27: 137 s of a 249 s full
 * 3.3M-row export on the dev box). The bytes produced are identical; the
 * caller must then call flush() before closing the handle.
 */
class CsvRowWriter
{
    private const DELIMITER = ';';
    private const ENCLOSURE = '"';
    private const ESCAPE    = '\\';

    /** @var resource */
    private $handle;

    /** @var resource|null In-memory staging stream when buffering, else null. */
    private $buffer;

    /** @var list<string> */
    private array $columns;

    /**
     * @param resource      $handle      Open, writable file handle.
     * @param list<string>  $columns     Column names, in the exact order to write them.
     * @param int           $bufferBytes 0 = write every row immediately; > 0 = copy to
     *                                   $handle in chunks of about this size (call flush()).
     */
    public function __construct($handle, array $columns, bool $writeBom = true, private readonly int $bufferBytes = 0)
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

        if ($this->bufferBytes > 0) {
            $this->buffer = fopen('php://memory', 'w+b');
        }
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

        fputcsv($this->buffer ?? $this->handle, $line, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);

        if ($this->buffer !== null && ftell($this->buffer) >= $this->bufferBytes) {
            $this->flush();
        }
    }

    /**
     * Copies buffered rows to the handle. No-op when not buffering.
     *
     * @throws RuntimeException when the handle accepts fewer bytes (disk full).
     */
    public function flush(): void
    {
        if ($this->buffer === null) {
            return;
        }

        $pending = ftell($this->buffer);
        if ($pending === 0) {
            return;
        }

        rewind($this->buffer);
        $copied = stream_copy_to_stream($this->buffer, $this->handle);
        ftruncate($this->buffer, 0);
        rewind($this->buffer);

        if ($copied !== $pending) {
            throw new RuntimeException("Écriture CSV incomplète ({$copied}/{$pending} octets) — disque plein ?");
        }
    }
}
