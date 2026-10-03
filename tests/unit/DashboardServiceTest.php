<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Snapshot\ActiveSnapshot;
use App\Services\Snapshot\SnapshotQueryEngine;
use CodeIgniter\Cache\Handlers\DummyHandler;
use Config\Oracle as OracleConfig;
use PHPUnit\Framework\TestCase;

/**
 * DashboardService shapes what the snapshot engine counts into KPIs and
 * chart datasets. These tests feed it canned engine answers (no file, no
 * Oracle) and pin the maths: the "% du total" denominators, the "Non
 * renseigné" bucket, no folding of segments, the segmentation-counts scope,
 * the export decision and the table guard rails. The engine itself is
 * tested against the real rules in SnapshotDashboardParityTest.
 *
 * @internal
 */
final class DashboardServiceTest extends TestCase
{
    private function service(FakeEngine $engine): DashboardService
    {
        return new DashboardService($engine, new OracleConfig(), new DummyHandler());
    }

    private static function kpis(int $total, int $actifs = 0, int $inactifs = 0, int $avecCompteur = 0, int $nuiCorrect = 0, int $contactOk = 0): array
    {
        return ['total' => $total, 'actifs' => $actifs, 'inactifs' => $inactifs, 'avecCompteur' => $avecCompteur, 'nuiCorrect' => $nuiCorrect, 'contactOk' => $contactOk];
    }

    public function testStatsComputesEveryKpiFromTheEngineFigures(): void
    {
        $engine = new FakeEngine(
            kpis: self::kpis(1000, actifs: 660, inactifs: 340, avecCompteur: 383, contactOk: 804),
            reference: ['totalClients' => 1000, 'totalNui' => 1000],
            distributions: [
                'region'       => ['DCUD' => 600, 'DCUY' => 400],
                'status'       => ['ACTIVE' => 660, 'INACTIVE.' => 340],
                'segmentation' => ['' => 10, '8 Autre' => 990],
                'meterType'    => ['PREPAID' => 620, 'POSTPAID' => 300, 'Compteurs Communicants' => 80],
            ],
        );

        $stats = $this->service($engine)->stats(FilterCriteria::none());

        $this->assertSame(1000, $stats['totalRows']);
        $this->assertSame(['value' => 660, 'pct' => 66.0], $stats['kpis']['actifs']);
        $this->assertSame(['value' => 340, 'pct' => 34.0], $stats['kpis']['inactifs']);
        $this->assertSame(383, $stats['kpis']['avecCompteur']['value']);
        $this->assertSame(['value' => 804, 'pct' => 80.4], $stats['kpis']['contacts']);
        $this->assertSame(
            [['value' => 'PREPAID', 'count' => 620], ['value' => 'POSTPAID', 'count' => 300], ['value' => 'Compteurs Communicants', 'count' => 80]],
            $stats['charts']['meterType'],
        );
        // The business rules travel to the engine unchanged (Config\Oracle).
        $this->assertSame(array_values((new OracleConfig())->activeStatuses), $engine->lastKpiArgs['active']);
        $this->assertSame(array_values((new OracleConfig())->meteredMeterValues), $engine->lastKpiArgs['metered']);
    }

    public function testStatsUsesTheAppliedFilters(): void
    {
        $engine  = new FakeEngine(kpis: self::kpis(1000, actifs: 1000, nuiCorrect: 795), reference: ['totalClients' => 1000, 'totalNui' => 1000]);
        $allowed = new AllowedValues(
            regions: [], divisions: [], agences: [],
            statuses: ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVE.'],
            segmentations: [], segmentsTresor: [], meters: [], voltages: [], niuQualities: [],
        );
        $criteria = FilterCriteria::fromRequest(['status' => ['ACTIVE', 'ACTIVE (PENDING BILLING)']], $allowed);

        $stats = $this->service($engine)->stats($criteria);

        $this->assertSame(['value' => 795, 'pct' => 79.5], $stats['kpis']['nuiCorrects']);
        $this->assertSame(['ACTIVE', 'ACTIVE (PENDING BILLING)'], $engine->lastKpiArgs['criteria']->statuses);
        $this->assertSame(['ACTIVE', 'ACTIVE (PENDING BILLING)'], $engine->lastDistributionCriteria->statuses);
    }

