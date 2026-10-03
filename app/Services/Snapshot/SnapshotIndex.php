<?php

namespace App\Services\Snapshot;

use Config\Snapshot as SnapshotConfig;
use Throwable;

/**
 * The DuckDB query index of one snapshot version:
 *   versions/<id>/customers_list.duckdb   (table cl)
 *
 * Derived from — and only from — that version's validated CSV, never from
 * any other source; it is part of the version (built before the version is
 * activated, deleted with it) and never changes afterwards.
 *
 * Build (≈ 4 min for 3.3 M rows on the dev box, measured 2026-10-02):
 *   1. one PHP pass over the CSV normalises each record with the SAME code
 *      the exports use (SnapshotSort::record: filter columns trimmed as
 *      RowMatcher compares them, dates read by SnapshotDate with the
 *      version's format -> 'YYYY-MM-DD' or empty) into a temporary file —
 *      so the dashboard and the exports cannot disagree on a date or a
 *      value;
 *   2. DuckDB loads it into a temporary database (dates typed, blanks '',
 *      CONTRACT / COD_CLI numeric sort keys, rows in CONTRACT order);
 *   3. its row count must equal the version's validated row count;
 *   4. rename to the final name (atomic): a reader sees no index or a
 *      complete one.
 */
final class SnapshotIndex
{
    public const TABLE = 'cl';

    private SnapshotConfig $config;
    private DuckDb $duckdb;

    public function __construct(?SnapshotConfig $config = null, ?DuckDb $duckdb = null)
    {
        $this->config = $config ?? new SnapshotConfig();
        $this->duckdb = $duckdb ?? new DuckDb($this->config);
    }

    public function path(ActiveSnapshot $snapshot): string
    {
        return dirname($snapshot->csvPath) . DIRECTORY_SEPARATOR . $this->config->indexFileName;
    }

    public function exists(ActiveSnapshot $snapshot): bool
    {
        return is_file($this->path($snapshot));
    }

    /**
     * The dashboard engine of $snapshot — never another version's, never Oracle.
     *
     * @throws SnapshotUnavailableException when this version has no index yet
     */
    public function engine(ActiveSnapshot $snapshot): SnapshotDuckDbEngine
    {
        if (! $this->exists($snapshot)) {
            throw new SnapshotUnavailableException("Index de requête absent pour la version {$snapshot->id} (php spark snapshot:index).");
        }

        $info = json_decode((string) @file_get_contents(self::infoPath($this->path($snapshot))), true);

        return new SnapshotDuckDbEngine(
            $snapshot,
            $this->duckdb,
            $this->path($snapshot),
            // Only a fact verified for THIS version at build time; absent = the exact (slower) SQL.
            is_array($info) && ($info['version'] ?? null) === $snapshot->id && ($info['contract_unique_numeric'] ?? false) === true,
        );
    }

    /** Build facts published next to the index (written before it, read by engine()). */
    public static function infoPath(string $indexPath): string
    {
        return $indexPath . '.info.json';
    }

