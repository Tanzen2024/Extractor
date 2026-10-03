<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\FilterCriteria;

/**
 * Read queries of the dashboard over ONE snapshot version — the only data
 * access of DashboardService (filters, KPIs, charts, segmentation counts,
 * table, count). Implementations never touch Oracle: they read the version's
 * customers_list.csv, directly or through an index built from it.
 *
 * Filter semantics are RowMatcher's (the export's): the 9 list filters match
 * the trimmed value exactly, DATE_AB bounds are inclusive at day level, a
 * date filter excludes rows whose DATE_AB is unreadable.
 *
 * Business rules (Config\Oracle, unchanged): active statuses, metered METER
 * values, "NUI correct" = NUI_QC contains CORRECT (case-insensitive), a blank
 * is a value that trims to ''.
 */
interface SnapshotQueryEngine
{
    /** The snapshot version every answer describes. */
    public function version(): string;

    /** That version (its meta: filter options, row count, dates). */
    public function snapshot(): ActiveSnapshot;

    /** Rows matching $criteria (and the table's free-text $search, if any). */
    public function count(FilterCriteria $criteria, string $search = ''): int;

    /**
     * Every KPI figure for $criteria:
     *   total, actifs, inactifs, avecCompteur, nuiCorrect (CONTRACT counts)
     *   contactOk (rows with a phone number or an e-mail)
     *
     * @param list<string> $activeStatuses
     * @param list<string> $meteredMeters
     *
     * @return array{total:int, actifs:int, inactifs:int, avecCompteur:int, nuiCorrect:int, contactOk:int}
     */
    public function kpis(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array;

    /**
     * kpis() and distributions() for the same criteria, in one call (the
     * dashboard's stats — one DuckDB process instead of two).
     *
     * @param list<string> $activeStatuses
     * @param list<string> $meteredMeters
     *
     * @return array{kpis: array{total:int, actifs:int, inactifs:int, avecCompteur:int, nuiCorrect:int, contactOk:int}, distributions: array{region: array<string,int>, status: array<string,int>, segmentation: array<string,int>, meterType: array<string,int>}}
     */
    public function summary(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array;

    /**
     * Unfiltered denominators: every contract, and every contract with a NUI_QC verdict.
     *
     * @return array{totalClients:int, totalNui:int}
     */
    public function reference(): array;

    /**
     * Row counts per raw value of REGION, STATUS, SEGMENTATION and METER.
     *
     * @return array{region: array<string,int>, status: array<string,int>, segmentation: array<string,int>, meterType: array<string,int>}
     */
    public function distributions(FilterCriteria $criteria): array;

    /**
     * Row counts per raw SEGMENTATION value.
     *
     * @return array<string,int>
     */
    public function segmentationCounts(FilterCriteria $criteria): array;

    /**
     * One table page. $sort is one of QueryBuilder::SORTABLE (null = CONTRACT),
     * ties broken by CONTRACT; dates as 'YYYY-MM-DD'; every
     * QueryBuilder::ALL_COLUMNS key present ('' when the snapshot lacks it).
     *
     * @return list<array<string, string|null>>
     */
    public function page(FilterCriteria $criteria, string $search, ?string $sort, string $dir, int $offset, int $limit): array;
}
