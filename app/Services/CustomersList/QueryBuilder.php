<?php

namespace App\Services\CustomersList;

use App\Services\CustomerListExportService;
use Config\Oracle as OracleConfig;

/**
 * Turns a validated FilterCriteria into parameterised Oracle SQL.
 *
 * Every value the user chose travels as an oci_bind_by_name placeholder —
 * this class only ever concatenates column names, and only from its own
 * constants. The same where() output feeds the dashboard's COUNT, the KPI
 * scan, the distributions, the paginated table and the export, so all five
 * describe exactly the same population.
 */
final class QueryBuilder
{
    public const TABLE = CustomerListExportService::SQL_TABLE;

    /**
     * All 27 columns selectable in the dashboard data table's existing column
     * selector, in CMS_RFC.TB_CUSTOMERS_LIST's own canonical order. This is
     * the full "available" list — NOT the initial/default selection (see
     * DEFAULT_VISIBLE_COLUMNS); see pageStatement() for how each is
     * formatted (dates via TO_CHAR, UPDATED_AT bare).
     */
    public const ALL_COLUMNS = [
        'REGION', 'DIVISION', 'AGENCE', 'COD_UNICOM', 'COD_CLI', 'CONTRACT', 'STATUS',
        'METER_NO', 'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'REF_GEO', 'DATE_AB',
        'DATE_RESILIATION', 'VOLTAGE', 'SEGMENT_TRESOR', 'METER', 'NIU_RIGHT', 'XCOORD',
        'YCOORD', 'NIU_TO_RECLASS', 'NUI_QC', 'LAST_VC_DATE', 'SEGMENT_RFM_2',
        'POSTPAID_PROFILE_DATE', 'SEGMENTATION', 'UPDATED_AT',
    ];

    /**
     * Columns selected/visible on first load (16): the 14 historical
     * dashboard columns, unchanged, plus NIU_RIGHT and NUI_QC. Every other
     * ALL_COLUMNS entry (including NIU_TO_RECLASS) is available in the
     * selector but starts hidden — see dashboard.js's column selector /
     * loadHiddenColumns().
     */
    public const DEFAULT_VISIBLE_COLUMNS = [
        'REGION', 'DIVISION', 'AGENCE', 'COD_CLI', 'CONTRACT', 'STATUS', 'METER_NO',
        'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'DATE_AB', 'DATE_RESILIATION', 'SEGMENTATION',
        'UPDATED_AT', 'NIU_RIGHT', 'NUI_QC',
    ];

    /** Columns the table may be sorted by (must be a safe, indexed-or-not real column). */
    public const SORTABLE = [
        'REGION', 'DIVISION', 'AGENCE', 'COD_CLI', 'CONTRACT', 'STATUS',
        'CUST_NAME', 'DATE_AB', 'DATE_RESILIATION', 'SEGMENTATION',
    ];

    private const DEFAULT_SORT = 'CONTRACT';

    private OracleConfig $config;

    public function __construct(?OracleConfig $config = null)
    {
        $this->config = $config ?? new OracleConfig();
    }

