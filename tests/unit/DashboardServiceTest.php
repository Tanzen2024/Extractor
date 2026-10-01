<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\OracleExtractionService;
use CodeIgniter\Cache\Handlers\DummyHandler;
use Config\Oracle as OracleConfig;
use PHPUnit\Framework\TestCase;

/**
 * DashboardService shapes raw Oracle aggregate rows into KPIs and chart
 * datasets. These tests feed it canned rows (no Oracle) and pin the maths:
 * the "active" rule, the metered rule, the contacts %, the "Autres" fold,
 * the meter-type distribution, and the pagination guard rails.
 *
 * @internal
 */
final class DashboardServiceTest extends TestCase
{
    private function service(FakeOracle $oracle): DashboardService
    {
        return new DashboardService($oracle, null, new OracleConfig(), new DummyHandler());
    }

    public function testStatsComputesEveryKpiFromOneAggregateRow(): void
    {
        $oracle = new FakeOracle([
            'kpi' => [[
                'TOTAL' => '1000', 'ACTIFS' => '660', 'AVEC_COMPTEUR' => '383',
                'PHONE_OK' => '800', 'EMAIL_OK' => '21', 'REFGEO_OK' => '1000',
                'METERNO_OK' => '984', 'NIU_OK' => '786', 'NAME_OK' => '1000', 'CONTACT_OK' => '804',
            ]],
            'ref'  => [['TOTAL' => '1000', 'NUI_TOTAL' => '1000']],
            'dist' => [
                ['DIM' => 'region', 'VAL' => 'DCUD', 'N' => '600'],
                ['DIM' => 'region', 'VAL' => 'DCUY', 'N' => '400'],
                ['DIM' => 'status', 'VAL' => 'ACTIVE', 'N' => '660'],
                ['DIM' => 'status', 'VAL' => 'INACTIVE.', 'N' => '340'],
                ['DIM' => 'segmentation', 'VAL' => null, 'N' => '10'],
                ['DIM' => 'segmentation', 'VAL' => '8 Autre', 'N' => '990'],
                ['DIM' => 'meterType', 'VAL' => 'PREPAID', 'N' => '620'],
                ['DIM' => 'meterType', 'VAL' => 'POSTPAID', 'N' => '300'],
                ['DIM' => 'meterType', 'VAL' => 'Compteurs Communicants', 'N' => '80'],
            ],
        ]);

        $stats = $this->service($oracle)->stats(FilterCriteria::none());

        $this->assertSame(1000, $stats['totalRows']);
        $this->assertSame(660, $stats['kpis']['actifs']['value']);
        $this->assertSame(66.0, $stats['kpis']['actifs']['pct']);
        $this->assertSame(383, $stats['kpis']['avecCompteur']['value']);
        $this->assertSame(804, $stats['kpis']['contacts']['value']);
        $this->assertSame(80.4, $stats['kpis']['contacts']['pct']);

        // "Type de compteurs" — sorted by count desc, values verbatim from METER.
        $this->assertSame(
            [['value' => 'PREPAID', 'count' => 620], ['value' => 'POSTPAID', 'count' => 300], ['value' => 'Compteurs Communicants', 'count' => 80]],
            $stats['charts']['meterType'],
        );
        $this->assertArrayNotHasKey('completeness', $stats['charts']);
    }

