<?php

namespace App\Services\Refresh;

use App\Services\CustomerListExportService;
use App\Services\CustomersList\BillingValue;
use App\Services\CustomersList\FailureSubcategories;
use App\Services\CustomersList\PrepaidRecency;
use App\Services\OracleExtractionService;
use App\Services\Snapshot\SnapshotException;
use Config\Oracle as OracleConfig;
use Throwable;

/**
 * Streams CMS_RFC.TB_CUSTOMERS_LIST into a snapshot-format CSV file
 * ('#'-separated, header first, one record per line, UTF-8) — the local
 * producer used by `php spark customers:refresh`, next to the SFTP push.
 *
 * Memory stays flat: rows come one at a time from
 * OracleExtractionService::stream() (OCI8 prefetch, no result array) and go
 * to disk through a ~1 MB write buffer; the SHA-256, byte and line counts of
 * the manifest are computed on the fly, so the file is never re-read here.
 *
 * The SELECT names its columns (CustomerListExportService::COLUMNS, the 25
 * the application exports and filters on) — never SELECT *. DATE columns are
 * rendered as DD/MM/YYYY: the Oracle session default (DD/MM/RR) would drop
 * the century and turn a garbage year 0980 into 1980.
 *
 * The output path is a temporary file: on any failure it is deleted and
 * the exception rethrown, so a partial extraction never survives.
 */
final class OracleSnapshotExtractor
{
    public const DELIMITER = '#';

    /** DATE columns in the source table (all other exported columns are text/number). */
    public const DATE_COLUMNS = ['DATE_AB', 'DATE_RESILIATION', 'LAST_VC_DATE', 'POSTPAID_PROFILE_DATE'];

    public const DATE_SQL_FORMAT = 'DD/MM/YYYY';

    private const WRITE_CHUNK_BYTES = 1 << 20;

    /** How often (rows) the stop callback is polled. */
    private const STOP_CHECK_EVERY = 5000;

    private OracleExtractionService $oracle;
    private OracleConfig $config;

    /** @var callable(string, string): void level, message */
    private $log;

    /** @var callable(): bool */
    private $shouldStop;

    /**
     * @param callable(string, string): void|null $log        Receives (level, message).
     * @param callable(): bool|null               $shouldStop Returns true to abort (signal received).
     */
    public function __construct(
        ?OracleExtractionService $oracle = null,
        ?OracleConfig $config = null,
        ?callable $log = null,
        ?callable $shouldStop = null,
        private readonly int $progressEvery = 250_000,
    ) {
        $this->config     = $config ?? new OracleConfig();
        $this->oracle     = $oracle ?? new OracleExtractionService($this->config);
        $this->log        = $log ?? static function (): void {};
        $this->shouldStop = $shouldStop ?? static fn (): bool => false;
    }

    /**
     * Analysis columns of the source table, never exported: read when the
     * table has them, left out otherwise, so a refresh works before and
     * after the source side adds them. Always in this order.
     */
    public const OPTIONAL_COLUMNS = [
        FailureSubcategories::COLUMN,
        PrepaidRecency::DAYS_COLUMN, PrepaidRecency::STATUS_COLUMN,
        BillingValue::AMOUNT_COLUMN, BillingValue::SEGMENT_COLUMN,
    ];

    /** @return list<string> the OPTIONAL_COLUMNS the table has, in OPTIONAL_COLUMNS order */
    private function optionalColumns(): array
    {
        $found = $this->oracle->select(
            "SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE OWNER || '.' || TABLE_NAME = '" . CustomerListExportService::SQL_TABLE . "'"
            . " AND COLUMN_NAME IN ('" . implode("', '", self::OPTIONAL_COLUMNS) . "')",
            [],
            count(self::OPTIONAL_COLUMNS),
        )['rows'] ?? [];

        $names = array_map(static fn (array $r): string => (string) ($r['COLUMN_NAME'] ?? ''), $found);

        return array_values(array_filter(self::OPTIONAL_COLUMNS, static fn (string $c): bool => in_array($c, $names, true)));
    }

