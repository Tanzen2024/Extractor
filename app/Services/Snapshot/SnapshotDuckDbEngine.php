<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;

/**
 * The dashboard's production SnapshotQueryEngine: SQL over the version's
 * DuckDB index (SnapshotIndex) — same answers as SnapshotScanEngine, the
 * PHP reference, on the same file (parity tests), 20–100× faster.
 *
 * Every filter value reaching SQL has been validated against the version's
 * own values (FilterCriteria / AllowedValues) and is still quoted by
 * DuckDb::literal(); dates come from FilterCriteria as DateTimeImmutable;
 * the free-text search is matched with contains() — no LIKE, no wildcard;
 * column names only ever come from this class's constants.
 */
final class SnapshotDuckDbEngine implements SnapshotQueryEngine
{
    private const T = SnapshotIndex::TABLE;

    /** @var array<string, true> columns the version's index holds */
    private array $columns;

    /**
     * @param bool $contractUniqueNumeric verified for this version when its
     *        index was built (SnapshotIndex): every CONTRACT is a distinct
     *        number. Then "distinct contracts" are simply rows and CONTRACT
     *        alone orders rows totally — same answers, ~6× cheaper KPIs.
     *        False (or unknown): the exact COUNT(DISTINCT) / text tie-break SQL.
     */
    public function __construct(
        private readonly ActiveSnapshot $snapshot,
        private readonly DuckDb $duckdb,
        private readonly string $database,
        private readonly bool $contractUniqueNumeric = false,
    ) {
        $header        = (string) ($snapshot->meta['header'] ?? '');
        $this->columns = array_fill_keys($header !== '' ? explode($snapshot->delimiter(), $header) : [], true);
    }

    public function version(): string
    {
        return $this->snapshot->id;
    }

    public function snapshot(): ActiveSnapshot
    {
        return $this->snapshot;
    }

    public function count(FilterCriteria $criteria, string $search = ''): int
    {
        $rows = $this->run('SELECT COUNT(*) AS n FROM ' . self::T . $this->where($criteria, $search));

        return (int) ($rows[0]['n'] ?? 0);
    }

