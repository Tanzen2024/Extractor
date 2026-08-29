<?php

use App\Models\CustomersListSnapshotModel;
use App\Models\ToolModel;
use App\Services\CustomersListStatsService;
use PHPUnit\Framework\TestCase;

/**
 * filteredStats() re-aggregates an already-decoded snapshot's stored cube in
 * plain PHP — it never touches Oracle or the local database, so it can be
 * exercised directly against a hand-built snapshot fixture. The models are
 * still injected as no-op stubs (rather than left to their defaults) purely
 * to avoid CodeIgniter's Model constructor eagerly resolving a DB connection
 * that this test environment has no driver for — filteredStats() never
 * calls into either model.
 *
 * @internal
 */
final class CustomersListStatsServiceFilterTest extends TestCase
{
    private function service(): CustomersListStatsService
    {
        $toolModel     = new class () extends ToolModel { public function __construct() {} };
        $snapshotModel = new class () extends CustomersListSnapshotModel { public function __construct() {} };

        return new CustomersListStatsService(null, $toolModel, $snapshotModel);
    }

    private function snapshot(): array
    {
        return [
            'cube' => [
                ['region' => 'NORD', 'division' => 'DIV A', 'agence' => 'AG 1', 'meter' => 'PREPAID', 'status' => 'ACTIVE', 'segmentation' => '1 Actif', 'segment_tresor' => 'T1', 'niu_qc' => '0', 'count' => 10],
                ['region' => 'NORD', 'division' => 'DIV A', 'agence' => 'AG 2', 'meter' => 'POSTPAID', 'status' => 'ACTIVE', 'segmentation' => 'Fidélisé', 'segment_tresor' => 'T2', 'niu_qc' => '1', 'count' => 5],
                ['region' => 'SUD', 'division' => 'DIV B', 'agence' => 'AG 3', 'meter' => 'POSTPAID', 'status' => 'INACTIVE', 'segmentation' => 'Standard', 'segment_tresor' => 'T1', 'niu_qc' => '0', 'count' => 7],
            ],
        ];
    }

    public function testNoFiltersSumsTheWholeCube(): void
    {
        $service = $this->service();
        $result  = $service->filteredStats($this->snapshot(), []);

        $this->assertSame(22, $result['row_count']);

        $byValue = [];
        foreach ($result['dimensions']['region'] as $pair) {
            $byValue[$pair['value']] = $pair['count'];
        }
        $this->assertSame(['NORD' => 15, 'SUD' => 7], $byValue);
    }

    public function testFilteringByRegionExcludesOtherRegions(): void
    {
        $service = $this->service();
        $result  = $service->filteredStats($this->snapshot(), ['region' => 'NORD']);

        $this->assertSame(15, $result['row_count']);

        $byValue = [];
        foreach ($result['dimensions']['meter'] as $pair) {
            $byValue[$pair['value']] = $pair['count'];
        }
        $this->assertSame(['PREPAID' => 10, 'POSTPAID' => 5], $byValue);
    }

    public function testCombiningFiltersNarrowsFurther(): void
    {
        $service = $this->service();
        $result  = $service->filteredStats($this->snapshot(), ['region' => 'NORD', 'meter' => 'POSTPAID']);

        $this->assertSame(5, $result['row_count']);
    }

    public function testFilterMatchingNothingReturnsZero(): void
    {
        $service = $this->service();
        $result  = $service->filteredStats($this->snapshot(), ['region' => 'OUEST']);

        $this->assertSame(0, $result['row_count']);
        $this->assertSame([], $result['dimensions']['region']);
    }

    public function testBlankFilterValuesAreIgnored(): void
    {
        $service = $this->service();
        $result  = $service->filteredStats($this->snapshot(), ['region' => '', 'meter' => null]);

        $this->assertSame(22, $result['row_count']);
    }

    public function testMissingCubeIsHandledGracefully(): void
    {
        $service = $this->service();
        $result  = $service->filteredStats([], ['region' => 'NORD']);

        $this->assertSame(0, $result['row_count']);
    }
}
