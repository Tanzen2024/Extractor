<?php

namespace App\Services\Refresh;

use App\Services\CustomerListExportService;
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

    public static function selectSql(): string
    {
        $columns = [];
        foreach (CustomerListExportService::COLUMNS as $column) {
            $columns[] = in_array($column, self::DATE_COLUMNS, true)
                ? sprintf("TO_CHAR(%s, '%s') AS %s", $column, self::DATE_SQL_FORMAT, $column)
                : $column;
        }

        return "SELECT\n    " . implode(",\n    ", $columns) . "\nFROM " . CustomerListExportService::SQL_TABLE;
    }

    public static function countSql(): string
    {
        return 'SELECT COUNT(*) AS N, MAX(UPDATED_AT) AS SOURCE_UPDATED_AT FROM ' . CustomerListExportService::SQL_TABLE;
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

            $header = implode(self::DELIMITER, CustomerListExportService::COLUMNS);
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

            $this->oracle->stream(self::selectSql(), function (array $row) use (&$buffer, &$rows, &$dirty, $flush, $every, $expected, $t1): void {
                $fields = [];
                foreach (CustomerListExportService::COLUMNS as $column) {
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
                'columns'           => count(CustomerListExportService::COLUMNS),
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
