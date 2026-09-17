<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * QueryBuilder is the only place filter values become SQL. These tests pin
 * that (a) every value is a bind placeholder, never inlined, (b) the sort
 * column is whitelisted, and (c) LIKE metacharacters in a search term are
 * escaped.
 *
 * @internal
 */
final class CustomersListQueryBuilderTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = new QueryBuilder();
    }

    private function allowed(): AllowedValues
    {
        return new AllowedValues(
            regions: ['DCUD', 'DCUY'],
            divisions: ['DVC DOUALA NORD'],
            agences: ['CSC_LOGPOM'],
            statuses: ['ACTIVE', 'INACTIVE.'],
            segmentations: ['1 PERFECT'],
            segmentsTresor: ['PRIVATE'],
            meters: ['PREPAID', 'POSTPAID'],
            voltages: ['LV'],
            niuQualities: ['NUI correct', 'NUI a RECLASSER'],
        );
    }

    private function criteria(array $get): FilterCriteria
    {
        return FilterCriteria::fromRequest($get, $this->allowed());
    }

    public function testEmptyCriteriaProducesNoWhere(): void
    {
        $w = $this->qb->where(FilterCriteria::none());

        $this->assertSame('', $w['sql']);
        $this->assertSame([], $w['binds']);
    }

    public function testEveryValueIsBoundNeverInlined(): void
    {
        $w = $this->qb->where($this->criteria([
            'region'    => ['DCUD', 'DCUY'],
            'status'    => ['ACTIVE'],
            'date_from' => '2024-01-01',
            'date_to'   => '2025-12-31',
            'niu_qc'    => ['NUI a RECLASSER'],
        ]));

        $this->assertStringContainsString('REGION IN (:f', $w['sql']);
        $this->assertStringContainsString("DATE_AB >= TO_DATE(:f", $w['sql']);
        $this->assertStringContainsString("DATE_AB < TO_DATE(:f", $w['sql']);
        $this->assertStringContainsString('NUI_QC IN (:f', $w['sql']);

        // No literal filter value anywhere in the SQL string.
        $this->assertStringNotContainsString('DCUD', $w['sql']);
        $this->assertStringNotContainsString('ACTIVE', $w['sql']);
        $this->assertStringNotContainsString('2024-01-01', $w['sql']);

        $this->assertContains('DCUD', $w['binds']);
        $this->assertContains('DCUY', $w['binds']);
        $this->assertContains('ACTIVE', $w['binds']);
        $this->assertContains('2024-01-01', $w['binds']);
        $this->assertContains('NUI a RECLASSER', $w['binds']);

        // Placeholder count matches bind count.
        preg_match_all('/:f\d+/', $w['sql'], $m);
        $this->assertCount(count($w['binds']), array_unique($m[0]));
    }

    public function testWhereFiltersMeterTypeByTheRealMeterColumn(): void
    {
        // The "Type de compteur" filter (app-facing "meter") must read the
        // real Oracle column METER — re-verified live against ALL_TAB_COLUMNS
        // on 2026-09-17 (DASH-20260917-96667: METER_TECHNOLOGY does not exist
        // and raised ORA-00904 on every dashboard endpoint).
        $w = $this->qb->where($this->criteria(['meter' => ['PREPAID', 'POSTPAID']]));

        $this->assertStringContainsString('METER IN (:f', $w['sql']);
        $this->assertStringNotContainsString('METER_TECHNOLOGY IN (', $w['sql']);
        $this->assertContains('PREPAID', $w['binds']);
        $this->assertContains('POSTPAID', $w['binds']);
    }

    public function testDateToIsInclusiveOfItsWholeDay(): void
    {
        $w = $this->qb->where($this->criteria(['date_to' => '2025-12-31']));

        $this->assertStringContainsString("+ 1", $w['sql']); // < end + 1 day
    }

    public function testOrderByEnforcesTheWhitelist(): void
    {
        $this->assertSame('ORDER BY CUST_NAME ASC, CONTRACT ASC', $this->qb->orderBy('CUST_NAME', 'asc'));
        $this->assertSame('ORDER BY CONTRACT DESC', $this->qb->orderBy('CONTRACT', 'desc'));

        // Injection attempt / unknown column -> safe default.
        $this->assertSame('ORDER BY CONTRACT ASC', $this->qb->orderBy('CONTRACT; DROP TABLE X', 'asc'));
        $this->assertSame('ORDER BY CONTRACT ASC', $this->qb->orderBy('SECRET_COLUMN', 'sideways'));
    }

    public function testSearchEscapesLikeMetacharacters(): void
    {
        $base = ['sql' => '', 'binds' => []];
        $out  = $this->qb->withSearch($base, '50%_x');

        $this->assertStringContainsString("LIKE :q ESCAPE '\\'", $out['sql']);
        $this->assertSame('%50\\%\\_X%', $out['binds']['q']);
    }

    public function testSearchIsAppendedWithAnd(): void
    {
        $w   = $this->qb->where($this->criteria(['region' => ['DCUD']]));
        $out = $this->qb->withSearch($w, ' dupont ');

        $this->assertStringContainsString(' AND (UPPER(CUST_NAME) LIKE :q', $out['sql']);
        $this->assertSame('%DUPONT%', $out['binds']['q']);
    }

    public function testBlankSearchIsANoOp(): void
    {
        $w   = $this->qb->where(FilterCriteria::none());
        $out = $this->qb->withSearch($w, '   ');

        $this->assertSame($w, $out);
    }

    public function testPageStatementBindsOffsetAndLimit(): void
    {
        $stmt = $this->qb->pageStatement(['sql' => '', 'binds' => []], 'ORDER BY CONTRACT ASC', 100, 50);

        $this->assertStringContainsString('OFFSET :p_off ROWS FETCH NEXT :p_lim ROWS ONLY', $stmt['sql']);
        $this->assertSame(100, $stmt['binds']['p_off']);
        $this->assertSame(50, $stmt['binds']['p_lim']);
        $this->assertStringContainsString("TO_CHAR(DATE_AB, 'YYYY-MM-DD')", $stmt['sql']);
    }

    public function testPageStatementSelectsAllTwentySevenColumnsWithUpdatedAtLast(): void
    {
        $stmt = $this->qb->pageStatement(['sql' => '', 'binds' => []], 'ORDER BY CONTRACT ASC', 0, 50);

        foreach (QueryBuilder::ALL_COLUMNS as $col) {
            $this->assertStringContainsString($col, $stmt['sql'], "pageStatement() must select {$col}.");
        }

        // UPDATED_AT is VARCHAR2(19) in Oracle, already formatted text — NOT
        // a DATE column despite its name. Wrapping it in TO_CHAR(..., date
        // fmt) is exactly the bug behind DASH-20260916-31939 (ORA-01722):
        // it must be selected bare, like NIU_TO_RECLASS/NUI_QC.
        $this->assertStringNotContainsString('TO_CHAR(UPDATED_AT', $stmt['sql']);
        // LAST_VC_DATE / POSTPAID_PROFILE_DATE are real DATE columns, so —
        // unlike UPDATED_AT — they DO need the same TO_CHAR treatment as
        // DATE_AB/DATE_RESILIATION.
        $this->assertStringContainsString("TO_CHAR(LAST_VC_DATE, 'YYYY-MM-DD')", $stmt['sql']);
        $this->assertStringContainsString("TO_CHAR(POSTPAID_PROFILE_DATE, 'YYYY-MM-DD')", $stmt['sql']);

        // UPDATED_AT must be the last selected expression before " FROM ".
        $select = substr($stmt['sql'], 0, strpos($stmt['sql'], ' FROM '));
        $this->assertStringEndsWith('UPDATED_AT', trim($select));
    }

    public function testAllColumnsListsExactlyTheTwentySevenSelectableColumnsInCanonicalOrder(): void
    {
        // The full selector list (2026-09-17 addition of the remaining 10
        // CMS_RFC.TB_CUSTOMERS_LIST columns), in the table's own order.
        $this->assertSame([
            'REGION', 'DIVISION', 'AGENCE', 'COD_UNICOM', 'COD_CLI', 'CONTRACT', 'STATUS',
            'METER_NO', 'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'REF_GEO', 'DATE_AB',
            'DATE_RESILIATION', 'VOLTAGE', 'SEGMENT_TRESOR', 'METER', 'NIU_RIGHT', 'XCOORD',
            'YCOORD', 'NIU_TO_RECLASS', 'NUI_QC', 'LAST_VC_DATE', 'SEGMENT_RFM_2',
            'POSTPAID_PROFILE_DATE', 'SEGMENTATION', 'UPDATED_AT',
        ], QueryBuilder::ALL_COLUMNS);
        $this->assertCount(27, QueryBuilder::ALL_COLUMNS);
        $this->assertSame(27, count(array_unique(QueryBuilder::ALL_COLUMNS)), 'ALL_COLUMNS must not contain duplicates.');
    }

    public function testDefaultVisibleColumnsIsTheFourteenHistoricalColumnsPlusNiuRightAndNuiQc(): void
    {
        $default = QueryBuilder::DEFAULT_VISIBLE_COLUMNS;

        $this->assertCount(16, $default, 'DEFAULT_VISIBLE_COLUMNS must total 16.');
        $this->assertSame(16, count(array_unique($default)), 'DEFAULT_VISIBLE_COLUMNS must not contain duplicates.');

        // Every default column must be one of the 27 selectable columns.
        foreach ($default as $col) {
            $this->assertContains($col, QueryBuilder::ALL_COLUMNS, "{$col} must be one of ALL_COLUMNS.");
        }

        $this->assertContains('NIU_RIGHT', $default);
        $this->assertContains('NUI_QC', $default);

        // NIU_TO_RECLASS is selectable but NOT part of the default selection.
        $this->assertNotContains('NIU_TO_RECLASS', $default);

        // The 14 historical columns are untouched.
        foreach ([
            'REGION', 'DIVISION', 'AGENCE', 'COD_CLI', 'CONTRACT', 'STATUS', 'METER_NO',
            'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'DATE_AB', 'DATE_RESILIATION',
            'SEGMENTATION', 'UPDATED_AT',
        ] as $col) {
            $this->assertContains($col, $default, "{$col} is one of the 14 historical columns and must stay in the default selection.");
        }
    }

    public function testKpiStatementCarriesTheConfiguredBusinessRules(): void
    {
        $stmt = $this->qb->kpiStatement(['sql' => '', 'binds' => []]);

        $this->assertSame(' ', $stmt['binds']['blank']);
        $this->assertContains('PREPAID', $stmt['binds']);
        $this->assertContains('Compteurs Communicants', $stmt['binds']);
        $this->assertContains('ACTIVE', $stmt['binds']);
        $this->assertContains('ACTIVE (PENDING BILLING)', $stmt['binds']);
        $this->assertContains('INACTIVATION IN PROCESS.', $stmt['binds']);
        $this->assertContains('SUSPENDED (DELINQUENT ACCOUNT)', $stmt['binds']);
        $this->assertStringContainsString('CONTACT_OK', $stmt['sql']);
        $this->assertStringContainsString('> :blank', $stmt['sql']);
    }

    public function testKpiStatementCountsTotalActifsAndAvecCompteurByDistinctContract(): void
    {
        $stmt = $this->qb->kpiStatement(['sql' => '', 'binds' => []]);

        // TOTAL / ACTIFS / AVEC_COMPTEUR are explicit CONTRACT counts — a
        // deliberate business decision (the "Clients ..." KPI labels keep
        // counting contracts, not distinct COD_CLI). CONTACT_OK stays a
        // plain row SUM, untouched by that decision.
        $this->assertStringContainsString('COUNT(DISTINCT CONTRACT) TOTAL', $stmt['sql']);
        $this->assertStringContainsString('COUNT(DISTINCT CASE WHEN STATUS IN (', $stmt['sql']);
        $this->assertStringContainsString('THEN CONTRACT END) ACTIFS', $stmt['sql']);
        // METER — see DASH-20260917-96667 audit (METER_TECHNOLOGY doesn't
        // exist; ORA-00904 on every dashboard endpoint until fixed).
        $this->assertStringContainsString('COUNT(DISTINCT CASE WHEN METER IN (', $stmt['sql']);
        $this->assertStringContainsString('THEN CONTRACT END) AVEC_COMPTEUR', $stmt['sql']);
        $this->assertStringContainsString('SUM(CASE WHEN PHONE_NUMBERS > :blank OR E_MAIL > :blank THEN 1 ELSE 0 END) CONTACT_OK', $stmt['sql']);
        // No SQL function wraps the STATUS column itself (stays index-friendly).
        $this->assertStringNotContainsString('UPPER(STATUS', $stmt['sql']);
        $this->assertStringNotContainsString('LOWER(STATUS', $stmt['sql']);
        $this->assertStringNotContainsString('TRIM(STATUS', $stmt['sql']);
    }

    public function testExportStatementReusesTheSharedWhere(): void
    {
        $criteria = $this->criteria(['region' => ['DCUD'], 'meter' => ['PREPAID']]);

        $where  = $this->qb->where($criteria);
        $export = $this->qb->exportStatement($criteria);

        $this->assertStringContainsString('FROM CMS_RFC.TB_CUSTOMERS_LIST', $export['sql']);
        $this->assertStringContainsString('WHERE ' . $where['sql'], $export['sql']);
        $this->assertSame($where['binds'], $export['binds']);
    }

    public function testDistributionsStatementAggregatesMeterTypeInOracleWithTheSharedWhere(): void
    {
        // Filtered by region + status -> the meter-type distribution must
        // scope to exactly the same population as every other dashboard query
        // (one shared WHERE), and Oracle does the GROUP BY / COUNT — never PHP.
        $criteria = $this->criteria(['region' => ['DCUD'], 'status' => ['ACTIVE']]);
        $where    = $this->qb->where($criteria);
        $stmt     = $this->qb->distributionsStatement($where);

        $this->assertStringContainsString("'meterType'", $stmt['sql']);
        // METER — see DASH-20260917-96667 audit.
        $this->assertStringContainsString('(METER)', $stmt['sql']);
        $this->assertStringContainsString('GROUP BY GROUPING SETS', $stmt['sql']);
        $this->assertStringContainsString('COUNT(*) N', $stmt['sql']);
        $this->assertStringContainsString('REGION IN (', $where['sql']);
        $this->assertStringContainsString('STATUS IN (', $where['sql']);
        $this->assertStringContainsString('WHERE ' . $where['sql'], $stmt['sql']);
        $this->assertSame($where['binds'], $stmt['binds']);
    }
}
