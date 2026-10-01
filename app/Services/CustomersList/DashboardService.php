<?php

namespace App\Services\CustomersList;

use App\Services\OracleExtractionService;
use CodeIgniter\Cache\CacheInterface;
use Config\Oracle as OracleConfig;
use Config\Services;

/**
 * Live analytics for the CUSTOMERS_LIST dashboard.
 *
 * Everything is computed straight from CMS_RFC.TB_CUSTOMERS_LIST via a small
 * number of aggregate full-table scans (the table has no indexes, but a bare
 * GROUP BY over its ~3.28M narrow rows runs in ~0.5s). Responses are cached
 * per filter set for a few minutes — the source table is refreshed in bulk
 * on a slow cadence, so that staleness is invisible while sparing Oracle a
 * scan on every repeat view.
 *
 * The KPI / "active" / "metered" business rules live in Config\Oracle and
 * were derived from the real distinct values in the column (see the data
 * profile in the dashboard plan), never guessed.
 */
class DashboardService
{
    private const EMPTY_LABEL = 'Non renseigné';
    private OracleExtractionService $oracle;
    private QueryBuilder $queryBuilder;
    private OracleConfig $config;
    private CacheInterface $cache;

    public function __construct(
        ?OracleExtractionService $oracle = null,
        ?QueryBuilder $queryBuilder = null,
        ?OracleConfig $config = null,
        ?CacheInterface $cache = null,
    ) {
        $this->config       = $config ?? new OracleConfig();
        $this->oracle       = $oracle ?? new OracleExtractionService($this->config);
        $this->queryBuilder = $queryBuilder ?? new QueryBuilder($this->config);
        $this->cache        = $cache ?? Services::cache();
    }

    /**
     * KPIs + the four chart datasets for a filter set. Two full scans (one for
     * every KPI figure, one GROUPING SETS scan for the region / status /
     * segmentation / meter-type distributions).
     *
     * @return array{
     *   totalRows:int,
     *   kpis:array{total:int, totalPct:float, reference:array{totalClients:int, totalNui:int}, nuiCorrects:array{value:int,pct:float}, actifs:array{value:int,pct:float}, inactifs:array{value:int,pct:float}, avecCompteur:array{value:int,pct:float}, contacts:array{value:int,pct:float}},
     *   charts:array{region:list<array{value:string,count:int}>, status:list<array{value:string,count:int}>, segmentation:list<array{value:string,count:int}>, meterType:list<array{value:string,count:int}>}
     * }
     */
    public function stats(FilterCriteria $criteria, bool $fresh = false): array
    {
        // v4: payload gained kpis.nuiCorrects (v2), kpis.inactifs (v3), then
        // kpis.totalPct / kpis.reference (v4), every segmentation value
        // unfolded (v5) — never serve an older entry.
        return $this->remember('stats_v5_' . $criteria->cacheKey(), $this->config->dashboardCacheTtl, $fresh, function () use ($criteria, $fresh): array {
            // "% du total" of Total clients / Clients actifs / NUI corrects is
            // taken against the unfiltered reference, not the filtered total.
            // Contacts (and the non-displayed cards) keep the filtered total.
            $ref      = $this->reference($fresh);
            $where    = $this->queryBuilder->where($criteria);
            $kpiStmt  = $this->queryBuilder->kpiStatement($where);
            $distStmt = $this->queryBuilder->distributionsStatement($where);

            $batch = $this->oracle->selectMany([
                'kpi'  => ['sql' => $kpiStmt['sql'], 'binds' => $kpiStmt['binds']],
                'dist' => ['sql' => $distStmt['sql'], 'binds' => $distStmt['binds']],
            ], 500);

            $kpiRow   = $batch['kpi'][0] ?? [];
            $distRows = $batch['dist'];
            $total    = (int) ($kpiRow['TOTAL'] ?? 0);

            return [
                'totalRows' => $total,
                'kpis'      => [
                    'total'        => $total,
                    'totalPct'     => $this->pct($total, $ref['totalClients']),
                    'reference'    => $ref,
                    'nuiCorrects'  => $this->ratio((int) ($kpiRow['NUI_CORRECT'] ?? 0), $ref['totalNui']),
                    'actifs'       => $this->ratio((int) ($kpiRow['ACTIFS'] ?? 0), $ref['totalClients']),
                    'inactifs'     => $this->ratio((int) ($kpiRow['INACTIFS'] ?? 0), $total),
                    'avecCompteur' => $this->ratio((int) ($kpiRow['AVEC_COMPTEUR'] ?? 0), $total),
                    'contacts'     => $this->ratio((int) ($kpiRow['CONTACT_OK'] ?? 0), $total),
                ],
                'charts'    => [
                    'region'       => $this->distribution($distRows, 'region'),
                    'status'       => $this->distribution($distRows, 'status'),
                    // Every segment, never folded into "Autres": the chart
                    // shows them in the business order (dashboard.js
                    // segmentationChartPairs()), not by volume.
                    'segmentation' => $this->distribution($distRows, 'segmentation'),
                    // Répartition des compteurs par type (colonne METER). Chaque
                    // ligne de la population filtrée tombe dans exactement un
                    // bucket (les valeurs vides -> "Non renseigné"), donc la
                    // somme des counts == totalRows.
                    'meterType'    => $this->distribution($distRows, 'meterType'),
                ],
            ];
        });
    }

