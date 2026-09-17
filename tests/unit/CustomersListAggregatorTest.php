<?php

use App\Services\CustomersListAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Pure aggregation logic exercised with fixture rows shaped exactly like
 * CUSTOMERS_LIST results (see app/Models/Extractor.sql) — no Oracle or
 * database dependency, since CustomersListAggregator only ever sees
 * already-fetched associative rows.
 *
 * @internal
 */
final class CustomersListAggregatorTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'REGION'         => 'NORD',
            'DIVISION'       => 'DIVISION A',
            'AGENCE'         => 'AGENCE 001',
            'COD_CLI'        => 'CLI-1',
            'STATUS'         => 'ACTIVE',
            'METER'          => 'POSTPAID',
            'NIU_QC'         => 0,
            'SEGMENTATION'   => '1 Actif',
            'SEGMENT_TRESOR' => 'T1',
            'SEGMENT_RFM_2'  => null,
        ], $overrides);
    }

    public function testEmptyResultProducesZeroedTotals(): void
    {
        $result = (new CustomersListAggregator())->result();

        $this->assertSame(0, $result['row_count']);
        $this->assertSame(0, $result['distinct_client_count']);
        $this->assertSame([], $result['dimensions']['region']);
        $this->assertSame([], $result['cube']);
    }

    public function testCountsRowsAndDistinctClients(): void
    {
        $aggregator = new CustomersListAggregator();

        // Same client, two contracts -> counted once for distinct_client_count
        // but twice for row_count and every per-row breakdown.
        $aggregator->add($this->row(['COD_CLI' => 'CLI-1']));
        $aggregator->add($this->row(['COD_CLI' => 'CLI-1']));
        $aggregator->add($this->row(['COD_CLI' => 'CLI-2']));

        $result = $aggregator->result();

        $this->assertSame(3, $result['row_count']);
        $this->assertSame(2, $result['distinct_client_count']);
    }

    public function testMeterAndNiuTotalsMatchTaskDefinitions(): void
    {
        $aggregator = new CustomersListAggregator();

        $aggregator->add($this->row(['METER' => 'PREPAID', 'NIU_QC' => 0, 'SEGMENT_RFM_2' => 'RFM 1']));
        $aggregator->add($this->row(['METER' => 'PREPAID', 'NIU_QC' => 1, 'SEGMENT_RFM_2' => 'RFM 2']));
        $aggregator->add($this->row(['METER' => 'POSTPAID', 'NIU_QC' => 0]));
        $aggregator->add($this->row(['METER' => 'Compteurs Communicants', 'NIU_QC' => 1]));

        $result = $aggregator->result();

        $this->assertSame(2, $result['totals']['meter']['PREPAID']);
        $this->assertSame(1, $result['totals']['meter']['POSTPAID']);
        $this->assertSame(1, $result['totals']['meter']['Compteurs Communicants']);
        $this->assertSame(2, $result['totals']['niu_qc']['0']);
        $this->assertSame(2, $result['totals']['niu_qc']['1']);
        $this->assertSame(4, $result['row_count']);
    }

    public function testDimensionBreakdownsUseOnlyValuesActuallyPresent(): void
    {
        $aggregator = new CustomersListAggregator();

        $aggregator->add($this->row(['REGION' => 'NORD']));
        $aggregator->add($this->row(['REGION' => 'NORD']));
        $aggregator->add($this->row(['REGION' => 'SUD']));

        $result = $aggregator->result();

        $byValue = [];
        foreach ($result['dimensions']['region'] as $pair) {
            $byValue[$pair['value']] = $pair['count'];
        }

        $this->assertSame(['NORD' => 2, 'SUD' => 1], $byValue);
        // No invented category (e.g. a hardcoded "OUEST") should ever appear.
        $this->assertArrayNotHasKey('OUEST', $byValue);
    }

    public function testEmptyDimensionValuesAreBucketedNotDropped(): void
    {
        $aggregator = new CustomersListAggregator();

        $aggregator->add($this->row(['REGION' => null]));
        $aggregator->add($this->row(['REGION' => '']));
        $aggregator->add($this->row(['REGION' => 'NORD']));

        $result = $aggregator->result();

        $byValue = [];
        foreach ($result['dimensions']['region'] as $pair) {
            $byValue[$pair['value']] = $pair['count'];
        }

        // Both rows still count towards row_count/totals — nothing silently vanishes.
        $this->assertSame(3, $result['row_count']);
        $this->assertSame(2, $byValue['Non renseigné']);
        $this->assertSame(1, $byValue['NORD']);
    }

    public function testRfmPrepaidOnlyCountsPrepaidRowsWithAProfile(): void
    {
        $aggregator = new CustomersListAggregator();

        $aggregator->add($this->row(['METER' => 'PREPAID', 'SEGMENT_RFM_2' => 'RFM 5']));
        $aggregator->add($this->row(['METER' => 'PREPAID', 'SEGMENT_RFM_2' => null]));
        $aggregator->add($this->row(['METER' => 'POSTPAID', 'SEGMENT_RFM_2' => 'RFM 5']));

        $result = $aggregator->result();

        $byValue = [];
        foreach ($result['dimensions']['rfm_prepaid'] as $pair) {
            $byValue[$pair['value']] = $pair['count'];
        }

        $this->assertSame(1, $byValue['RFM 5']);
        $this->assertSame(1, $byValue['Sans profil RFM']);
        // The postpaid row's SEGMENT_RFM_2 must never leak into the prepaid RFM chart.
        $this->assertSame(2, array_sum($byValue));
    }

    public function testPostpaidProfileOnlyCountsPostpaidRows(): void
    {
        $aggregator = new CustomersListAggregator();

        $aggregator->add($this->row(['METER' => 'POSTPAID', 'SEGMENTATION' => 'Fidélisé']));
        $aggregator->add($this->row(['METER' => 'POSTPAID', 'SEGMENTATION' => 'Standard']));
        $aggregator->add($this->row(['METER' => 'PREPAID', 'SEGMENTATION' => '1 PERFECT']));

        $result = $aggregator->result();

        $byValue = [];
        foreach ($result['dimensions']['postpaid_profile'] as $pair) {
            $byValue[$pair['value']] = $pair['count'];
        }

        $this->assertSame(['Fidélisé' => 1, 'Standard' => 1], $byValue);
    }

    public function testCubeEntriesSumBackToRowCount(): void
    {
        $aggregator = new CustomersListAggregator();

        $aggregator->add($this->row(['REGION' => 'NORD', 'STATUS' => 'ACTIVE']));
        $aggregator->add($this->row(['REGION' => 'NORD', 'STATUS' => 'ACTIVE']));
        $aggregator->add($this->row(['REGION' => 'SUD', 'STATUS' => 'INACTIVE']));

        $result = $aggregator->result();

        $cubeTotal = array_sum(array_column($result['cube'], 'count'));

        $this->assertSame($result['row_count'], $cubeTotal);
        $this->assertCount(2, $result['cube']);
    }
}
