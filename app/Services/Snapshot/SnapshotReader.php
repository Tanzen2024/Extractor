<?php

namespace App\Services\Snapshot;

/**
 * Streams a customers_list.csv snapshot one record at a time.
 *
 * Record format (validated for every line at install, see SnapshotValidator):
 * one record per physical line, fields separated by the manifest's single
 * delimiter character ('#'), no enclosure, UTF-8. A field can therefore never
 * contain the delimiter or a newline, so explode() is an exact parser — and
 * much cheaper than fgetcsv() over millions of lines.
 *
 * Memory is flat: only the current line is held. The whole file is never
 * loaded (no file(), no file_get_contents()).
 */
final class SnapshotReader
{
    private const READ_BUFFER = 1 << 20;

    /** @var resource */
    private $handle;

    /** @var list<string> */
    private array $header;

    /** @var array<string, int> */
    private array $index;

    private int $lineNo = 1;

    /** @var array<int, array<string, string>> field position => trimmed value => new value */
    private array $translations = [];

    private ?int $derivedField = null;
    private ?int $derivedFrom  = null;

    /** @var array<string, string> */
    private array $derivation = [];

    public function __construct(private readonly string $path, private readonly string $delimiter)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new SnapshotException('snapshot_unreadable', 'Fichier snapshot illisible.');
        }
        stream_set_read_buffer($handle, self::READ_BUFFER);

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);

            throw new SnapshotException('snapshot_empty', 'Fichier snapshot vide (aucun en-tête).');
        }

        $this->handle = $handle;
        $this->header = self::split(SnapshotManifest::stripBom(rtrim($first, "\r\n")), $delimiter);
        $this->index  = self::indexOf($this->header);
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return list<string> */
    public function header(): array
    {
        return $this->header;
    }

    /** @return array<string, int> Column name => field position. */
    public function index(): array
    {
        return $this->index;
    }

    /**
     * Records are delivered with this column's values replaced through
     * $translation (trimmed value => new value). The file is not changed.
     *
     * @param array<string, string> $translation
     */
    public function translate(string $column, array $translation): self
    {
        if (isset($this->index[$column])) {
            $this->translations[$this->index[$column]] = $translation;
        }

        return $this;
    }

    /**
     * Records also carry $column, filled from the file's $from value through
     * $map when the file holds no value for it (trimmed value => new value,
     * '' otherwise). A column the file lacks is added after its last field,
     * to index() only: header() stays the file's. Reads $from before
     * translate() replaces it. The file is not changed.
     *
     * @param array<string, string> $map
     */
    public function derive(string $column, string $from, array $map): self
    {
        $this->derivedField   = $this->index[$column] ?? count($this->header);
        $this->derivedFrom    = $this->index[$from] ?? null;
        $this->derivation     = $map;
        $this->index[$column] = $this->derivedField;

        return $this;
    }

    /**
     * Calls $onRecord(list<string> $fields) for every data record, in file
     * order; stops early when it returns false. Returns the number of
     * records read.
     *
     * @param callable(list<string>): (bool|null) $onRecord
     */
    public function each(callable $onRecord): int
    {
        $expected = count($this->header);
        $read     = 0;

        while (($line = fgets($this->handle)) !== false) {
            $this->lineNo++;
            $fields = explode($this->delimiter, rtrim($line, "\r\n"));

            if (count($fields) !== $expected) {
                throw new SnapshotException('snapshot_corrupt', sprintf(
                    'Ligne %d du snapshot : %d champs au lieu de %d.',
                    $this->lineNo,
                    count($fields),
                    $expected,
                ));
            }

            if ($this->derivedField !== null && trim($fields[$this->derivedField] ?? '') === '') {
                $fields[$this->derivedField] = $this->derivedFrom !== null ? ($this->derivation[trim($fields[$this->derivedFrom])] ?? '') : '';
            }

            foreach ($this->translations as $field => $translation) {
                $fields[$field] = $translation[trim($fields[$field])] ?? $fields[$field];
            }

            $read++;
            if ($onRecord($fields) === false) {
                return $read;
            }
        }

        if (! feof($this->handle)) {
            throw new SnapshotException('snapshot_read_error', "Erreur de lecture du snapshot après la ligne {$this->lineNo}.");
        }

        return $read;
    }

    public function close(): void
    {
        if (is_resource($this->handle ?? null)) {
            fclose($this->handle);
        }
    }

    /** @return list<string> */
    public static function split(string $line, string $delimiter): array
    {
        return explode($delimiter, $line);
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int>
     */
    public static function indexOf(array $header): array
    {
        $index = [];
        foreach ($header as $i => $name) {
            $index[strtoupper(trim($name))] = $i;
        }

        return $index;
    }
}
