<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\ActiveCustomers;
use Config\Snapshot as SnapshotConfig;

/**
 * What changed between two snapshot versions, computed from their DuckDB
 * indexes after a refresh. The source table is truncated and reloaded every
 * night and carries no per-customer change date, so this comparison is the
 * only way to know which customers were added, modified or removed.
 *
 * A contract is the customer key. UPDATED_AT is left out of the comparison:
 * it is one batch stamp shared by every row, so it would mark every customer
 * as modified.
 */
final class SnapshotDiff
{
    private const IGNORED_COLUMNS = ['UPDATED_AT'];

    private SnapshotConfig $config;
    private DuckDb $duckdb;

    public function __construct(?SnapshotConfig $config = null, ?DuckDb $duckdb = null)
    {
        $this->config = $config ?? new SnapshotConfig();
        $this->duckdb = $duckdb ?? new DuckDb($this->config);
    }

    /**
     * @return array{rows_active: int, rows_duplicate: int, rows_added: ?int, rows_modified: ?int,
     *     rows_ignored: ?int, rows_removed: ?int, rows_inactivated: ?int}
     *     The comparison counts are null when there is no previous index, or
     *     when duplicate contracts make a per-contract comparison meaningless.
     *
     * @throws SnapshotException 'duckdb_failed'
     */
    public function compare(ActiveSnapshot $current, ?ActiveSnapshot $previous): array
    {
        $index  = new SnapshotIndex($this->config, $this->duckdb);
        $active = self::activeCondition();
        $table  = SnapshotIndex::TABLE;

        $facts = $this->duckdb->query($index->path($current), "SELECT
                COUNT(*) FILTER (WHERE {$active}) AS active_rows,
                COUNT(*) - COUNT(DISTINCT CONTRACT) AS duplicates
            FROM {$table}")[0] ?? [];

        $result = [
            'rows_active'      => (int) ($facts['active_rows'] ?? 0),
            'rows_duplicate'   => (int) ($facts['duplicates'] ?? 0),
            'rows_added'       => null,
            'rows_modified'    => null,
            'rows_ignored'     => null,
            'rows_removed'     => null,
            'rows_inactivated' => null,
        ];

        if ($previous === null || ! $index->exists($previous) || $result['rows_duplicate'] > 0) {
            return $result;
        }

        $fingerprint = self::fingerprint(self::comparedColumns($current, $previous));
        $attached    = str_replace('\\', '/', $index->path($previous));

        $counts = $this->duckdb->query($index->path($current), 'ATTACH ' . DuckDb::literal($attached) . " AS prev (READ_ONLY);
            WITH n AS (SELECT CONTRACT, {$fingerprint} AS fp, {$active} AS is_active FROM {$table}),
                 o AS (SELECT CONTRACT, {$fingerprint} AS fp, {$active} AS is_active FROM prev.{$table})
            SELECT
                COUNT(*) FILTER (WHERE o.CONTRACT IS NULL) AS added,
                COUNT(*) FILTER (WHERE n.CONTRACT IS NULL) AS removed,
                COUNT(*) FILTER (WHERE n.fp <> o.fp) AS modified,
                COUNT(*) FILTER (WHERE n.fp = o.fp) AS unchanged,
                COUNT(*) FILTER (WHERE o.is_active AND NOT n.is_active) AS inactivated
            FROM n FULL OUTER JOIN o ON n.CONTRACT = o.CONTRACT")[0] ?? [];

        return [
            'rows_added'       => (int) ($counts['added'] ?? 0),
            'rows_modified'    => (int) ($counts['modified'] ?? 0),
            'rows_ignored'     => (int) ($counts['unchanged'] ?? 0),
            'rows_removed'     => (int) ($counts['removed'] ?? 0),
            'rows_inactivated' => (int) ($counts['inactivated'] ?? 0),
        ] + $result;
    }

    /** @return list<string> Columns present in both versions. */
    private static function comparedColumns(ActiveSnapshot $current, ActiveSnapshot $previous): array
    {
        $columns = array_intersect(self::header($current), self::header($previous));

        return array_values(array_diff($columns, self::IGNORED_COLUMNS));
    }

    /** @return list<string> */
    private static function header(ActiveSnapshot $snapshot): array
    {
        $columns = explode($snapshot->delimiter(), (string) ($snapshot->meta['header'] ?? ''));

        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new SnapshotException('duckdb_failed', "Nom de colonne inattendu dans l'en-tête : {$column}");
            }
        }

        return $columns;
    }

    /** @param list<string> $columns */
    private static function fingerprint(array $columns): string
    {
        $values = array_map(static fn (string $c): string => "COALESCE(CAST(\"{$c}\" AS VARCHAR), '')", $columns);

        return 'hash(concat_ws(chr(31), ' . implode(', ', $values) . '))';
    }

    private static function activeCondition(): string
    {
        $values = array_map(DuckDb::literal(...), ActiveCustomers::STATUSES);

        return 'COALESCE("STATUS" IN (' . implode(', ', $values) . '), false)';
    }
}