    /**
     * WHERE fragment (without the "WHERE" keyword) + binds for a criteria.
     * Returns an empty string when nothing is filtered.
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function where(FilterCriteria $c): array
    {
        $conds = [];
        $binds = [];
        $n     = 0;
        $bind  = static function (mixed $value) use (&$binds, &$n): string {
            $key         = 'f' . $n++;
            $binds[$key] = $value;

            return ':' . $key;
        };

        if ($c->dateFrom !== null) {
            $conds[] = FilterCriteria::DATE_COLUMN . " >= TO_DATE(" . $bind($c->dateFrom->format('Y-m-d')) . ", 'YYYY-MM-DD')";
        }
        if ($c->dateTo !== null) {
            // + 1 day, strict less-than: makes the end date inclusive of its whole day.
            $conds[] = FilterCriteria::DATE_COLUMN . " < TO_DATE(" . $bind($c->dateTo->format('Y-m-d')) . ", 'YYYY-MM-DD') + 1";
        }

        foreach ([
            'REGION'         => $c->regions,
            'DIVISION'       => $c->divisions,
            'AGENCE'         => $c->agences,
            'STATUS'         => $c->statuses,
            'SEGMENTATION'   => $c->segmentations,
            'SEGMENT_TRESOR' => $c->segmentsTresor,
            // Real column in CMS_RFC.TB_CUSTOMERS_LIST is METER (VARCHAR2(22)),
            // NOT METER_TECHNOLOGY — re-verified live against ALL_TAB_COLUMNS
            // on 2026-09-17 (DASH-20260917-96667: every dashboard endpoint was
            // failing with ORA-00904 "METER_TECHNOLOGY": identificateur non
            // valide). The DASH-20260916-31939 comment this replaces claimed
            // METER_TECHNOLOGY was confirmed instead — that was wrong, or the
            // table was reverted since; live ALL_TAB_COLUMNS is authoritative.
            // $c->meters / the "meter" app dimension keep their existing name.
            'METER' => $c->meters,
            'VOLTAGE'        => $c->voltages,
            // Real column in CMS_RFC.TB_CUSTOMERS_LIST is NUI_QC (not NIU_QC) —
            // a transposed-letter name in the source schema. Selecting/filtering
            // the wrong spelling raises ORA-00904. The filter itself matches the
            // exact live distinct values (see DashboardService::filterOptions()),
            // never a guessed/normalised string — the column holds free text
            // ('NUI correct' / 'NUI ... RECLASSER') with no numeric flag.
            'NUI_QC'         => $c->niuQualities,
        ] as $column => $values) {
            if ($values === []) {
                continue;
            }

            $placeholders = array_map($bind, $values);
            $conds[]      = $column . ' IN (' . implode(', ', $placeholders) . ')';
        }

        return ['sql' => implode(' AND ', $conds), 'binds' => $binds];
    }

    /**
     * Adds a free-text search over a fixed set of columns to an existing
     * where() result. Escapes LIKE metacharacters so a user's "%" is literal.
     *
     * @param array{sql: string, binds: array<string, mixed>} $where
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function withSearch(array $where, string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return $where;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $like    = '%' . mb_strtoupper($escaped) . '%';

        $where['binds']['q'] = $like;
        $clause = "(UPPER(CUST_NAME) LIKE :q ESCAPE '\\' "
            . "OR TO_CHAR(CONTRACT) LIKE :q ESCAPE '\\' "
            . "OR TO_CHAR(COD_CLI) LIKE :q ESCAPE '\\' "
            . "OR UPPER(METER_NO) LIKE :q ESCAPE '\\')";

        $where['sql'] = $where['sql'] === '' ? $clause : $where['sql'] . ' AND ' . $clause;

        return $where;
    }

    public function orderBy(?string $column, string $direction): string
    {
        $column    = in_array($column, self::SORTABLE, true) ? $column : self::DEFAULT_SORT;
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        // CONTRACT is unique, so append it as a tie-breaker for a stable page order.
        $tieBreak = $column === 'CONTRACT' ? '' : ', CONTRACT ASC';

        return "ORDER BY {$column} {$direction}{$tieBreak}";
    }

    // ---- Full statements -------------------------------------------------

    /**
     * @param array{sql: string, binds: array<string, mixed>} $where
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function countStatement(array $where): array
    {
        $sql = 'SELECT COUNT(*) N FROM ' . self::TABLE . $this->whereSuffix($where['sql']);

        return ['sql' => $sql, 'binds' => $where['binds']];
    }

    /**
     * One full-scan query returning every KPI figure in a single pass.
     *
     * @param array{sql: string, binds: array<string, mixed>} $where
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function kpiStatement(array $where): array
    {
        $binds = $where['binds'];

        $binds['blank']   = ' ';
        $metered          = $this->config->meteredMeterValues;
        $meterPlaceholders = [];
        foreach (array_values($metered) as $i => $value) {
            $key                 = 'meter' . $i;
            $binds[$key]         = $value;
            $meterPlaceholders[] = ':' . $key;
        }
        $meterIn = implode(', ', $meterPlaceholders) ?: "NULL";

        $active             = $this->config->activeStatuses;
        $activePlaceholders = [];
        foreach (array_values($active) as $i => $value) {
            $key                  = 'active' . $i;
            $binds[$key]          = $value;
            $activePlaceholders[] = ':' . $key;
        }
        $activeIn = implode(', ', $activePlaceholders) ?: "NULL";

        // NOTE: the text columns store " " (a single space), not NULL, for
        // "no value". `col > :blank` (blank = a single space) is the "has real
        // content" test: NULL -> NULL (uncounted), " " -> false, any real
        // string -> true. Used here only for the "Contacts renseignés" KPI.
        //
        // TOTAL / ACTIFS / AVEC_COMPTEUR are explicit CONTRACT counts
        // (COUNT(DISTINCT CONTRACT), CONTRACT being the row's own unique
        // identifier) — a deliberate business decision: the KPI cards keep
        // their "Clients ..." labels, but count CONTRACTS, not distinct
        // COD_CLI. Do not "fix" this back to COD_CLI without checking with
        // product first — an earlier audit in this project's history did
        // exactly that for ACTIFS (client-based, COUNT(DISTINCT COD_CLI));
        // it was deliberately reverted to CONTRACT-based counting after
        // review. CONTACT_OK is intentionally left as a plain row count —
        // it was not part of that decision.
        $sql = 'SELECT
                COUNT(DISTINCT CONTRACT) TOTAL,
                COUNT(DISTINCT CASE WHEN STATUS IN (' . $activeIn . ') THEN CONTRACT END) ACTIFS,
                COUNT(DISTINCT CASE WHEN METER IN (' . $meterIn . ') THEN CONTRACT END) AVEC_COMPTEUR,
                SUM(CASE WHEN PHONE_NUMBERS > :blank OR E_MAIL > :blank THEN 1 ELSE 0 END) CONTACT_OK
            FROM ' . self::TABLE . $this->whereSuffix($where['sql']);

        return ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * Region / status / segmentation / meter-type distributions in one full
     * scan via GROUP BY GROUPING SETS. Rows come back with a DIM tag and a
     * VALUE. METER is the meter-type column of CMS_RFC.TB_CUSTOMERS_LIST
     * (real values: PREPAID, POSTPAID, "Compteurs Communicants", plus a
     * blank bucket) — the same column the "Type de compteur" filter and the
     * "Clients avec compteur" KPI already read.
     *
     * @param array{sql: string, binds: array<string, mixed>} $where
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function distributionsStatement(array $where): array
    {
        $sql = "SELECT
                CASE
                    WHEN GROUPING(REGION) = 0 THEN 'region'
                    WHEN GROUPING(STATUS) = 0 THEN 'status'
                    WHEN GROUPING(SEGMENTATION) = 0 THEN 'segmentation'
                    ELSE 'meterType'
                END DIM,
                COALESCE(REGION, STATUS, SEGMENTATION, METER) VAL,
                COUNT(*) N
            FROM " . self::TABLE . $this->whereSuffix($where['sql']) . "
            GROUP BY GROUPING SETS ((REGION), (STATUS), (SEGMENTATION), (METER))";

        return ['sql' => $sql, 'binds' => $where['binds']];
    }

    /**
     * A single page of the data table.
     *
     * @param array{sql: string, binds: array<string, mixed>} $where
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function pageStatement(array $where, string $orderBy, int $offset, int $limit): array
    {
        $binds           = $where['binds'];
        $binds['p_off']  = $offset;
        $binds['p_lim']  = $limit;

        // UPDATED_AT is VARCHAR2(19) in CMS_RFC.TB_CUSTOMERS_LIST, already
        // formatted as 'YYYY-MM-DD HH24:MI:SS' text — NOT a DATE column,
        // despite the name. Wrapping it in TO_CHAR(UPDATED_AT, <date fmt>)
        // made Oracle resolve the NUMBER-argument overload of TO_CHAR and
        // attempt an implicit VARCHAR2->NUMBER conversion on a non-numeric
        // string, raising ORA-01722 on every single row (root cause of
        // DASH-20260916-31939, confirmed against ALL_TAB_COLUMNS). Select it
        // bare, like NIU_TO_RECLASS, and pass the real value through as-is.
        // LAST_VC_DATE and POSTPAID_PROFILE_DATE are DATE columns too (like
        // DATE_AB/DATE_RESILIATION) — same TO_CHAR treatment, for the same
        // reason: a bare DATE column returned via OCI8 isn't the plain
        // 'YYYY-MM-DD' string the frontend's date formatting expects.
        $select = 'REGION, DIVISION, AGENCE, COD_UNICOM, COD_CLI, CONTRACT, STATUS, METER_NO, CUST_NAME, '
            . "PHONE_NUMBERS, E_MAIL, REF_GEO, TO_CHAR(DATE_AB, 'YYYY-MM-DD') DATE_AB, "
            . "TO_CHAR(DATE_RESILIATION, 'YYYY-MM-DD') DATE_RESILIATION, VOLTAGE, SEGMENT_TRESOR, "
            . "METER, NIU_RIGHT, XCOORD, YCOORD, NIU_TO_RECLASS, NUI_QC, "
            . "TO_CHAR(LAST_VC_DATE, 'YYYY-MM-DD') LAST_VC_DATE, SEGMENT_RFM_2, "
            . "TO_CHAR(POSTPAID_PROFILE_DATE, 'YYYY-MM-DD') POSTPAID_PROFILE_DATE, "
            . 'SEGMENTATION, UPDATED_AT';

        $sql = "SELECT {$select} FROM " . self::TABLE . $this->whereSuffix($where['sql']) . "
            {$orderBy}
            OFFSET :p_off ROWS FETCH NEXT :p_lim ROWS ONLY";

        return ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * The export SELECT (all 23 columns, verbatim) plus the shared WHERE.
     *
     * @return array{sql: string, binds: array<string, mixed>}
     */
    public function exportStatement(FilterCriteria $c): array
    {
        $where = $this->where($c);
        $sql   = CustomerListExportService::SQL_SELECT . $this->whereSuffix($where['sql']);

        return ['sql' => $sql, 'binds' => $where['binds']];
    }

    private function whereSuffix(string $whereSql): string
    {
        return $whereSql === '' ? '' : ' WHERE ' . $whereSql;
    }
}