    /**
     * "% du total" of Total clients / Clients actifs / NUI corrects uses the
     * UNFILTERED reference (global contracts, all NUI) — never the filtered
     * total — while Contacts renseignés keeps its filtered-total rule.
     */
    public function testKpiPercentagesUseTheUnfilteredReferenceDenominators(): void
    {
        $engine = new FakeEngine(
            kpis: self::kpis(1000000, actifs: 980000, inactifs: 20000, nuiCorrect: 900000, contactOk: 500000),
            reference: ['totalClients' => 2480095, 'totalNui' => 2473000],
        );

        $k = $this->service($engine)->stats(FilterCriteria::fromArray(['regions' => ['DCUD']]))['kpis'];

        $this->assertSame(1000000, $k['total']);
        $this->assertSame(40.3, $k['totalPct']);                                  // 1 000 000 / 2 480 095
        $this->assertSame(['value' => 980000, 'pct' => 39.5], $k['actifs']);       // / 2 480 095
        $this->assertSame(['value' => 900000, 'pct' => 36.4], $k['nuiCorrects']);  // / 2 473 000
        $this->assertSame(['value' => 500000, 'pct' => 50.0], $k['contacts']);     // unchanged: / filtered total
        $this->assertSame(['totalClients' => 2480095, 'totalNui' => 2473000], $k['reference']);
    }

    public function testUnfilteredTotalIsHundredPercentAndActifsIsOverTheGlobalTotal(): void
    {
        $engine = new FakeEngine(kpis: self::kpis(2480095, actifs: 2430857, nuiCorrect: 2272992), reference: ['totalClients' => 2480095, 'totalNui' => 2480095]);

        $k = $this->service($engine)->stats(FilterCriteria::none())['kpis'];

        $this->assertSame(100.0, $k['totalPct']);
        $this->assertSame(98.0, $k['actifs']['pct']);
        $this->assertSame(91.6, $k['nuiCorrects']['pct']);
    }

    public function testEmptyReferenceAndZeroTotalNeverDivideByZero(): void
    {
        $k = $this->service(new FakeEngine(kpis: self::kpis(5, actifs: 5, nuiCorrect: 5), reference: ['totalClients' => 0, 'totalNui' => 0]))->stats(FilterCriteria::none())['kpis'];
        $this->assertSame(0.0, $k['totalPct']);
        $this->assertSame(0.0, $k['actifs']['pct']);
        $this->assertSame(0.0, $k['nuiCorrects']['pct']);

        $stats = $this->service(new FakeEngine(kpis: self::kpis(0)))->stats(FilterCriteria::none());
        $this->assertSame(0, $stats['totalRows']);
        $this->assertSame(0.0, $stats['kpis']['actifs']['pct']);
        $this->assertSame(0.0, $stats['kpis']['contacts']['pct']);
    }

    public function testStatsShapesTheDistributionsAndNeverFoldsTheSegmentation(): void
    {
        $segments = [];
        for ($i = 0; $i < 12; $i++) {
            $segments["S{$i}"] = 100 - $i;
        }
        $engine = new FakeEngine(kpis: self::kpis(100), distributions: [
            'region' => ['DCUD' => 10], 'status' => [], 'segmentation' => $segments, 'meterType' => ['PREPAID' => 7, 'POSTPAID' => 3],
        ]);

        $charts = $this->service($engine)->stats(FilterCriteria::none())['charts'];

        $this->assertCount(1, $charts['region']);
        $this->assertSame([], $charts['status']);
        $this->assertCount(12, $charts['segmentation']);
        $this->assertNotContains('Autres', array_column($charts['segmentation'], 'value'));
        $this->assertSame(array_sum(range(89, 100)), array_sum(array_column($charts['segmentation'], 'count')));
        $this->assertCount(2, $charts['meterType']);
        $this->assertSame('PREPAID', $charts['meterType'][0]['value']);
    }

    public function testBlankValuesShareOneNonRenseigneBucketAndCountsSumToTheTotal(): void
    {
        // The snapshot stores a blank as '' — a value of only spaces is blank too.
        $engine = new FakeEngine(kpis: self::kpis(100000), distributions: [
            'region' => [], 'status' => ['' => 5],
            'segmentation' => [], 'meterType' => ['PREPAID' => 60000, 'POSTPAID' => 35000, '' => 4000, ' ' => 1000],
        ]);

        $stats     = $this->service($engine)->stats(FilterCriteria::none());
        $meterType = $stats['charts']['meterType'];

        $this->assertSame('Non renseigné', $stats['charts']['status'][0]['value']);
        $this->assertSame($stats['totalRows'], array_sum(array_column($meterType, 'count')));
        $this->assertSame(['value' => 'Non renseigné', 'count' => 5000], end($meterType));
    }

