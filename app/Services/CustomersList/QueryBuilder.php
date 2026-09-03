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

    /** Columns shown in the dashboard data table (subset of the 23 exported). */
    public const TABLE_COLUMNS = [
        'REGION', 'DIVISION', 'AGENCE', 'COD_CLI', 'CONTRACT', 'STATUS', 'METER_NO',
        'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'DATE_AB', 'DATE_RESILIATION', 'SEGMENTATION',
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
            'METER'          => $c->meters,
            'VOLTAGE'        => $c->voltages,
        ] as $column => $values) {
            if ($values === []) {
                continue;
            }

            $placeholders = array_map($bind, $values);
            $conds[]      = $column . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if ($c->niuQc !== null) {
            // Real column in CMS_RFC.TB_CUSTOMERS_LIST is NUI_QC (not NIU_QC) —
            // a transposed-letter name in the source schema. Selecting/filtering
            // the wrong spelling raises ORA-00904.
            $conds[] = 'NUI_QC = ' . $bind($c->niuQc);
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

        $binds['active']  = $this->config->activeStatusPattern;
        $binds['blank']   = ' ';
        $metered          = $this->config->meteredMeterValues;
        $meterPlaceholders = [];
        foreach (array_values($metered) as $i => $value) {
            $key                 = 'meter' . $i;
            $binds[$key]         = $value;
            $meterPlaceholders[] = ':' . $key;
        }
        $meterIn = implode(', ', $meterPlaceholders) ?: "NULL";

        // NOTE: the text columns store " " (a single space), not NULL, for
        // "no value". `col > :blank` (blank = a single space) is the "has real
        // content" test: NULL -> NULL (uncounted), " " -> false, any real
        // string -> true. Used here only for the "Contacts renseignés" KPI.
        $sql = 'SELECT
                COUNT(*) TOTAL,
                SUM(CASE WHEN STATUS LIKE :active THEN 1 ELSE 0 END) ACTIFS,
                SUM(CASE WHEN METER IN (' . $meterIn . ') THEN 1 ELSE 0 END) AVEC_COMPTEUR,
                SUM(CASE WHEN PHONE_NUMBERS > :blank OR E_MAIL > :blank THEN 1 ELSE 0 END) CONTACT_OK
            FROM ' . self::TABLE . $this->whereSuffix($where['sql']);

        return ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * Region / status / segmentation / meter-type distributions in one full
     * scan via GROUP BY GROUPING SETS. Rows come back with a DIM tag and a
     * VALUE. METER is the meter-type column of CMS_RFC.TB_CUSTOMERS_LIST
     * (real values: PREPAID, POSTPAID, "Compteurs Communicants", plus a blank
     * bucket) — the same column the "Type de compteur" filter and the
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

        $select = 'REGION, DIVISION, AGENCE, COD_CLI, CONTRACT, STATUS, METER_NO, CUST_NAME, '
            . "PHONE_NUMBERS, E_MAIL, TO_CHAR(DATE_AB, 'YYYY-MM-DD') DATE_AB, "
            . "TO_CHAR(DATE_RESILIATION, 'YYYY-MM-DD') DATE_RESILIATION, SEGMENTATION";

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