    /**
     * @return array{rows: int, seconds: float, size: int}
     *
     * @throws SnapshotException 'index_failed' — nothing is left behind
     */
    public function build(ActiveSnapshot $snapshot): array
    {
        $t0     = microtime(true);
        $final  = $this->path($snapshot);
        $tmpDb  = $final . '.tmp';
        $tmpCsv = $final . '.src.tmp';

        foreach ([$tmpDb, $tmpDb . '.wal', $tmpCsv] as $stale) {
            @unlink($stale);
        }

        try {
            $columns = $this->normalise($snapshot, $tmpCsv);
            $this->load($tmpCsv, $tmpDb, $columns, $snapshot->delimiter());
            @unlink($tmpCsv);

            $facts = $this->duckdb->query($tmpDb, 'SELECT COUNT(*) AS n,
                    COUNT(DISTINCT CONTRACT) AS distinct_contracts,
                    COUNT(*) FILTER (WHERE __contract_n IS NULL) AS non_numeric
                FROM ' . self::TABLE)[0] ?? [];
            $rows = (int) ($facts['n'] ?? -1);
            if ($rows !== $snapshot->rows()) {
                throw new SnapshotException('index_failed', "Index DuckDB incomplet : {$rows} lignes pour {$snapshot->rows()} attendues.");
            }

            // Facts of THIS version the engine may rely on: when every CONTRACT
            // is a distinct number, "distinct contracts" == "rows" and
            // CONTRACT alone is a total order (SnapshotDuckDbEngine).
            $info = [
                'version'                 => $snapshot->id,
                'rows'                    => $rows,
                'contract_unique_numeric' => (int) ($facts['distinct_contracts'] ?? -1) === $rows && (int) ($facts['non_numeric'] ?? -1) === 0,
                'duckdb'                  => $this->duckdb->version(),
                'built_at'                => date(DATE_ATOM),
            ];
            if (@file_put_contents($tmpDb . '.info', json_encode($info, JSON_PRETTY_PRINT)) === false) {
                throw new SnapshotException('index_failed', "Impossible d'écrire {$final}.info.json");
            }

            @unlink($final);
            @unlink(self::infoPath($final));
            if (! @rename($tmpDb . '.info', self::infoPath($final)) || ! @rename($tmpDb, $final)) {
                throw new SnapshotException('index_failed', "Impossible de publier l'index {$final}.");
            }
        } catch (Throwable $e) {
            foreach ([$tmpDb, $tmpDb . '.wal', $tmpDb . '.info', $tmpCsv] as $tmp) {
                @unlink($tmp);
            }

            throw $e instanceof SnapshotException ? $e : new SnapshotException('index_failed', 'Construction de l\'index DuckDB impossible : ' . $e->getMessage());
        }

        return ['rows' => $rows, 'seconds' => round(microtime(true) - $t0, 1), 'size' => (int) filesize($final)];
    }

    /**
     * Writes the normalised copy of the CSV (same delimiter, same header).
     *
     * @return list<string> the header columns
     */
    private function normalise(ActiveSnapshot $snapshot, string $target): array
    {
        $reader  = $snapshot->reader();
        $index   = $reader->index();
        $date    = $snapshot->dateFormat() !== null ? new SnapshotDate($snapshot->dateFormat()) : null;
        $d       = $snapshot->delimiter();
        $columns = array_keys($index);

        $out = @fopen($target, 'wb');
        if ($out === false) {
            throw new SnapshotException('index_failed', "Fichier temporaire d'index non inscriptible : {$target}");
        }

        try {
            fwrite($out, implode($d, $columns) . "\n");
            $buffer = '';
            $reader->each(static function (array $fields) use ($index, $date, $d, $out, &$buffer): void {
                $r       = SnapshotSort::record($fields, $index, $date);
                $buffer .= implode($d, array_map(static fn (?string $v): string => $v ?? '', $r)) . "\n";
                if (strlen($buffer) > 4_194_304) {
                    fwrite($out, $buffer);
                    $buffer = '';
                }
            });
            fwrite($out, $buffer);
        } finally {
            fclose($out);
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function load(string $csv, string $database, array $columns, string $delimiter): void
    {
        $types  = [];
        $select = [];
        foreach ($columns as $column) {
            self::assertColumn($column);
            $types[] = DuckDb::literal($column) . ': ' . DuckDb::literal('VARCHAR');
            $select[] = in_array($column, SnapshotSort::DATE_COLUMNS, true)
                ? "TRY_CAST(NULLIF(\"{$column}\", '') AS DATE) AS \"{$column}\""
                : "COALESCE(\"{$column}\", '') AS \"{$column}\"";
        }
        foreach (['CONTRACT' => '__contract_n', 'COD_CLI' => '__cod_cli_n'] as $column => $key) {
            $select[] = in_array($column, $columns, true) ? "TRY_CAST(\"{$column}\" AS BIGINT) AS {$key}" : "NULL::BIGINT AS {$key}";
        }

        $source = 'read_csv(' . DuckDb::literal(str_replace('\\', '/', $csv))
            . ', delim = ' . DuckDb::literal($delimiter)
            . ", header = true, quote = '', escape = '', auto_detect = false, columns = {" . implode(', ', $types) . '})';

        $this->duckdb->query($database, 'CREATE TABLE ' . self::TABLE . ' AS SELECT ' . implode(', ', $select)
            . " FROM {$source} ORDER BY __contract_n NULLS LAST, \"" . (in_array('CONTRACT', $columns, true) ? 'CONTRACT' : $columns[0]) . "\"; CHECKPOINT", false);
    }

    /** Column names come from a validated header; still, only plain names reach SQL. */
    private static function assertColumn(string $column): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new SnapshotException('index_failed', "Nom de colonne inattendu dans l'en-tête : {$column}");
        }
    }
}
