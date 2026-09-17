<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use PHPUnit\Framework\TestCase;

/**
 * FilterCriteria is the trust boundary for the dashboard: it turns raw
 * request params into a validated, immutable filter set or throws. These
 * tests pin the validation rules and the round-trip used to persist a
 * filter set on an export job.
 *
 * @internal
 */
final class FilterCriteriaTest extends TestCase
{
    private function allowed(): AllowedValues
    {
        return new AllowedValues(
            regions: ['DCUD', 'DCUY', 'DRE'],
            divisions: ['DVC DOUALA NORD', 'DVC DOUALA EST'],
            agences: ['CSC_LOGPOM', 'CSC_PK21'],
            statuses: ['ACTIVE', 'INACTIVE.'],
            segmentations: ['1 PERFECT', '8 Autre'],
            segmentsTresor: ['PRIVATE', 'ADMINISTRATION'],
            meters: ['PREPAID', 'POSTPAID', 'Compteurs Communicants'],
            voltages: ['LV', 'MV'],
            niuQualities: ['NUI correct', 'NUI a RECLASSER'],
            dateBounds: ['min' => '1990-01-01', 'max' => '2026-08-25'],
        );
    }

    public function testEmptyRequestProducesAnEmptyCriteria(): void
    {
        $c = FilterCriteria::fromRequest([], $this->allowed());

        $this->assertTrue($c->isEmpty());
        $this->assertSame([], $c->toArray());
        $this->assertSame([], $c->describe());
    }

    public function testParsesEveryDimension(): void
    {
        $c = FilterCriteria::fromRequest([
            'date_from'      => '2024-01-01',
            'date_to'        => '2025-12-31',
            'region'         => ['DCUD', 'DCUY'],
            'division'       => ['DVC DOUALA NORD'],
            'agence'         => ['CSC_LOGPOM'],
            'status'         => ['ACTIVE'],
            'segmentation'   => ['1 PERFECT'],
            'segment_tresor' => ['PRIVATE'],
            'meter'          => ['PREPAID'],
            'voltage'        => ['LV'],
            'niu_qc'         => ['NUI correct'],
        ], $this->allowed());

        $this->assertFalse($c->isEmpty());
        $this->assertSame('2024-01-01', $c->dateFrom->format('Y-m-d'));
        $this->assertSame('2025-12-31', $c->dateTo->format('Y-m-d'));
        $this->assertSame(['DCUD', 'DCUY'], $c->regions);
        $this->assertSame(['NUI correct'], $c->niuQualities);
        $this->assertArrayHasKey('Région', $c->describe());
    }

    public function testDeduplicatesAndDropsBlankValues(): void
    {
        $c = FilterCriteria::fromRequest(['region' => ['DCUD', '', 'DCUD', 'DCUY']], $this->allowed());

        $this->assertSame(['DCUD', 'DCUY'], $c->regions);
    }

    public function testRejectsAnUnknownRegion(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['region' => ['DCUD', 'PIRATE']], $this->allowed());
    }

    public function testRejectsAnUnknownStatus(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['status' => ['DELETED']], $this->allowed());
    }

    public function testRejectsAMalformedDate(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['date_from' => '01/01/2024'], $this->allowed());
    }

    public function testRejectsAnImpossibleDate(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['date_from' => '2024-02-31'], $this->allowed());
    }

    public function testRejectsFromAfterTo(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['date_from' => '2025-01-01', 'date_to' => '2024-01-01'], $this->allowed());
    }

    public function testRejectsAnUnknownNiuQuality(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['niu_qc' => ['N/A']], $this->allowed());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $original = FilterCriteria::fromRequest([
            'date_from' => '2024-06-01',
            'region'    => ['DRE'],
            'meter'     => ['PREPAID', 'POSTPAID'],
            'niu_qc'    => ['NUI a RECLASSER'],
        ], $this->allowed());

        $restored = FilterCriteria::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $restored->toArray());
        $this->assertSame('2024-06-01', $restored->dateFrom->format('Y-m-d'));
        $this->assertSame(['NUI a RECLASSER'], $restored->niuQualities);
    }

    public function testCacheKeyIsStableAndFilterSensitive(): void
    {
        $a = FilterCriteria::fromRequest(['region' => ['DCUD']], $this->allowed());
        $b = FilterCriteria::fromRequest(['region' => ['DCUD']], $this->allowed());
        $c = FilterCriteria::fromRequest(['region' => ['DCUY']], $this->allowed());

        $this->assertSame($a->cacheKey(), $b->cacheKey());
        $this->assertNotSame($a->cacheKey(), $c->cacheKey());
    }

    public function testDescribeIsHumanReadable(): void
    {
        $c = FilterCriteria::fromRequest([
            'date_from' => '2024-01-01',
            'date_to'   => '2024-12-31',
            'region'    => ['DCUD', 'DCUY'],
            'niu_qc'    => ['NUI correct'],
        ], $this->allowed());

        $described = $c->describe();
        $this->assertSame('01/01/2024 → 31/12/2024', $described['Période (abonnement)']);
        $this->assertSame('DCUD, DCUY', $described['Région']);
        $this->assertSame('NUI correct', $described['Qualité NIU']);
    }
}