    public function kpis(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array
    {
        return self::kpiRow($this->run($this->kpiSql($criteria, $activeStatuses, $meteredMeters))[0] ?? []);
    }

    public function summary(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array
    {
        // KPIs and the four charts in ONE DuckDB process, rows tagged by q.
        $rows = $this->run("SELECT 'kpi' AS q, * FROM (" . $this->kpiSql($criteria, $activeStatuses, $meteredMeters) . ");\n"
            . "SELECT 'dist' AS q, * FROM (" . $this->distributionSql($criteria) . ')');

        $kpi  = [];
        $dist = [];
        foreach ($rows as $r) {
            if ($r['q'] === 'kpi') {
                $kpi = $r;
            } else {
                $dist[] = $r;
            }
        }

        return ['kpis' => self::kpiRow($kpi), 'distributions' => self::distributionRows($dist)];
    }

    /** One scan for every KPI (contracts counted distinctly — see $contractUniqueNumeric). */
    private function kpiSql(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): string
    {
        $active  = $this->list($activeStatuses);
        $metered = $this->list($meteredMeters);

        return 'SELECT
                ' . $this->contracts('TRUE') . ' AS total,
                ' . $this->contracts('STATUS IN (' . $active . ')') . ' AS actifs,
                ' . $this->contracts('STATUS NOT IN (' . $active . ')') . ' AS inactifs,
                ' . $this->contracts('METER IN (' . $metered . ')') . ' AS avec_compteur,
                ' . $this->contracts('contains(upper(NUI_QC), \'CORRECT\')') . ' AS nui_correct,
                COALESCE(SUM(CASE WHEN trim(PHONE_NUMBERS) <> \'\' OR trim(E_MAIL) <> \'\' THEN 1 ELSE 0 END), 0) AS contact_ok
            FROM ' . self::T . $this->where($criteria);
    }

    /** COUNT(DISTINCT CONTRACT) of the rows meeting $condition. */
    private function contracts(string $condition): string
    {
        return $this->contractUniqueNumeric
            ? "COUNT(*) FILTER (WHERE {$condition})"
            : "COUNT(DISTINCT CASE WHEN {$condition} THEN CONTRACT END)";
    }

    /** @param array<string, mixed> $row */
    private static function kpiRow(array $row): array
    {
        return [
            'total'        => (int) ($row['total'] ?? 0),
            'actifs'       => (int) ($row['actifs'] ?? 0),
            'inactifs'     => (int) ($row['inactifs'] ?? 0),
            'avecCompteur' => (int) ($row['avec_compteur'] ?? 0),
            'nuiCorrect'   => (int) ($row['nui_correct'] ?? 0),
            'contactOk'    => (int) ($row['contact_ok'] ?? 0),
        ];
    }

    public function reference(): array
    {
        $row = $this->run('SELECT ' . $this->contracts('TRUE') . ' AS total,
                ' . $this->contracts('trim(NUI_QC) <> \'\'') . ' AS nui
            FROM ' . self::T)[0] ?? [];

        return ['totalClients' => (int) ($row['total'] ?? 0), 'totalNui' => (int) ($row['nui'] ?? 0)];
    }

    public function distributions(FilterCriteria $criteria): array
    {
        return self::distributionRows($this->run($this->distributionSql($criteria)));
    }

    /** One scan for the four charts (GROUPING SETS, as the Oracle query did). */
    private function distributionSql(FilterCriteria $criteria): string
    {
        return 'SELECT
                CASE WHEN GROUPING(REGION) = 0 THEN \'region\'
                     WHEN GROUPING(STATUS) = 0 THEN \'status\'
                     WHEN GROUPING(SEGMENTATION) = 0 THEN \'segmentation\'
                     ELSE \'meterType\' END AS dim,
                COALESCE(REGION, STATUS, SEGMENTATION, METER) AS val,
                COUNT(*) AS n
            FROM ' . self::T . $this->where($criteria) . '
            GROUP BY GROUPING SETS ((REGION), (STATUS), (SEGMENTATION), (METER))';
    }

    /** @param list<array<string, mixed>> $rows */
    private static function distributionRows(array $rows): array
    {
        $out = ['region' => [], 'status' => [], 'segmentation' => [], 'meterType' => []];
        foreach ($rows as $r) {
            $out[$r['dim']][(string) $r['val']] = (int) $r['n'];
        }

        return $out;
    }

    public function segmentationCounts(FilterCriteria $criteria): array
    {
        $out = [];
        foreach ($this->run('SELECT SEGMENTATION AS val, COUNT(*) AS n FROM ' . self::T . $this->where($criteria) . ' GROUP BY SEGMENTATION') as $r) {
            $out[(string) $r['val']] = (int) $r['n'];
        }

        return $out;
    }

    public function page(FilterCriteria $criteria, string $search, ?string $sort, string $dir, int $offset, int $limit): array
    {
        $sort = in_array($sort, QueryBuilder::SORTABLE, true) ? $sort : 'CONTRACT';
        $desc = strtolower($dir) === 'desc';

        $select = [];
        foreach (QueryBuilder::ALL_COLUMNS as $column) {
            if (! isset($this->columns[$column])) {
                $select[] = "'' AS \"{$column}\""; // not carried by this snapshot (XCOORD / YCOORD)
            } elseif (in_array($column, SnapshotSort::DATE_COLUMNS, true)) {
                $select[] = "strftime(\"{$column}\", '%Y-%m-%d') AS \"{$column}\"";
            } else {
                $select[] = "\"{$column}\"";
            }
        }

        $rows = $this->run('SELECT ' . implode(', ', $select) . ' FROM ' . self::T . $this->where($criteria, $search)
            . ' ORDER BY ' . $this->orderBy($sort, $desc)
            . ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset));

        return array_map(static function (array $r): array {
            $row = [];
            foreach (QueryBuilder::ALL_COLUMNS as $column) {
                $value        = $r[$column] ?? null;
                $row[$column] = $value === null ? null : (string) $value;
            }

            return $row;
        }, $rows);
    }

    /** ORDER BY of SnapshotSort::compare(), tie broken by CONTRACT ascending. */
    private function orderBy(string $sort, bool $desc): string
    {
        $dir   = $desc ? 'DESC NULLS FIRST' : 'ASC NULLS LAST';
        // CONTRACT distinct numbers (verified at build): __contract_n alone is a total order.
        $byContract = static fn (string $d): array => ["__contract_n {$d}", "CONTRACT {$d}"];
        $exprs = match ($sort) {
            'CONTRACT' => $this->contractUniqueNumeric ? ["__contract_n {$dir}"] : $byContract($dir),
            'COD_CLI'  => ["__cod_cli_n {$dir}", "COD_CLI {$dir}"],
            default    => ["\"{$sort}\" {$dir}"],
        };
        if ($sort !== 'CONTRACT') {
            $exprs = array_merge($exprs, $this->contractUniqueNumeric ? ['__contract_n ASC'] : $byContract('ASC NULLS LAST'));
        }

        return implode(', ', $exprs);
    }

    /** WHERE of RowMatcher (+ SnapshotSort's search), '' when nothing applies. */
    private function where(FilterCriteria $c, string $search = ''): string
    {
        $conds = [];
        foreach (RowMatcher::LIST_FILTERS as $column => $property) {
            if ($c->{$property} !== []) {
                $conds[] = "\"{$column}\" IN (" . $this->list($c->{$property}) . ')';
            }
        }
        if ($c->dateFrom !== null) {
            $conds[] = 'DATE_AB >= DATE ' . DuckDb::literal($c->dateFrom->format('Y-m-d'));
        }
        if ($c->dateTo !== null) {
            $conds[] = 'DATE_AB <= DATE ' . DuckDb::literal($c->dateTo->format('Y-m-d'));
        }

        $needle = SnapshotSort::searchNeedle($search);
        if ($needle !== null) {
            $q      = DuckDb::literal($needle);
            $any    = [];
            foreach (SnapshotSort::SEARCH_UPPER as $column) {
                $any[] = "contains(upper(\"{$column}\"), {$q})";
            }
            foreach (SnapshotSort::SEARCH_PLAIN as $column) {
                $any[] = "contains(\"{$column}\", {$q})";
            }
            $conds[] = '(' . implode(' OR ', $any) . ')';
        }

        return $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds);
    }

    /** @param list<string> $values */
    private function list(array $values): string
    {
        return $values === [] ? 'NULL' : implode(', ', array_map(static fn ($v): string => DuckDb::literal((string) $v), $values));
    }

    /** @return list<array<string, mixed>> */
    private function run(string $sql): array
    {
        return $this->duckdb->query($this->database, $sql);
    }
}
