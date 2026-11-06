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
 * PERIMETER: every answer — counts, KPIs, reference, charts, options, pages —
 * covers ActiveCustomers only (STATUS in FilterCriteria::effectiveStatuses(),
 * FilterCriteria::none() included). Excluded rows stay in the source files
 * but never reach a result.
 *
 * Business rules: metered METER values (Config\Oracle), "NUI correct" =
 * NUI_QC contains CORRECT (case-insensitive), a blank is a value that trims
 * to ''.
 */
interface SnapshotQueryEngine
{
    /** The snapshot version every answer describes. */
    public function version(): string;

    /** That version (its meta: row count, dates). */
    public function snapshot(): ActiveSnapshot;

    /** Rows matching $criteria (and the table's free-text $search, if any). */
    public function count(FilterCriteria $criteria, string $search = ''): int;

    /**
     * Every KPI figure for $criteria:
     *   total, actifs, avecCompteur, nuiCorrect (distinct CONTRACT counts;
     *   actifs = contracts with an active status, i.e. total, recounted on
     *   ActiveCustomers::STATUSES as a guard)
     *   contactOk (rows with a phone number or an e-mail)
     *
     * @param list<string> $meteredMeters
     *
     * @return array{total:int, actifs:int, avecCompteur:int, nuiCorrect:int, contactOk:int}
     */
    public function kpis(FilterCriteria $criteria, array $meteredMeters): array;

    /**
     * kpis() and distributions() for the same criteria, in one call (the
     * dashboard's stats — one DuckDB process instead of two).
     *
     * @param list<string> $meteredMeters
     *
     * @return array{kpis: array{total:int, actifs:int, avecCompteur:int, nuiCorrect:int, contactOk:int}, distributions: array{region: array<string,int>, status: array<string,int>, segmentation: array<string,int>, meterType: array<string,int>, failure: array<string,int>}}
     */
    public function summary(FilterCriteria $criteria, array $meteredMeters): array;

    /**
     * Denominators without user filter: every in-perimeter contract, and
     * every in-perimeter contract with a NUI_QC verdict.
     *
     * @return array{totalClients:int, totalNui:int}
     */
    public function reference(): array;

    /**
     * Row counts per raw value of REGION, STATUS, SEGMENTATION and METER,
     * and per component of the 6 Défaillant profile (FailureSubcategories;
     * rows without one are not listed).
     *
     * @return array{region: array<string,int>, status: array<string,int>, segmentation: array<string,int>, meterType: array<string,int>, failure: array<string,int>}
     */
    public function distributions(FilterCriteria $criteria): array;

    /**
     * Row counts per raw SEGMENTATION value.
     *
     * @return array<string,int>
     */
    public function segmentationCounts(FilterCriteria $criteria): array;

    /**
     * The filter UI's options over the in-perimeter rows — the shape of
     * SnapshotFilterOptions::build() (value lists with counts, geo tree,
     * DATE_AB bounds): never an excluded status, nor a value held only by
     * excluded customers.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array;

    /**
     * One table page. $sort is one of QueryBuilder::SORTABLE (null = CONTRACT),
     * ties broken by CONTRACT; dates as 'YYYY-MM-DD'; every
     * QueryBuilder::ALL_COLUMNS key present ('' when the snapshot lacks it).
     *
     * @return list<array<string, string|null>>
     */
    public function page(FilterCriteria $criteria, string $search, ?string $sort, string $dir, int $offset, int $limit): array;
}
