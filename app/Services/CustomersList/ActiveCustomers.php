<?php

namespace App\Services\CustomersList;

/**
 * THE functional perimeter of Extractor: only customers whose CONTRACT
 * STATUS is one of the four business-active statuses exist for the
 * application — filters, filter options, KPIs, charts, segmentation counts,
 * table, counts and CSV / XLSX exports (and therefore the PNG / CSV / XLSX
 * chart exports, drawn from the same answers).
 *
 * Applied where the rows are selected, never in the display:
 *   - FilterCriteria::effectiveStatuses() is the STATUS condition of every
 *     WHERE (SnapshotDuckDbEngine, RowMatcher for the exports and the PHP
 *     reference engine, QueryBuilder for the Oracle CLI tools) — an empty
 *     STATUT selection means "the 4 active statuses", never "every status";
 *   - FilterCriteria / AllowedValues only accept a status from this list,
 *     so no filter can bring an excluded customer back;
 *   - SnapshotFilterOptions and the engines' filterOptions() only describe
 *     in-perimeter rows.
 * The source data is never altered: the snapshot CSV, its DuckDB index and
 * Oracle keep every row; out-of-perimeter rows are just never selected.
 *
 * Values are the EXACT spellings found in CMS_RFC.TB_CUSTOMERS_LIST (and so
 * in the snapshot), checked on the 2026-10-03 snapshot (8 distinct values):
 * the business labels "SUSPENDED (DELINQUANT ACCOUNT)" and "INACTIVATION IN
 * PROCESS" are spelled "SUSPENDED (DELINQUENT ACCOUNT)" and "INACTIVATION IN
 * PROCESS." (trailing period) by the source. Matching is exact on the
 * trimmed value — no case folding, no pattern — so nothing else can match:
 * INACTIVE WITH BALANCE., INACTIVE., IN PROCESS (PENDING CONNECTION),
 * IN PROCESS (PENDING READING), blank, NULL and any unknown value are out.
 */
final class ActiveCustomers
{
    /** @var list<string> */
    public const STATUSES = [
        'ACTIVE',
        'ACTIVE (PENDING BILLING)',
        'SUSPENDED (DELINQUENT ACCOUNT)',
        'INACTIVATION IN PROCESS.',
    ];

    /** Source column holding the contract status. */
    public const STATUS_COLUMN = 'STATUS';

    /**
     * Tag mixed into every cache key computed under this perimeter, so no
     * figure cached under the former (all statuses) rules can be served.
     */
    public const SCOPE = 'active4';

    /**
     * Key stamped with SCOPE into export_jobs.filters by the jobs created
     * under this perimeter: a job file generated before it (all statuses)
     * is never served again (ExportJobController::download()).
     */
    public const JOB_MARKER = 'perimeter';

    /** True when an export job's stored filters were created under this perimeter. */
    public static function jobInScope(?string $filtersJson): bool
    {
        $filters = json_decode((string) $filtersJson, true);

        return is_array($filters) && ($filters[self::JOB_MARKER] ?? null) === self::SCOPE;
    }

    /**
     * True only for one of the four statuses. Surrounding blanks are
     * ignored (the filter columns are compared trimmed everywhere, see
     * RowMatcher); anything else — case, null, '' — is out.
     */
    public static function isActive(?string $status): bool
    {
        return $status !== null && in_array(trim($status), self::STATUSES, true);
    }

    /**
     * The active statuses among $statuses, de-duplicated, in their order.
     *
     * @param list<string> $statuses
     *
     * @return list<string>
     */
    public static function restrict(array $statuses): array
    {
        $out = [];
        foreach ($statuses as $status) {
            $status = (string) $status;
            if (in_array($status, self::STATUSES, true) && ! in_array($status, $out, true)) {
                $out[] = $status;
            }
        }

        return $out;
    }
}