    /**
     * The columns of the file: the exported ones, then the OPTIONAL_COLUMNS
     * the table has. FAILURE_SUBCATEGORY is always there: read when the table
     * carries it, computed by the extraction query otherwise ($derived).
     *
     * @param list<string> $found what optionalColumns() found
     *
     * @return array{0: list<string>, 1: bool} columns, FAILURE_SUBCATEGORY derived
     */
    public static function plannedColumns(array $found): array
    {
        $derived = ! in_array(FailureSubcategories::COLUMN, $found, true);
        $wanted  = $derived ? array_merge([FailureSubcategories::COLUMN], $found) : $found;
        $ordered = array_values(array_filter(self::OPTIONAL_COLUMNS, static fn (string $c): bool => in_array($c, $wanted, true)));

        return [array_merge(CustomerListExportService::COLUMNS, $ordered), $derived];
    }

    /**
     * @param list<string> $optional       OPTIONAL_COLUMNS to select, in that order
     * @param bool         $deriveFailure  compute FailureSubcategories::COLUMN instead of
     *                                     reading it (the table does not carry it)
     */
    public static function selectSql(array $optional = [], bool $deriveFailure = false): string
    {
        $columns = [];
        foreach (array_merge(CustomerListExportService::COLUMNS, $optional) as $column) {
            if ($deriveFailure && $column === FailureSubcategories::COLUMN) {
                $columns[] = self::failureSql() . ' AS ' . $column;
            } elseif (in_array($column, self::DATE_COLUMNS, true)) {
                $columns[] = sprintf("TO_CHAR(t.%s, '%s') AS %s", $column, self::DATE_SQL_FORMAT, $column);
            } else {
                $columns[] = 't.' . $column;
            }
        }

        $sql = "SELECT\n    " . implode(",\n    ", $columns) . "\nFROM " . CustomerListExportService::SQL_TABLE . ' t';
        if ($deriveFailure) {
            // One row per SERVICE_NO on the right: the row count is unchanged
            // (checked against COUNT(*) after the extraction anyway). Same
            // join as customers_list.sql (SERVICE_NO = CONTRACT).
            $sql .= "\nLEFT JOIN " . FailureSubcategories::oracleLastRows() . ' pp ON pp.SERVICE_NO = t.CONTRACT';
        }

        return $sql;
    }

    /**
     * Column 28 of customers_list.sql: the component for every 6 Défaillant
     * row (any spelling folded into the profile, so exactly the chart's
     * total), NULL for every other row.
     */
    public static function failureSql(): string
    {
        $labels = array_map(self::oracleText(...), FailureSubcategories::defaillantLabels());

        return "CASE WHEN t.SEGMENTATION IN (" . implode(', ', $labels) . ")\n        THEN "
            . FailureSubcategories::oracleRule('pp') . "\n    END";
    }

    /**
     * A text literal written in ASCII only, so the session character set can
     * never alter it: non-ASCII characters go through UNISTR (the corrupted
     * "6 D\u{FFFD}\u{FFFD}faillant" is matched byte for byte this way, a
     * plain literal is not).
     */
    public static function oracleText(string $value): string
    {
        $parts = [];
        $ascii = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            if (strlen($char) === 1) {
                $ascii .= $char;

                continue;
            }
            if ($ascii !== '') {
                $parts[] = "'" . str_replace("'", "''", $ascii) . "'";
                $ascii   = '';
            }
            $cp = mb_ord($char, 'UTF-8');
            $parts[] = $cp > 0xFFFF
                ? sprintf("UNISTR('\\%04X\\%04X')", 0xD800 + (($cp - 0x10000) >> 10), 0xDC00 + (($cp - 0x10000) & 0x3FF))
                : sprintf("UNISTR('\\%04X')", $cp);
        }
        if ($ascii !== '' || $parts === []) {
            $parts[] = "'" . str_replace("'", "''", $ascii) . "'";
        }

