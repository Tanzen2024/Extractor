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
            'niu_qc'    => '1',
        ]));

        $this->assertStringContainsString('REGION IN (:f', $w['sql']);
        $this->assertStringContainsString("DATE_AB >= TO_DATE(:f", $w['sql']);
        $this->assertStringContainsString("DATE_AB < TO_DATE(:f", $w['sql']);
        $this->assertStringContainsString('NIU_QC = :f', $w['sql']);

        // No literal filter value anywhere in the SQL string.
        $this->assertStringNotContainsString('DCUD', $w['sql']);
        $this->assertStringNotContainsString('ACTIVE', $w['sql']);
        $this->assertStringNotContainsString('2024-01-01', $w['sql']);

        $this->assertContains('DCUD', $w['binds']);
        $this->assertContains('DCUY', $w['binds']);
        $this->assertContains('ACTIVE', $w['binds']);
        $this->assertContains('2024-01-01', $w['binds']);
        $this->assertContains(1, $w['binds']);

        // Placeholder count matches bind count.
        preg_match_all('/:f\d+/', $w['sql'], $m);
        $this->assertCount(count($w['binds']), array_unique($m[0]));
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

    public function testKpiStatementCarriesTheConfiguredBusinessRules(): void
    {
        $stmt = $this->qb->kpiStatement(['sql' => '', 'binds' => []]);

        $this->assertSame('ACTIVE%', $stmt['binds']['active']);
        $this->assertSame(' ', $stmt['binds']['blank']);
        $this->assertContains('PREPAID', $stmt['binds']);
        $this->assertContains('Compteurs Communicants', $stmt['binds']);
        $this->assertStringContainsString('CONTACT_OK', $stmt['sql']);
        $this->assertStringContainsString('> :blank', $stmt['sql']);
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
}