    public function testStatsExposesInactifsAlongsideActifs(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '1000', 'ACTIFS' => '660', 'INACTIFS' => '340', 'AVEC_COMPTEUR' => '0', 'NUI_CORRECT' => '0', 'CONTACT_OK' => '0']],
            'ref'  => [['TOTAL' => '1000', 'NUI_TOTAL' => '1000']],
            'dist' => [],
        ]);

        $stats = $this->service($oracle)->stats(FilterCriteria::none());

        $this->assertSame(['value' => 660, 'pct' => 66.0], $stats['kpis']['actifs']);
        $this->assertSame(['value' => 340, 'pct' => 34.0], $stats['kpis']['inactifs']);
        $this->assertStringContainsString('INACTIFS', $oracle->lastQueries['kpi']['sql']);
    }

    public function testStatsExposesNuiCorrectsComputedUnderTheAppliedFilters(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '1000', 'ACTIFS' => '1000', 'AVEC_COMPTEUR' => '0', 'NUI_CORRECT' => '795', 'CONTACT_OK' => '0']],
            'ref'  => [['TOTAL' => '1000', 'NUI_TOTAL' => '1000']],
            'dist' => [],
        ]);
        $allowed = new AllowedValues(
            regions: [], divisions: [], agences: [],
            statuses: ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVE.'],
            segmentations: [], segmentsTresor: [], meters: [], voltages: [], niuQualities: [],
        );
        $active   = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)'];
        $criteria = FilterCriteria::fromRequest(['status' => $active], $allowed);

        $stats = $this->service($oracle)->stats($criteria);

        $this->assertSame(['value' => 795, 'pct' => 79.5], $stats['kpis']['nuiCorrects']);
        $kpi = $oracle->lastQueries['kpi'];
        $this->assertStringContainsString('NUI_CORRECT', $kpi['sql']);
        $this->assertMatchesRegularExpression('/ WHERE .*STATUS IN \(/s', $kpi['sql']);
        foreach ($active as $status) {
            $this->assertContains($status, $kpi['binds']);
        }
    }

    /**
     * "% du total" of Total clients / Clients actifs / NUI corrects uses the
     * UNFILTERED reference (global contracts, all NUI) — never the filtered
     * total — while Contacts renseignés keeps its filtered-total rule.
     */
    public function testKpiPercentagesUseTheUnfilteredReferenceDenominators(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '1000000', 'ACTIFS' => '980000', 'INACTIFS' => '20000', 'AVEC_COMPTEUR' => '0', 'NUI_CORRECT' => '900000', 'CONTACT_OK' => '500000']],
            'ref'  => [['TOTAL' => '2480095', 'NUI_TOTAL' => '2473000']],
            'dist' => [],
        ]);
        $allowed  = new AllowedValues(
            regions: ['DCUD', 'DCUY'], divisions: [], agences: [], statuses: [],
            segmentations: [], segmentsTresor: [], meters: [], voltages: [], niuQualities: [],
        );
        $criteria = FilterCriteria::fromRequest(['region' => ['DCUD']], $allowed);

        $k = $this->service($oracle)->stats($criteria)['kpis'];

        $this->assertSame(1000000, $k['total']);
        $this->assertSame(40.3, $k['totalPct']);                                  // 1 000 000 / 2 480 095
        $this->assertSame(['value' => 980000, 'pct' => 39.5], $k['actifs']);       // / 2 480 095
        $this->assertSame(['value' => 900000, 'pct' => 36.4], $k['nuiCorrects']);  // / 2 473 000
        $this->assertSame(['value' => 500000, 'pct' => 50.0], $k['contacts']);     // unchanged: / filtered total
        $this->assertSame(['totalClients' => 2480095, 'totalNui' => 2473000], $k['reference']);
    }

    public function testUnfilteredTotalIsHundredPercentAndActifsIsOverTheGlobalTotal(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '2480095', 'ACTIFS' => '2430857', 'AVEC_COMPTEUR' => '0', 'NUI_CORRECT' => '2272992', 'CONTACT_OK' => '0']],
            'ref'  => [['TOTAL' => '2480095', 'NUI_TOTAL' => '2480095']],
            'dist' => [],
        ]);

        $k = $this->service($oracle)->stats(FilterCriteria::none())['kpis'];

        $this->assertSame(100.0, $k['totalPct']);
        $this->assertSame(98.0, $k['actifs']['pct']);
        $this->assertSame(91.6, $k['nuiCorrects']['pct']);
    }

    public function testEmptyReferenceNeverDividesByZero(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '5', 'ACTIFS' => '5', 'AVEC_COMPTEUR' => '0', 'NUI_CORRECT' => '5', 'CONTACT_OK' => '0']],
            'ref'  => [['TOTAL' => '0', 'NUI_TOTAL' => '0']],
            'dist' => [],
        ]);

        $k = $this->service($oracle)->stats(FilterCriteria::none())['kpis'];

        $this->assertSame(0.0, $k['totalPct']);
        $this->assertSame(0.0, $k['actifs']['pct']);
        $this->assertSame(0.0, $k['nuiCorrects']['pct']);
    }

    public function testStatsShapesTheDistributionsAndNeverFoldsTheSegmentation(): void
    {
        $dist = [
            ['DIM' => 'region', 'VAL' => 'DCUD', 'N' => '10'],
            ['DIM' => 'meterType', 'VAL' => 'PREPAID', 'N' => '7'],
            ['DIM' => 'meterType', 'VAL' => 'POSTPAID', 'N' => '3'],
        ];
        for ($i = 0; $i < 12; $i++) {
            $dist[] = ['DIM' => 'segmentation', 'VAL' => "S{$i}", 'N' => (string) (100 - $i)];
        }

        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '100', 'ACTIFS' => '0', 'AVEC_COMPTEUR' => '0', 'CONTACT_OK' => '0']],
            'dist' => $dist,
        ]);

        $charts = $this->service($oracle)->stats(FilterCriteria::none())['charts'];

        $this->assertCount(1, $charts['region']);
        $this->assertSame([], $charts['status']);
        // Every segment kept (the chart orders them by business rule — no
        // "Autres" bucket merging real segments), total unchanged.
        $this->assertCount(12, $charts['segmentation']);
        $this->assertNotContains('Autres', array_column($charts['segmentation'], 'value'));
        $this->assertSame(array_sum(range(89, 100)), array_sum(array_column($charts['segmentation'], 'count')));
        // meter-type distribution is NOT folded — every real type is shown.
        $this->assertCount(2, $charts['meterType']);
        $this->assertSame('PREPAID', $charts['meterType'][0]['value']);
    }

    public function testNullDistributionValueBecomesTheEmptyLabel(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '5', 'ACTIFS' => '0', 'AVEC_COMPTEUR' => '0', 'PHONE_OK' => '0', 'EMAIL_OK' => '0', 'REFGEO_OK' => '0', 'METERNO_OK' => '0', 'NIU_OK' => '0', 'NAME_OK' => '0', 'CONTACT_OK' => '0']],
            'dist' => [['DIM' => 'status', 'VAL' => null, 'N' => '5']],
        ]);

        $status = $this->service($oracle)->stats(FilterCriteria::none())['charts']['status'];

        $this->assertSame('Non renseigné', $status[0]['value']);
    }

    public function testMeterTypeDistributionSumsToTheTotalAndLabelsTheBlankBucket(): void
    {
        // COHÉRENCE (mandat §17) : la somme des types == totalRows, valeurs
        // NULL/vides -> bucket "Non renseigné" (jamais supprimées en silence).
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '100000', 'ACTIFS' => '0', 'AVEC_COMPTEUR' => '0', 'CONTACT_OK' => '0']],
            'dist' => [
                ['DIM' => 'meterType', 'VAL' => 'PREPAID', 'N' => '60000'],
                ['DIM' => 'meterType', 'VAL' => 'POSTPAID', 'N' => '35000'],
                ['DIM' => 'meterType', 'VAL' => null, 'N' => '5000'],
            ],
        ]);

        $stats     = $this->service($oracle)->stats(FilterCriteria::none());
        $meterType = $stats['charts']['meterType'];

        $this->assertSame(100000, array_sum(array_column($meterType, 'count')));
        $this->assertSame($stats['totalRows'], array_sum(array_column($meterType, 'count')));
        $this->assertSame('Non renseigné', end($meterType)['value']);
        $this->assertSame(5000, end($meterType)['count']);
    }

    public function testZeroTotalNeverDividesByZero(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '0', 'ACTIFS' => '0', 'AVEC_COMPTEUR' => '0', 'PHONE_OK' => '0', 'EMAIL_OK' => '0', 'REFGEO_OK' => '0', 'METERNO_OK' => '0', 'NIU_OK' => '0', 'NAME_OK' => '0', 'CONTACT_OK' => '0']],
            'dist' => [],
        ]);

        $stats = $this->service($oracle)->stats(FilterCriteria::none());

        $this->assertSame(0, $stats['totalRows']);
        $this->assertSame(0.0, $stats['kpis']['actifs']['pct']);
    }

    /**
     * Regression for "199 lignes classé comme export volumineux (0 lignes)":
     * the count that drives the export decision is DashboardService::count(),
     * which for a given criteria must equal the total the modal shows
     * (stats()['totalRows']) for the same criteria — same QueryBuilder WHERE.
     */
    public function testCountMatchesTheStatsTotalForTheSameCriteria(): void
    {
        $oracle = new FakeOracle([
            'kpi'   => [['TOTAL' => '199', 'ACTIFS' => '150', 'AVEC_COMPTEUR' => '80', 'PHONE_OK' => '0', 'EMAIL_OK' => '0', 'REFGEO_OK' => '0', 'METERNO_OK' => '0', 'NIU_OK' => '0', 'NAME_OK' => '0', 'CONTACT_OK' => '0']],
            'dist'  => [],
            'count' => [['N' => '199']],
        ]);
        $svc = $this->service($oracle);

        $criteria = FilterCriteria::none();
        $this->assertSame(199, $svc->stats($criteria)['totalRows']);
        $this->assertSame(199, $svc->count($criteria));
    }

    /**
     * Numbers next to the Segmentation options: every active filter (meter
     * type, region, status, …) EXCEPT the segmentation one, one GROUP BY.
     */
    public function testSegmentationCountsUseEveryFilterExceptTheSegmentationOne(): void
    {
        $oracle = new FakeOracle(['segs' => [
            ['VAL' => '2 RELIABLE', 'N' => '7057'],
            ['VAL' => '1 PERFECT', 'N' => '1248'],
            ['VAL' => null, 'N' => '3'],
        ]]);

        $counts = $this->service($oracle)->segmentationCounts(FilterCriteria::fromArray([
            'meters' => ['COMPTEURS COMMUNICANTS'], 'regions' => ['DCUD'], 'statuses' => ['ACTIVE'], 'segmentations' => ['1 PERFECT'],
        ]));

        $this->assertSame([
            ['value' => '2 RELIABLE', 'count' => 7057],
            ['value' => '1 PERFECT', 'count' => 1248],
            ['value' => 'Non renseigné', 'count' => 3],
        ], $counts);

        $sql = $oracle->lastSelect['sql'];
        $this->assertStringContainsString('METER IN', $sql);
        $this->assertStringContainsString('REGION IN', $sql);
        $this->assertStringContainsString('STATUS IN', $sql);
        $this->assertStringNotContainsString('SEGMENTATION IN', $sql);
        $this->assertStringContainsString('GROUP BY SEGMENTATION', $sql);
        $this->assertEqualsCanonicalizing(['COMPTEURS COMMUNICANTS', 'DCUD', 'ACTIVE'], array_values($oracle->lastSelect['binds']));
    }

    public function testExportDecisionUsesOnlyTheBackendCount(): void
    {
        $svc = $this->service(new FakeOracle([]));
        $max = (new OracleConfig())->exportSyncMaxRows; // 150 000

        $this->assertSame('empty', $svc->exportDecision(0));
        $this->assertSame('empty', $svc->exportDecision(-3));
        $this->assertSame('sync', $svc->exportDecision(1));
        $this->assertSame('sync', $svc->exportDecision(199));           // the bug's number
        $this->assertSame('sync', $svc->exportDecision($max));          // exactly at the threshold
        $this->assertSame('async', $svc->exportDecision($max + 1));
        $this->assertSame('async', $svc->exportDecision(1_683_192));
    }

    public function testRowsReturnsPageMetadataAndTheCachedTotal(): void
    {
        $oracle = new FakeOracle([
            'count' => [['N' => '4230']],
            'page'  => [
                ['REGION' => 'DCUD', 'CONTRACT' => '1', 'CUST_NAME' => 'A'],
                ['REGION' => 'DCUY', 'CONTRACT' => '2', 'CUST_NAME' => 'B'],
            ],
        ]);

        $result = $this->service($oracle)->rows(FilterCriteria::none(), 3, 20, 'CUST_NAME', 'desc', 'dupont');

        $this->assertSame(4230, $result['total']);
        $this->assertSame(3, $result['page']);
        $this->assertSame(20, $result['perPage']);
        $this->assertSame('CUST_NAME', $result['sort']);
        $this->assertSame('desc', $result['dir']);
        $this->assertCount(2, $result['data']);
        $this->assertSame(\App\Services\CustomersList\QueryBuilder::ALL_COLUMNS, $result['columns']);
    }

    public function testRowsNormalisesAnUnknownSortAndPerPage(): void
    {
        $oracle = new FakeOracle(['count' => [['N' => '0']], 'page' => []]);

        $result = $this->service($oracle)->rows(FilterCriteria::none(), 1, 999, 'HACK', 'x');

        $this->assertSame(50, $result['perPage']);
        $this->assertSame('CONTRACT', $result['sort']); // default
        $this->assertSame('asc', $result['dir']);
    }

    public function testRowsRefusesTooDeepAPage(): void
    {
        $oracle = new FakeOracle(['count' => [['N' => '9999999']], 'page' => []]);

        $this->expectException(InvalidFilterException::class);
        // offset = (page-1) * perPage = 99999 * 200 -> well past tableMaxOffset
        $this->service($oracle)->rows(FilterCriteria::none(), 100000, 200, null, 'asc');
    }

    public function testFilterOptionsBuildsTheGeoTreeAndValueLists(): void
    {
        $oracle = new FakeOracle([]);
        $oracle->many = [
            'flat' => [
                ['DIM' => 'regions', 'VAL' => 'DCUD', 'N' => '600'],
                ['DIM' => 'regions', 'VAL' => 'DCUY', 'N' => '400'],
                ['DIM' => 'statuses', 'VAL' => 'ACTIVE', 'N' => '900'],
                ['DIM' => 'statuses', 'VAL' => null, 'N' => '5'],   // blank bucket excluded from options
                ['DIM' => 'meters', 'VAL' => 'PREPAID', 'N' => '1000'],
            ],
            'geo' => [
                ['REGION' => 'DCUD', 'DIVISION' => 'DVC A', 'AGENCE' => 'CSC_1', 'N' => '300'],
                ['REGION' => 'DCUD', 'DIVISION' => 'DVC A', 'AGENCE' => 'CSC_2', 'N' => '300'],
                ['REGION' => 'DCUY', 'DIVISION' => 'DVC B', 'AGENCE' => 'CSC_3', 'N' => '400'],
            ],
            'bounds' => [['MN' => '0980-07-01', 'MX' => '2026-08-25']],
        ];

        $options = $this->service($oracle)->filterOptions();

        $this->assertSame(['DCUD', 'DCUY'], array_column($options['regions'], 'value'));
        $this->assertSame(['ACTIVE'], array_column($options['statuses'], 'value')); // null bucket dropped
        $this->assertArrayHasKey('DCUD', $options['geoTree']);
        $this->assertArrayHasKey('DVC A', $options['geoTree']['DCUD']);
        $this->assertCount(2, $options['geoTree']['DCUD']['DVC A']);
        $this->assertSame('1990-01-01', $options['dateBounds']['min']); // clamped
        $this->assertSame('2026-08-25', $options['dateBounds']['max']);
    }

    public function testAllowedValuesDerivesFromFilterOptions(): void
    {
        $oracle = new FakeOracle([]);
        $oracle->many = [
            'flat'   => [['DIM' => 'regions', 'VAL' => 'DRE', 'N' => '1']],
            'geo'    => [['REGION' => 'DRE', 'DIVISION' => 'D', 'AGENCE' => 'A', 'N' => '1']],
            'bounds' => [['MN' => '2000-01-01', 'MX' => '2020-01-01']],
        ];

        $allowed = $this->service($oracle)->allowedValues();

        $this->assertInstanceOf(AllowedValues::class, $allowed);
        $this->assertSame(['DRE'], $allowed->regions);
    }
}

