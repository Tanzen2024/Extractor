<?php

namespace App\Services\CustomersList;

use App\Services\Snapshot\ActiveSnapshot;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotQueryEngine;
use App\Services\Snapshot\SnapshotStore;
use App\Services\Snapshot\SnapshotUnavailableException;
use Closure;
use CodeIgniter\Cache\CacheInterface;
use Config\Oracle as OracleConfig;
use Config\Services;
use Config\Snapshot as SnapshotConfig;

/**
 * Analytics of the CUSTOMERS_LIST dashboard — filters, KPIs, charts,
 * segmentation counts, table and count — computed from the ACTIVE SNAPSHOT
 * only, the very version the exports read (one SnapshotQueryEngine, i.e.
 * one version, per instance). Never Oracle: Oracle is read by the snapshot
 * refresh alone; no valid snapshot / index -> SnapshotUnavailableException
 * (HTTP 503), never a fallback.
 *
 * Results are cached per (version, filters): a version never changes, so a
 * new version simply starts new keys.
 *
 * The KPI / "active" / "metered" business rules are unchanged and still live
 * in Config\Oracle (activeStatuses, meteredMeterValues).
 */
class DashboardService
{
    private const EMPTY_LABEL = 'Non renseigné';

    private OracleConfig $config;
    private CacheInterface $cache;
    private int $cacheTtl;

    /** @var Closure(): SnapshotQueryEngine */
    private Closure $engineFactory;

    private ?SnapshotQueryEngine $engine = null;

    /**
     * @param SnapshotQueryEngine|(callable(): SnapshotQueryEngine)|null $engine
     *        null = the active version's DuckDB engine, resolved on first use.
     */
    public function __construct(
        SnapshotQueryEngine|callable|null $engine = null,
        ?OracleConfig $config = null,
        ?CacheInterface $cache = null,
        ?SnapshotConfig $snapshotConfig = null,
    ) {
        $this->config   = $config ?? new OracleConfig();
        $this->cache    = $cache ?? Services::cache();
        $snapshotConfig ??= new SnapshotConfig();
        $this->cacheTtl = $snapshotConfig->countCacheTtl;

        if ($engine instanceof SnapshotQueryEngine) {
            $this->engine        = $engine;
            $this->engineFactory = static fn (): SnapshotQueryEngine => $engine;
        } elseif ($engine !== null) {
            $this->engineFactory = Closure::fromCallable($engine);
        } else {
            $this->engineFactory = static function () use ($snapshotConfig): SnapshotQueryEngine {
                $store = new SnapshotStore($snapshotConfig);

                return (new SnapshotIndex($snapshotConfig))->engine($store->active());
            };
        }
    }

    /**
     * The engine of this request — resolved once, so every figure of a
     * request describes the same version.
     *
     * @throws SnapshotUnavailableException
     */
    public function engine(): SnapshotQueryEngine
    {
        return $this->engine ??= ($this->engineFactory)();
    }

    public function snapshot(): ActiveSnapshot
    {
        return $this->engine()->snapshot();
    }

    /**
     * KPIs + the four chart datasets for a filter set.
     *
     * @return array{
     *   totalRows:int,
     *   kpis:array{total:int, totalPct:float, reference:array{totalClients:int, totalNui:int}, nuiCorrects:array{value:int,pct:float}, actifs:array{value:int,pct:float}, inactifs:array{value:int,pct:float}, avecCompteur:array{value:int,pct:float}, contacts:array{value:int,pct:float}},
     *   charts:array{region:list<array{value:string,count:int}>, status:list<array{value:string,count:int}>, segmentation:list<array{value:string,count:int}>, meterType:list<array{value:string,count:int}>}
     * }
     */
    public function stats(FilterCriteria $criteria, bool $fresh = false): array
    {
        return $this->remember('stats_' . $criteria->cacheKey(), $fresh, function () use ($criteria, $fresh): array {
            // "% du total" of Total clients / Clients actifs / NUI corrects is
            // taken against the unfiltered reference, not the filtered total.
            // Contacts (and the non-displayed cards) keep the filtered total.
            $ref     = $this->reference($fresh);
            $summary = $this->engine()->summary($criteria, array_values($this->config->activeStatuses), array_values($this->config->meteredMeterValues));
            $k       = $summary['kpis'];
            $dist    = $summary['distributions'];
            $total   = $k['total'];

            return [
                'totalRows' => $total,
                'kpis'      => [
                    'total'        => $total,
                    'totalPct'     => $this->pct($total, $ref['totalClients']),
                    'reference'    => $ref,
                    'nuiCorrects'  => $this->ratio($k['nuiCorrect'], $ref['totalNui']),
                    'actifs'       => $this->ratio($k['actifs'], $ref['totalClients']),
                    'inactifs'     => $this->ratio($k['inactifs'], $total),
                    'avecCompteur' => $this->ratio($k['avecCompteur'], $total),
                    'contacts'     => $this->ratio($k['contactOk'], $total),
                ],
                'charts'    => [
                    'region'       => $this->distribution($dist['region']),
                    'status'       => $this->distribution($dist['status']),
                    // Every segment, never folded into "Autres": the chart
                    // shows them in the business order (dashboard.js
                    // segmentationChartPairs()), not by volume.
                    'segmentation' => $this->distribution($dist['segmentation']),
                    // Every row falls in exactly one METER bucket (blank ->
                    // "Non renseigné"), so the counts sum to totalRows.
                    'meterType'    => $this->distribution($dist['meterType']),
                ],
            ];
        });
    }