        return implode(' || ', $parts);
    }

    public static function countSql(): string
    {
        return 'SELECT COUNT(*) AS N, MAX(UPDATED_AT) AS SOURCE_UPDATED_AT FROM ' . CustomerListExportService::SQL_TABLE;
    }

    /**
     * What the source table holds now: one cheap aggregate, no extraction.
     *
     * @return array{rows: int, updated_at: string}
     */
    public function sourceState(): array
    {
        $ref = $this->oracle->select(self::countSql(), [], 1)['rows'][0] ?? [];

        return ['rows' => (int) ($ref['N'] ?? 0), 'updated_at' => (string) ($ref['SOURCE_UPDATED_AT'] ?? '')];
    }

    /**
     * @return array{rows: int, lines: int, size: int, sha256: string, header: string, columns: int,
     *     expected_rows: int, source_updated_at: ?string, sanitized_values: int,
     *     count_seconds: float, extract_seconds: float, peak_memory_mb: float}
     *
     * @throws SnapshotException|Throwable The temporary file is deleted first.
     */
    public function extract(string $tmpPath): array
    {
        $handle = null;

        try {
            $dir = dirname($tmpPath);
            if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
                throw new SnapshotException('write_failed', "Répertoire {$dir} impossible à créer.");
            }

            $handle = @fopen($tmpPath, 'wb');
            if ($handle === false) {
                $handle = null;

                throw new SnapshotException('write_failed', "Fichier temporaire {$tmpPath} impossible à créer (droits, disque ?).");
            }

            // Reference count, taken just before the extraction: a reload
            // (truncate + SQL*Loader) running meanwhile makes the two differ,
            // and the extraction is refused.
            $t0    = microtime(true);
            $ref   = $this->oracle->select(self::countSql(), [], 1)['rows'][0] ?? [];
            $expected      = (int) ($ref['N'] ?? 0);
            $sourceUpdated = isset($ref['SOURCE_UPDATED_AT']) && $ref['SOURCE_UPDATED_AT'] !== '' ? (string) $ref['SOURCE_UPDATED_AT'] : null;
            $countSeconds  = microtime(true) - $t0;

            ($this->log)('info', sprintf('Oracle connection OK — %s lignes attendues, MAX(UPDATED_AT)=%s (%.1fs)', number_format($expected, 0, '.', ' '), $sourceUpdated ?? 'n/d', $countSeconds));

            [$columns, $deriveFailure] = self::plannedColumns($this->optionalColumns());
            $optional = array_slice($columns, count(CustomerListExportService::COLUMNS));
            if ($deriveFailure) {
                ($this->log)('info', FailureSubcategories::COLUMN . ' absente de la table : calculée par la requête d\'extraction (' . FailureSubcategories::SOURCE_TABLE . ')');
            }
            $header   = implode(self::DELIMITER, $columns);
            $hash   = hash_init('sha256');
            $buffer = $header . "\n";
            $bytes  = 0;
            $rows   = 0;
            $dirty  = 0;
            $every  = max(1, $this->progressEvery);
            $t1     = microtime(true);

            $flush = function () use (&$buffer, &$bytes, $handle, $hash): void {
                if ($buffer === '') {
                    return;
                }
                $written = @fwrite($handle, $buffer);
                if ($written !== strlen($buffer)) {
                    throw new SnapshotException('write_failed', 'Écriture du fichier temporaire incomplète (disque plein ?).');
                }
                hash_update($hash, $buffer);
                $bytes += $written;
                $buffer = '';
            };

            ($this->log)('info', 'Requête d\'extraction démarrée');

            $this->oracle->stream(self::selectSql($optional, $deriveFailure), function (array $row) use (&$buffer, &$rows, &$dirty, $flush, $every, $expected, $t1, $columns): void {
                $fields = [];
                foreach ($columns as $column) {
                    $value = (string) ($row[$column] ?? '');
                    // The format has no enclosure: a delimiter or newline
                    // inside a value would shift every following field.
                    // None exist today (checked 2026-09-27); neutralise and
                    // count rather than corrupt the file if one appears.
                    if ($value !== '' && strpbrk($value, "#\r\n") !== false) {
                        $value = strtr($value, ['#' => ' ', "\r" => ' ', "\n" => ' ']);
                        $dirty++;
                    }
                    $fields[] = $value;
                }
                $buffer .= implode(self::DELIMITER, $fields) . "\n";
                $rows++;

                if (strlen($buffer) >= self::WRITE_CHUNK_BYTES) {
                    $flush();
                }

                if ($rows % self::STOP_CHECK_EVERY === 0 && ($this->shouldStop)()) {
                    throw new SnapshotException('interrupted', "Extraction interrompue par un signal après {$rows} lignes.");
                }

                if ($rows % $every === 0) {
                    $elapsed = microtime(true) - $t1;
                    ($this->log)('info', sprintf(
                        '%s rows extracted (%s%%, %d lignes/s, mémoire pic %.1f Mo)',
                        number_format($rows, 0, '.', ' '),
                        $expected > 0 ? number_format(100 * $rows / $expected, 1) : '?',
                        $elapsed > 0 ? (int) ($rows / $elapsed) : 0,
                        memory_get_peak_usage(true) / 1048576,
                    ));
                }
            }, [], $this->config->exportPrefetchRows);

            $flush();

            if (! fflush($handle) || (function_exists('fsync') && ! @fsync($handle))) {
                throw new SnapshotException('write_failed', 'Vidage du fichier temporaire sur disque impossible.');
            }
            $closed = fclose($handle);
            $handle = null;
            if (! $closed) {
                throw new SnapshotException('write_failed', 'Fermeture du fichier temporaire en erreur.');
            }

            $extractSeconds = microtime(true) - $t1;
            ($this->log)('info', sprintf('Extraction completed en %.1fs', $extractSeconds));

            clearstatcache(true, $tmpPath);
            $size = (int) @filesize($tmpPath);
            if ($size !== $bytes) {
                throw new SnapshotException('size_mismatch', "Taille sur disque {$size} ≠ {$bytes} octets écrits.");
            }
            if ($rows === 0) {
                throw new SnapshotException('no_data', 'Oracle n\'a renvoyé aucune ligne (table vide ou en cours de rechargement ?).');
            }
            if ($rows !== $expected) {
                throw new SnapshotException('row_count_mismatch', "{$rows} lignes extraites ≠ COUNT(*) Oracle {$expected} (table modifiée pendant l'extraction ?).");
            }
            if ($dirty > 0) {
                ($this->log)('warning', "{$dirty} valeur(s) contenant '#' ou un saut de ligne remplacé(s) par un espace");
            }

            return [
                'rows'              => $rows,
                'lines'             => $rows + 1,
                'size'              => $bytes,
                'sha256'            => hash_final($hash),
                'header'            => $header,
                'columns'           => count($columns),
                'expected_rows'     => $expected,
                'source_updated_at' => $sourceUpdated,
                'sanitized_values'  => $dirty,
                'count_seconds'     => round($countSeconds, 1),
                'extract_seconds'   => round($extractSeconds, 1),
                'peak_memory_mb'    => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            @unlink($tmpPath);

            throw $e;
        }
    }

    /**
     * Writes the manifest SnapshotInstaller validates the file against
     * (same key=value format as the SFTP push, see SnapshotManifest).
     *
     * @param array<string, mixed> $facts Result of extract().
     */
    public static function writeManifest(string $path, array $facts): void
    {
        $values = [
            'file'              => 'customers_list.csv',
            'size'              => $facts['size'],
            'sha256'            => $facts['sha256'],
            'lines'             => $facts['lines'],
            'columns'           => $facts['columns'],
            'delimiter'         => self::DELIMITER,
            'encoding'          => 'UTF-8',
            'header'            => $facts['header'],
            'generated_at'      => date(DATE_ATOM),
            'source_host'       => (gethostname() ?: 'extractor') . ' (customers:refresh)',
            'source'            => 'oracle:' . CustomerListExportService::SQL_TABLE,
            'source_updated_at' => $facts['source_updated_at'] ?? '',
            'oracle_count'      => $facts['expected_rows'],
        ];

        $out = '';
        foreach ($values as $key => $value) {
            $out .= "{$key}={$value}\n";
        }

        if (@file_put_contents($path, $out) !== strlen($out)) {
            @unlink($path);

            throw new SnapshotException('write_failed', 'Écriture du manifeste impossible.');
        }
    }
}