    /**
     * Numbers next to the options of the Segmentation filter: rows per
     * SEGMENTATION value under every active filter EXCEPT the segmentation
     * one (so ticking a segment gives exactly the number shown next to it).
     * Same WHERE as the KPIs / table (QueryBuilder::where()), one GROUP BY,
     * cached per filter set like stats().
     *
     * @return list<array{value:string,count:int}>
     */
    public function segmentationCounts(FilterCriteria $criteria, bool $fresh = false): array
    {
        $scope = $criteria->withoutSegmentations();

        return $this->remember('segcounts_v1_' . $scope->cacheKey(), $this->config->dashboardCacheTtl, $fresh, function () use ($scope): array {
            $stmt = $this->queryBuilder->segmentationCountsStatement($this->queryBuilder->where($scope));
            $rows = $this->oracle->select($stmt['sql'], $stmt['binds'], 500)['rows'];

            return $this->distribution(array_map(static fn (array $r): array => $r + ['DIM' => 'segmentation'], $rows), 'segmentation');
        });
    }

    /**
     * One page of the data table. `total` is the cached filtered COUNT; the
     * page itself is a bounded OFFSET/FETCH query (not cached).
     *
     * @return array{data:list<array<string,mixed>>, columns:list<string>, page:int, perPage:int, total:int, sort:string, dir:string, search:string}
     */
    public function rows(
        FilterCriteria $criteria,
        int $page,
        int $perPage,
        ?string $sort,
        string $dir,
        string $search = '',
        bool $fresh = false,
    ): array {
        $page    = max(1, $page);
        $perPage = in_array($perPage, [20, 50, 100, 200], true) ? $perPage : 50;
        $offset  = ($page - 1) * $perPage;

        if ($offset > $this->config->tableMaxOffset) {
            throw new InvalidFilterException(
                'Page trop profonde : affinez vos filtres ou votre recherche pour atteindre ces lignes.'
            );
        }

        $sort  = in_array($sort, QueryBuilder::SORTABLE, true) ? $sort : null;
        $dir   = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        $total = $this->count($criteria, $search, $fresh);

        $where   = $this->queryBuilder->withSearch($this->queryBuilder->where($criteria), $search);
        $orderBy = $this->queryBuilder->orderBy($sort, $dir);
        $stmt    = $this->queryBuilder->pageStatement($where, $orderBy, $offset, $perPage);

        $result = $this->oracle->select($stmt['sql'], $stmt['binds'], $perPage);

        return [
            'data'    => $result['rows'],
            'columns' => QueryBuilder::ALL_COLUMNS,
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
            'sort'    => $sort ?? 'CONTRACT',
            'dir'     => $dir,
            'search'  => $search,
        ];
    }

