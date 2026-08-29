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
 * completeness %, and the pagination guard rails.
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
            'dist' => [
                ['DIM' => 'region', 'VAL' => 'DCUD', 'N' => '600'],
                ['DIM' => 'region', 'VAL' => 'DCUY', 'N' => '400'],
                ['DIM' => 'status', 'VAL' => 'ACTIVE', 'N' => '660'],
                ['DIM' => 'status', 'VAL' => 'INACTIVE.', 'N' => '340'],
                ['DIM' => 'segmentation', 'VAL' => null, 'N' => '10'],
                ['DIM' => 'segmentation', 'VAL' => '8 Autre', 'N' => '990'],
            ],
        ]);

        $stats = $this->service($oracle)->stats(FilterCriteria::none());

        $this->assertSame(1000, $stats['totalRows']);
        $this->assertSame(660, $stats['kpis']['actifs']['value']);
        $this->assertSame(66.0, $stats['kpis']['actifs']['pct']);
        $this->assertSame(383, $stats['kpis']['avecCompteur']['value']);
        $this->assertSame(804, $stats['kpis']['contacts']['value']);
        $this->assertSame(80.4, $stats['kpis']['contacts']['pct']);
    }

    public function testStatsShapesTheThreeDistributionsAndFoldsTheTail(): void
    {
        $dist = [['DIM' => 'region', 'VAL' => 'DCUD', 'N' => '10']];
        for ($i = 0; $i < 12; $i++) {
            $dist[] = ['DIM' => 'segmentation', 'VAL' => "S{$i}", 'N' => (string) (100 - $i)];
        }

        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '100', 'ACTIFS' => '0', 'AVEC_COMPTEUR' => '0', 'PHONE_OK' => '0', 'EMAIL_OK' => '0', 'REFGEO_OK' => '0', 'METERNO_OK' => '0', 'NIU_OK' => '0', 'NAME_OK' => '0', 'CONTACT_OK' => '0']],
            'dist' => $dist,
        ]);

        $charts = $this->service($oracle)->stats(FilterCriteria::none())['charts'];

        $this->assertCount(1, $charts['region']);
        $this->assertSame([], $charts['status']);
        // 12 segmentation buckets -> top 7 + "Autres" (SEGMENTATION_TOP_N = 8).
        $this->assertCount(8, $charts['segmentation']);
        $this->assertSame('Autres', end($charts['segmentation'])['value']);
        $this->assertCount(6, $charts['completeness']);
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

    public function testCompletenessPercentagesAreRoundedToOneDecimal(): void
    {
        $oracle = new FakeOracle([
            'kpi'  => [['TOTAL' => '3', 'ACTIFS' => '0', 'AVEC_COMPTEUR' => '0', 'PHONE_OK' => '1', 'EMAIL_OK' => '0', 'REFGEO_OK' => '3', 'METERNO_OK' => '2', 'NIU_OK' => '0', 'NAME_OK' => '3', 'CONTACT_OK' => '1']],
            'dist' => [],
        ]);

        $completeness = $this->service($oracle)->stats(FilterCriteria::none())['charts']['completeness'];
        $byField      = array_column($completeness, 'pct', 'field');

        $this->assertSame(33.3, $byField['Téléphone']);
        $this->assertSame(100.0, $byField['Réf. géo.']);
        $this->assertSame(66.7, $byField['N° compteur']);
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
        $this->assertSame(\App\Services\CustomersList\QueryBuilder::TABLE_COLUMNS, $result['columns']);
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

    public function select(string $sql, array $binds = [], int $maxRows = 5000): array
    {
        $key = $this->classify($sql);
        $rows = $this->canned[$key] ?? [];

        return ['columns' => $rows === [] ? [] : array_keys($rows[0]), 'rows' => $rows];
    }

    public function selectMany(array $queries, int $maxRows = 5000): array
    {
        $out = [];
        foreach ($queries as $k => $spec) {
            $out[$k] = $this->many[$k] ?? ($this->canned[$this->classify($spec['sql'])] ?? []);
        }

        return $out;
    }

    private function classify(string $sql): string
    {
        if (str_contains($sql, 'CONTACT_OK'))          { return 'kpi'; }
        if (str_contains($sql, 'GROUPING SETS'))       { return 'dist'; }
        if (str_contains($sql, 'COUNT(*) N'))          { return 'count'; }
        if (str_contains($sql, 'FETCH NEXT'))          { return 'page'; }

        return 'other';
    }
}