    /**
     * Numbers next to the options of the Segmentation filter: rows per
     * SEGMENTATION value under every active filter EXCEPT the segmentation
     * one (so ticking a segment gives exactly the number shown next to it).
     *
     * @return list<array{value:string,count:int}>
     */
    public function segmentationCounts(FilterCriteria $criteria, bool $fresh = false): array
    {
        $scope = $criteria->withoutSegmentations();

        return $this->remember('segcounts_' . $scope->cacheKey(), $fresh, fn (): array => $this->distribution($this->engine()->segmentationCounts($scope)));
    }

    /**
     * One page of the data table. `total` is the cached filtered count.
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

        return [
            'data'    => $this->engine()->page($criteria, $search, $sort, $dir, $offset, $perPage),
            'columns' => QueryBuilder::ALL_COLUMNS,
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
            'sort'    => $sort ?? 'CONTRACT',
            'dir'     => $dir,
            'search'  => $search,
        ];
    }

    /** Rows matching the filters (+ the table search) in this version. */
    public function count(FilterCriteria $criteria, string $search = '', bool $fresh = false): int
    {
        return (int) $this->remember('count_' . $criteria->cacheKey() . '_' . md5($search), $fresh, fn (): int => $this->engine()->count($criteria, $search));
    }

    /**
     * How an export of $count rows must be handled — the ONLY input to the
     * sync/async decision, and $count must be a backend count (never a figure
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
     * The filter UI's options (value lists with counts, region -> division ->
     * agence tree, DATE_AB bounds) — computed from this version when it was
     * installed (SnapshotFilterOptions), so instant.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return $this->snapshot()->filterOptions();
    }

    /** The values a filter may take: exactly those present in this version. */
    public function allowedValues(): AllowedValues
    {
        return AllowedValues::fromFilterOptions($this->filterOptions());
    }

    // ---- internals ----------------------------------------------------

    /**
     * @param array<string,int> $counts raw value => rows
     *
     * @return list<array{value:string,count:int}>
     */
    private function distribution(array $counts): array
    {
        // Blank / missing values share one "Non renseigné" bucket.
        $merged = [];
        foreach ($counts as $value => $count) {
            $label          = $this->label((string) $value);
            $merged[$label] = ($merged[$label] ?? 0) + (int) $count;
        }

        $out = [];
        foreach ($merged as $label => $count) {
            $out[] = ['value' => (string) $label, 'count' => $count];
        }
        usort($out, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Unfiltered totals of the version (KPI "% du total" denominators).
     *
     * @return array{totalClients:int, totalNui:int}
     */
    private function reference(bool $fresh): array
    {
        return $this->remember('reference', $fresh, fn (): array => $this->engine()->reference());
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

    private function label(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? $value : self::EMPTY_LABEL;
    }

    /**
     * Cached per snapshot version: keys never outlive their version's data.
     *
     * @template T
     *
     * @param callable():T $compute
     *
     * @return T
     */
    private function remember(string $key, bool $fresh, callable $compute): mixed
    {
        $cacheKey = 'cl_snap_' . $this->engine()->version() . '_' . $key;

        if (! $fresh && $this->cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $value = $compute();

        if ($this->cacheTtl > 0) {
            $this->cache->save($cacheKey, $value, $this->cacheTtl);
        }

        return $value;
    }
}