    public function count(FilterCriteria $criteria, string $search = '', bool $fresh = false): int
    {
        $key = 'count_' . $criteria->cacheKey() . '_' . md5($search);

        return (int) $this->remember($key, $this->config->dashboardCacheTtl, $fresh, function () use ($criteria, $search): int {
            $where = $this->queryBuilder->withSearch($this->queryBuilder->where($criteria), $search);
            $stmt  = $this->queryBuilder->countStatement($where);

            return (int) ($this->oracle->select($stmt['sql'], $stmt['binds'], 1)['rows'][0]['N'] ?? 0);
        });
    }

    /**
     * How an export of $count rows must be handled — the ONLY input to the
     * sync/async decision, and $count must be a backend COUNT (never a figure
     * echoed by the browser).
     *
     * @return 'empty'|'sync'|'async'
     */
    public function exportDecision(int $count): string
    {
        if ($count <= 0) {
            return 'empty';
        }

        return $count <= $this->config->exportSyncMaxRows ? 'sync' : 'async';
    }

    /**
     * Everything the filter UI needs: the region -> division -> agence tree,
     * every distinct value (with counts) for the flat dimensions, and the
     * DATE_AB min/max. Heavily cached — this only changes when the source
     * table is rebuilt.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(bool $fresh = false): array
    {
        return $this->remember('filter-options', $this->config->filterOptionsCacheTtl, $fresh, function (): array {
            $t = QueryBuilder::TABLE;

            // All three fan-out queries on one connection.
            $batch = $this->oracle->selectMany([
                'flat' => ['sql' => "SELECT
                        CASE
                            WHEN GROUPING(REGION) = 0 THEN 'regions'
                            WHEN GROUPING(STATUS) = 0 THEN 'statuses'
                            WHEN GROUPING(SEGMENTATION) = 0 THEN 'segmentations'
                            WHEN GROUPING(SEGMENT_TRESOR) = 0 THEN 'segmentsTresor'
                            WHEN GROUPING(METER) = 0 THEN 'meters'
                            WHEN GROUPING(VOLTAGE) = 0 THEN 'voltages'
                            ELSE 'niuQualities'
                        END DIM,
                        COALESCE(REGION, STATUS, SEGMENTATION, SEGMENT_TRESOR, METER, VOLTAGE, NUI_QC) VAL,
                        COUNT(*) N
                    FROM {$t}
                    GROUP BY GROUPING SETS ((REGION), (STATUS), (SEGMENTATION), (SEGMENT_TRESOR), (METER), (VOLTAGE), (NUI_QC))"],
                'geo'    => ['sql' => "SELECT REGION, DIVISION, AGENCE, COUNT(*) N FROM {$t} GROUP BY REGION, DIVISION, AGENCE"],
                'bounds' => ['sql' => "SELECT TO_CHAR(MIN(DATE_AB), 'YYYY-MM-DD') MN, TO_CHAR(MAX(DATE_AB), 'YYYY-MM-DD') MX FROM {$t}"],
            ], 3000);

            $flat = $batch['flat'];

            $lists = ['regions' => [], 'statuses' => [], 'segmentations' => [], 'segmentsTresor' => [], 'meters' => [], 'voltages' => [], 'niuQualities' => []];
            foreach ($flat as $row) {
                $value = trim((string) ($row['VAL'] ?? ''));
                // A blank / NULL bucket is shown in the charts (via label())
                // but is NOT offered as a filter value in v1 — see the plan's
                // "points restants".
                if ($value === '') {
                    continue;
                }
                $lists[$row['DIM']][] = ['value' => $value, 'count' => (int) $row['N']];
            }
            foreach ($lists as &$list) {
                usort($list, static fn ($a, $b) => $b['count'] <=> $a['count']);
            }
            unset($list);

            $geo = $batch['geo'];

            $tree      = [];
            $divisions = [];
            $agences   = [];
            foreach ($geo as $row) {
                $region   = $this->label($row['REGION']);
                $division = $this->label($row['DIVISION']);
                $agence   = $this->label($row['AGENCE']);

                $tree[$region][$division][] = ['value' => $agence, 'count' => (int) $row['N']];
                $divisions[$division]       = ($divisions[$division] ?? 0) + (int) $row['N'];
                $agences[$agence]           = ($agences[$agence] ?? 0) + (int) $row['N'];
            }

            $bounds = $batch['bounds'][0] ?? ['MN' => null, 'MX' => null];

            // The column holds a handful of obviously-bad dates (year 0980,
            // etc.). Floor the date-picker minimum so the native control
            // doesn't open a millennium ago; the filter itself still accepts
            // any date the user types.
            $min = $bounds['MN'] ?? null;
            if ($min !== null && $min < '1990-01-01') {
                $min = '1990-01-01';
            }

            // Type de compteur (POSTPAID/PREPAID) <-> Segmentation is a fixed
            // business mapping, not derived from the data — see
            // POSTPAID_SEGMENTATIONS / PREPAID_SEGMENTATIONS in dashboard.js.
            // The PREPAID categories absent from the data are added by
            // PrepaidSegmentations::completeOptions() (controller /
            // AllowedValues), not cached here.

            return [
                'regions'        => $lists['regions'],
                'divisions'      => $this->pairs($divisions),
                'agences'        => $this->pairs($agences),
                'statuses'       => $lists['statuses'],
                'segmentations'  => $lists['segmentations'],
                'segmentsTresor' => $lists['segmentsTresor'],
                'meters'         => $lists['meters'],
                'voltages'       => $lists['voltages'],
                'niuQualities'   => $lists['niuQualities'],
                'geoTree'        => $tree,
                'dateBounds'     => ['min' => $min, 'max' => $bounds['MX'] ?? null],
            ];
        });
    }

    public function allowedValues(bool $fresh = false): AllowedValues
    {
        return AllowedValues::fromFilterOptions($this->filterOptions($fresh));
    }

    // ---- internals ----------------------------------------------------

    /**
     * @param list<array<string,mixed>> $rows Rows from distributionsStatement().
     *
     * @return list<array{value:string,count:int}>
     */
    private function distribution(array $rows, string $dim): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (($row['DIM'] ?? null) !== $dim) {
                continue;
            }
            $out[] = ['value' => $this->label($row['VAL'] ?? null), 'count' => (int) ($row['N'] ?? 0)];
        }

        usort($out, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Unfiltered totals of the whole table (KPI "% du total" denominators),
     * cached on their own so a filter change never rescans for them.
     *
     * @return array{totalClients:int, totalNui:int}
     */
    private function reference(bool $fresh): array
    {
        return $this->remember('stats_reference_v1', $this->config->dashboardCacheTtl, $fresh, function (): array {
            $stmt = $this->queryBuilder->referenceStatement();
            $row  = $this->oracle->selectMany(['ref' => ['sql' => $stmt['sql'], 'binds' => $stmt['binds']]], 1)['ref'][0] ?? [];

            return [
                'totalClients' => (int) ($row['TOTAL'] ?? 0),
                'totalNui'     => (int) ($row['NUI_TOTAL'] ?? 0),
            ];
        });
    }

    /**
     * @return array{value:int,pct:float}
     */
    private function ratio(int $value, int $total): array
    {
        return ['value' => $value, 'pct' => $this->pct($value, $total)];
    }

    private function pct(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    /**
     * @param array<string,int> $counts
     *
     * @return list<array{value:string,count:int}>
     */
    private function pairs(array $counts): array
    {
        arsort($counts);

        $out = [];
        foreach ($counts as $value => $count) {
            $out[] = ['value' => (string) $value, 'count' => $count];
        }

        return $out;
    }

    private function label(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : self::EMPTY_LABEL;
    }

    /**
     * @template T
     *
     * @param callable():T $compute
     *
     * @return T
     */
    private function remember(string $key, int $ttl, bool $fresh, callable $compute): mixed
    {
        $cacheKey = 'cl_' . $key;

        if (! $fresh && $ttl > 0) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $value = $compute();

        if ($ttl > 0) {
            $this->cache->save($cacheKey, $value, $ttl);
        }

        return $value;
    }
}
