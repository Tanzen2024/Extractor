<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of routing the dashboard COUNT and the export through the
 * same QueryBuilder is that "the number the dashboard shows" and "the number
 * of rows in the file" cannot drift. This pins that they are built from
 * byte-identical WHERE SQL and binds for any given FilterCriteria.
 *
 * (The end-to-end figure parity against real Oracle — filtered dashboard
 * count == filtered export line count — is exercised by the HTTP smoke in
 * the delivery notes, not here, since it needs a live connection.)
 *
 * @internal
 */
final class ExportFilterParityTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = new QueryBuilder();
    }

    private function allowed(): AllowedValues
    {
        return new AllowedValues(
            regions: ['DCUD', 'DCUY', 'DRE'],
            divisions: ['DVC DOUALA NORD'],
            agences: ['CSC_LOGPOM'],
            statuses: ['ACTIVE', 'SUSPENDED (DELINQUENT ACCOUNT)'],
            segmentations: ['1 PERFECT', '8 Autre'],
            segmentsTresor: ['PRIVATE'],
            meters: ['PREPAID', 'POSTPAID', 'Compteurs Communicants'],
            voltages: ['LV', 'MV'],
        );
    }

    /**
     * @return iterable<string, array{array<string,mixed>}>
     */
    public static function filterSets(): iterable
    {
        yield 'no filter'        => [[]];
        yield 'single region'    => [['region' => ['DCUD']]];
        yield 'multi region'     => [['region' => ['DCUD', 'DCUY', 'DRE']]];
        yield 'date range only'  => [['date_from' => '2024-01-01', 'date_to' => '2025-12-31']];
        yield 'open-ended date'  => [['date_from' => '2020-06-15']];
        yield 'niu + meter'      => [['niu_qc' => '1', 'meter' => ['PREPAID', 'Compteurs Communicants']]];
        yield 'the works'        => [[
            'date_from' => '2023-01-01', 'date_to' => '2026-01-01',
            'region' => ['DCUD', 'DCUY'], 'division' => ['DVC DOUALA NORD'],
            'agence' => ['CSC_LOGPOM'], 'status' => ['ACTIVE'],
            'segmentation' => ['8 Autre'], 'segment_tresor' => ['PRIVATE'],
            'meter' => ['POSTPAID'], 'voltage' => ['LV'], 'niu_qc' => '0',
        ]];
    }

    /**
     * @dataProvider filterSets
     *
     * @param array<string, mixed> $get
     */
    public function testCountAndExportShareTheExactSameWhereAndBinds(array $get): void
    {
        $criteria = FilterCriteria::fromRequest($get, $this->allowed());

        $where       = $this->qb->where($criteria);
        $countStmt   = $this->qb->countStatement($where);
        $exportStmt  = $this->qb->exportStatement($criteria);

        // Same binds, same order.
        $this->assertSame($countStmt['binds'], $exportStmt['binds']);

        // Same WHERE fragment inside each full statement.
        $countWhere  = $this->extractWhere($countStmt['sql']);
        $exportWhere = $this->extractWhere($exportStmt['sql']);
        $this->assertSame($countWhere, $exportWhere);
    }

    public function testAnEmptyCriteriaExportsTheWholeTableWithNoBinds(): void
    {
        $stmt = $this->qb->exportStatement(FilterCriteria::none());

        $this->assertSame([], $stmt['binds']);
        $this->assertStringNotContainsString('WHERE', $stmt['sql']);
    }

    private function extractWhere(string $sql): string
    {
        $pos = stripos($sql, ' WHERE ');

        return $pos === false ? '' : trim(substr($sql, $pos + 7));
    }
}