    /**
     * Regression for "199 lignes classé comme export volumineux (0 lignes)":
     * the count driving the export decision equals the total the modal shows.
     */
    public function testCountMatchesTheStatsTotalForTheSameCriteria(): void
    {
        $svc = $this->service(new FakeEngine(kpis: self::kpis(199, actifs: 150), count: 199));

        $this->assertSame(199, $svc->stats(FilterCriteria::none())['totalRows']);
        $this->assertSame(199, $svc->count(FilterCriteria::none()));
    }

    /**
     * Numbers next to the Segmentation options: every active filter EXCEPT
     * the segmentation one.
     */
    public function testSegmentationCountsUseEveryFilterExceptTheSegmentationOne(): void
    {
        $engine = new FakeEngine(segmentationCounts: ['2 RELIABLE' => 7057, '1 PERFECT' => 1248, '' => 3]);

        $counts = $this->service($engine)->segmentationCounts(FilterCriteria::fromArray([
            'meters' => ['Compteurs Communicants'], 'regions' => ['DCUD'], 'statuses' => ['ACTIVE'], 'segmentations' => ['1 PERFECT'],
        ]));

        $this->assertSame([
            ['value' => '2 RELIABLE', 'count' => 7057],
            ['value' => '1 PERFECT', 'count' => 1248],
            ['value' => 'Non renseigné', 'count' => 3],
        ], $counts);
        $scope = $engine->lastSegmentationCriteria;
        $this->assertSame(['Compteurs Communicants'], $scope->meters);
        $this->assertSame(['DCUD'], $scope->regions);
        $this->assertSame(['ACTIVE'], $scope->statuses);
        $this->assertSame([], $scope->segmentations);
    }

    public function testExportDecisionUsesOnlyTheBackendCount(): void
    {
        $svc = $this->service(new FakeEngine());
        $max = (new OracleConfig())->exportSyncMaxRows; // 150 000

        $this->assertSame('empty', $svc->exportDecision(0));
        $this->assertSame('empty', $svc->exportDecision(-3));
        $this->assertSame('sync', $svc->exportDecision(1));
        $this->assertSame('sync', $svc->exportDecision(199));
        $this->assertSame('sync', $svc->exportDecision($max));
        $this->assertSame('async', $svc->exportDecision($max + 1));
        $this->assertSame('async', $svc->exportDecision(1_683_192));
    }

    public function testRowsReturnsPageMetadataTheCountAndTheEnginePage(): void
    {
        $engine = new FakeEngine(count: 4230, page: [
            ['REGION' => 'DCUD', 'CONTRACT' => '1', 'CUST_NAME' => 'A'],
            ['REGION' => 'DCUY', 'CONTRACT' => '2', 'CUST_NAME' => 'B'],
        ]);

        $result = $this->service($engine)->rows(FilterCriteria::none(), 3, 20, 'CUST_NAME', 'desc', 'dupont');

        $this->assertSame(4230, $result['total']);
        $this->assertSame(3, $result['page']);
        $this->assertSame(20, $result['perPage']);
        $this->assertSame('CUST_NAME', $result['sort']);
        $this->assertSame('desc', $result['dir']);
        $this->assertCount(2, $result['data']);
        $this->assertSame(QueryBuilder::ALL_COLUMNS, $result['columns']);
        $this->assertSame(['dupont', 'CUST_NAME', 'desc', 40, 20], $engine->lastPageArgs);
        $this->assertSame('dupont', $engine->lastCountSearch, 'the total counts the searched rows');
    }

    public function testRowsNormalisesAnUnknownSortAndPerPage(): void
    {
        $engine = new FakeEngine();
        $result = $this->service($engine)->rows(FilterCriteria::none(), 1, 999, 'HACK', 'x');

        $this->assertSame(50, $result['perPage']);
        $this->assertSame('CONTRACT', $result['sort']); // default
        $this->assertSame('asc', $result['dir']);
        $this->assertNull($engine->lastPageArgs[1], 'an unknown sort never reaches the engine');
    }

    public function testRowsRefusesTooDeepAPage(): void
    {
        $this->expectException(InvalidFilterException::class);
        // offset = (page-1) * perPage = 99999 * 200 -> well past tableMaxOffset
        $this->service(new FakeEngine())->rows(FilterCriteria::none(), 100000, 200, null, 'asc');
    }