/**
 * Test double for OracleExtractionService — returns canned rows keyed by the
 * "shape" of the query (kpi / dist / count / page for select(); the map for
 * selectMany()).
 */
final class FakeOracle extends OracleExtractionService
{
    /** @var array<string, list<array<string,mixed>>> */
    public array $many = [];

    /** @param array<string, list<array<string,mixed>>> $canned */
    public function __construct(private array $canned)
    {
        // no parent ctor — we never connect
    }

    /** @var array{sql:string, binds:array<string,mixed>}|null last select() */
    public ?array $lastSelect = null;

    public function select(string $sql, array $binds = [], int $maxRows = 5000): array
    {
        $this->lastSelect = ['sql' => $sql, 'binds' => $binds];
        $key = $this->classify($sql);
        $rows = $this->canned[$key] ?? [];

        return ['columns' => $rows === [] ? [] : array_keys($rows[0]), 'rows' => $rows];
    }

    /** @var array<string, array{sql:string, binds:array<string,mixed>}> last selectMany() batch */
    public array $lastQueries = [];

    public function selectMany(array $queries, int $maxRows = 5000): array
    {
        $this->lastQueries = $queries;
        $out = [];
        foreach ($queries as $k => $spec) {
            $out[$k] = $this->many[$k] ?? ($this->canned[$this->classify($spec['sql'])] ?? []);
        }

        return $out;
    }

    private function classify(string $sql): string
    {
        if (str_contains($sql, 'NUI_TOTAL'))           { return 'ref'; }
        if (str_contains($sql, 'CONTACT_OK'))          { return 'kpi'; }
        if (str_contains($sql, 'GROUPING SETS'))       { return 'dist'; }
        if (str_contains($sql, 'GROUP BY SEGMENTATION')) { return 'segs'; }
        if (str_contains($sql, 'COUNT(*) N'))          { return 'count'; }
        if (str_contains($sql, 'FETCH NEXT'))          { return 'page'; }

        return 'other';
    }
}