    public function testFilterOptionsAndAllowedValuesComeFromTheSnapshotVersion(): void
    {
        $options = [
            'regions' => [['value' => 'DRE', 'count' => 1]], 'divisions' => [['value' => 'D', 'count' => 1]], 'agences' => [['value' => 'A', 'count' => 1]],
            'statuses' => [['value' => 'ACTIVE', 'count' => 1]], 'segmentations' => [], 'segmentsTresor' => [], 'meters' => [], 'voltages' => [], 'niuQualities' => [],
            'geoTree' => ['DRE' => ['D' => [['value' => 'A', 'count' => 1]]]], 'dateBounds' => ['min' => '2000-01-01', 'max' => '2020-01-01'],
        ];
        $svc = $this->service(new FakeEngine(filterOptions: $options));

        $this->assertSame($options, $svc->filterOptions());
        $this->assertInstanceOf(AllowedValues::class, $svc->allowedValues());
        $this->assertSame(['DRE'], $svc->allowedValues()->regions);
        $this->assertSame(['ACTIVE'], $svc->allowedValues()->statuses);
    }

    public function testCacheKeysBelongToTheSnapshotVersion(): void
    {
        $cache = new class () extends DummyHandler {
            public array $saved = [];

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->saved[] = $key;

                return true;
            }
        };
        $svc = new DashboardService(new FakeEngine(kpis: self::kpis(1), count: 1), new OracleConfig(), $cache);
        $svc->stats(FilterCriteria::none());
        $svc->count(FilterCriteria::none());

        $this->assertNotEmpty($cache->saved);
        foreach ($cache->saved as $key) {
            $this->assertStringStartsWith('cl_snap_20261002T050000_aaaaaaaa_', $key, 'a new version never reads an older version\'s figures');
        }
    }
}

/**
 * Canned SnapshotQueryEngine — records the criteria / arguments it receives.
 */
final class FakeEngine implements SnapshotQueryEngine
{
    public array $lastKpiArgs = [];
    public ?FilterCriteria $lastDistributionCriteria = null;
    public ?FilterCriteria $lastSegmentationCriteria = null;
    public array $lastPageArgs = [];
    public ?string $lastCountSearch = null;

    public function __construct(
        private readonly array $kpis = ['total' => 0, 'actifs' => 0, 'inactifs' => 0, 'avecCompteur' => 0, 'nuiCorrect' => 0, 'contactOk' => 0],
        private readonly array $reference = ['totalClients' => 0, 'totalNui' => 0],
        private readonly array $distributions = ['region' => [], 'status' => [], 'segmentation' => [], 'meterType' => []],
        private readonly array $segmentationCounts = [],
        private readonly int $count = 0,
        private readonly array $page = [],
        private readonly array $filterOptions = [],
    ) {
    }

    public function version(): string
    {
        return '20261002T050000_aaaaaaaa';
    }

    public function snapshot(): ActiveSnapshot
    {
        return new ActiveSnapshot($this->version(), '/dev/null', ['rows' => 0, 'delimiter' => '#', 'filter_options' => $this->filterOptions]);
    }

    public function count(FilterCriteria $criteria, string $search = ''): int
    {
        $this->lastCountSearch = $search;

        return $this->count;
    }

    public function kpis(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array
    {
        $this->lastKpiArgs = ['criteria' => $criteria, 'active' => $activeStatuses, 'metered' => $meteredMeters];

        return $this->kpis;
    }

    public function summary(FilterCriteria $criteria, array $activeStatuses, array $meteredMeters): array
    {
        return ['kpis' => $this->kpis($criteria, $activeStatuses, $meteredMeters), 'distributions' => $this->distributions($criteria)];
    }

    public function reference(): array
    {
        return $this->reference;
    }

    public function distributions(FilterCriteria $criteria): array
    {
        $this->lastDistributionCriteria = $criteria;

        return $this->distributions;
    }

    public function segmentationCounts(FilterCriteria $criteria): array
    {
        $this->lastSegmentationCriteria = $criteria;

        return $this->segmentationCounts;
    }

    public function page(FilterCriteria $criteria, string $search, ?string $sort, string $dir, int $offset, int $limit): array
    {
        $this->lastPageArgs = [$search, $sort, $dir, $offset, $limit];

        return $this->page;
    }
}
