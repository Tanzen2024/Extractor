<?php

use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\PrepaidRecency;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotScanEngine;
use CodeIgniter\Cache\Handlers\DummyHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Oracle as OracleConfig;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * "Ancienneté du dernier achat" end to end on a snapshot: the two source
 * columns travel CSV -> DuckDB index -> /dashboard/stats; the production
 * engine (DuckDB) gives exactly the PHP reference (SnapshotScanEngine) under
 * the page filters; every in-perimeter PREPAID contract is in exactly one
 * class (sum == PREPAID count of the METER chart); two contracts sharing a
 * meter are two customers; a snapshot without the columns gives "not
 * available", never zeros.
 *
 * @internal
 */
final class SnapshotRecencyTest extends CIUnitTestCase
{
    private const HEADER = [...SnapshotFixture::HEADER, PrepaidRecency::DAYS_COLUMN, PrepaidRecency::STATUS_COLUMN];

    /** (status, days) per PREPAID row, cycling: every class, + 2 pairs that break the source contract. */
    private const PAIRS = [
        ['KNOWN', '0'], ['KNOWN', '29'], ['KNOWN', '30'], ['KNOWN', '59'], ['KNOWN', '60'], ['KNOWN', '89'],
        ['KNOWN', '90'], ['KNOWN', '179'], ['KNOWN', '180'], ['KNOWN', '359'], ['KNOWN', '360'], ['KNOWN', '4000'],
        ['NO_PURCHASE', ''], ['POST_REFERENCE', ''], ['UNKNOWN', ''], ['KNOWN', ''], ['KNOWN', '-2'],
    ];

    private SnapshotFixture $fx;

    protected function setUp(): void
    {
        parent::setUp();
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }
        $this->fx = new SnapshotFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private static function rows(): array
    {
        $statuses = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVE.', 'SUSPENDED (DELINQUENT ACCOUNT)'];
        $rows     = [];
        for ($n = 1; $n <= 120; $n++) {
            $meter = ['PREPAID', 'PREPAID', 'POSTPAID', 'Compteurs Communicants'][$n % 4];
            [$status, $days] = $meter === 'PREPAID' ? self::PAIRS[$n % count(self::PAIRS)] : ['', ''];
            $rows[] = SnapshotFixture::row($n, [
                'REGION'       => ['DRC', 'DRE', 'DRO'][$n % 3],
                'STATUS'       => $statuses[$n % 4 === 1 ? $n % 3 : $n % 4],
                'METER'        => $meter,
                'SEGMENTATION' => $meter === 'PREPAID' ? ['1-Stable', '5-Dormant', '6-Never Vending'][$n % 3] : '1 PERFECT',
                'DATE_AB'      => $n % 2 === 0 ? '2020-01-15' : '2024-06-30',
                // Two contracts on one meter (rows 1 and 13, both PREPAID and active): two customers.
                'METER_NO'     => in_array($n, [1, 13], true) ? 'M-SHARED' : 'M' . $n,
                PrepaidRecency::STATUS_COLUMN => $status,
                PrepaidRecency::DAYS_COLUMN   => $days,
            ]);
        }

        return $rows;
    }

    private function installWithColumns(): void
    {
        $r = $this->fx->install(self::rows(), self::HEADER);
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
    }

    /** @return array<string, FilterCriteria> */
    private static function criteriaSets(): array
    {
        return [
            'aucun filtre'         => FilterCriteria::none(),
            'section Prepaid'      => FilterCriteria::fromArray(['meters' => ['PREPAID']]),
            'Prepaid + région'     => FilterCriteria::fromArray(['meters' => ['PREPAID'], 'regions' => ['DRE']]),
            'Prepaid + segments'   => FilterCriteria::fromArray(['meters' => ['PREPAID'], 'segmentations' => ['5-Dormant', '6-Never Vending']]),
            'Prepaid + date'       => FilterCriteria::fromArray(['meters' => ['PREPAID'], 'dateFrom' => '2024-01-01']),
            'Prepaid + un statut'  => FilterCriteria::fromArray(['meters' => ['PREPAID'], 'statuses' => ['ACTIVE']]),
            'section Postpaid'     => FilterCriteria::fromArray(['meters' => ['POSTPAID']]),
        ];
    }

    public function testTheIndexCarriesBothColumns(): void
    {
        $this->installWithColumns();
        $snapshot = $this->fx->store->active();
        $cols     = array_column((new DuckDb($this->fx->config))->query((new SnapshotIndex($this->fx->config))->path($snapshot), 'DESCRIBE ' . SnapshotIndex::TABLE), 'column_name');

        $this->assertContains(PrepaidRecency::DAYS_COLUMN, $cols);
        $this->assertContains(PrepaidRecency::STATUS_COLUMN, $cols);
    }

    public function testDuckDbEqualsTheReferenceAndEveryPrepaidContractIsInExactlyOneClass(): void
    {
        $this->installWithColumns();
        $snapshot = $this->fx->store->active();
        $duck     = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $scan     = new SnapshotScanEngine($snapshot);

        foreach (self::criteriaSets() as $name => $c) {
            $expected = $scan->distributions($c);
            $actual   = $duck->distributions($c);
            $this->assertIsArray($actual['recency'], $name);
            ksort($expected['recency']);
            ksort($actual['recency']);
            $this->assertSame($expected['recency'], $actual['recency'], "recency — {$name}");
            $fromSummary = $duck->summary($c, ['PREPAID'])['distributions']['recency'];
            ksort($fromSummary);
            $this->assertSame($actual['recency'], $fromSummary, "summary — {$name}");

            // Reconciliation: one class per PREPAID contract, nobody lost, nobody twice.
            $prepaid = (int) ($actual['meterType']['PREPAID'] ?? 0);
            $this->assertSame($prepaid, array_sum($actual['recency']), "sum == PREPAID count — {$name}");
            $prepaidCount = $c->meters === [] || in_array('PREPAID', $c->meters, true)
                ? $duck->count(FilterCriteria::fromArray(array_merge(self::criteriaArray($c), ['meters' => ['PREPAID']])))
                : 0;
            $this->assertSame($prepaidCount, array_sum($actual['recency']), "sum == filtered PREPAID count — {$name}");
        }
    }

    public function testHandCountedClassesForTheUnfilteredPrepaidPerimeter(): void
    {
        $this->installWithColumns();
        $duck = (new SnapshotIndex($this->fx->config))->engine($this->fx->store->active());

        $expected = [];
        $active   = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVATION IN PROCESS.'];
        foreach (self::rows() as $r) {
            if ($r['METER'] !== 'PREPAID' || ! in_array($r['STATUS'], $active, true)) {
                continue;
            }
            $class            = PrepaidRecency::classify($r[PrepaidRecency::STATUS_COLUMN], $r[PrepaidRecency::DAYS_COLUMN]);
            $expected[$class] = ($expected[$class] ?? 0) + 1;
        }
        $actual = $duck->distributions(FilterCriteria::none())['recency'];
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertArrayHasKey(PrepaidRecency::INCONSISTENT, $actual, 'pairs breaking the contract are counted apart');
        $this->assertArrayHasKey('D360_PLUS', $actual, '4 000 days stays in 360 and more');
    }

    public function testTwoContractsOnOneMeterAreTwoCustomers(): void
    {
        $this->installWithColumns();
        $duck = (new SnapshotIndex($this->fx->config))->engine($this->fx->store->active());

        $shared = FilterCriteria::fromArray(['meters' => ['PREPAID']]);
        $rows   = array_filter($duck->page($shared, 'M-SHARED', null, 'asc', 0, 50), static fn (array $r): bool => $r['METER_NO'] === 'M-SHARED');
        $this->assertCount(2, $rows, 'both contracts of the shared meter are listed');
        $this->assertSame((int) $duck->distributions($shared)['meterType']['PREPAID'], array_sum($duck->distributions($shared)['recency']));
    }

    public function testStatsCarryTheBreakdownWithItsDenominator(): void
    {
        $this->installWithColumns();
        $duck  = (new SnapshotIndex($this->fx->config))->engine($this->fx->store->active());
        $stats = (new DashboardService($duck, new OracleConfig(), new DummyHandler()))->stats(FilterCriteria::fromArray(['meters' => ['PREPAID']]));
        $b     = $stats['charts']['prepaidRecency'];

        $this->assertTrue($b['available']);
        $this->assertSame($stats['totalRows'], $b['total'], 'denominator = the Prepaid section total');
        $this->assertSame($b['total'], $b['sum']);
        $this->assertSame(0, $b['difference']);
        $this->assertSame(PrepaidRecency::CLASSES, array_column($b['classes'], 'value'), 'every class, zeros included');
        $this->assertGreaterThan(0, $b['inconsistent']);
    }

    public function testASnapshotWithoutTheColumnsSaysNotAvailable(): void
    {
        $rows = array_map(static function (array $r): array {
            unset($r[PrepaidRecency::DAYS_COLUMN], $r[PrepaidRecency::STATUS_COLUMN]);

            return $r;
        }, self::rows());
        $r = $this->fx->install($rows); // the former 27-column header
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $snapshot = $this->fx->store->active();
        $duck     = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $this->assertNull($duck->distributions(FilterCriteria::none())['recency']);
        $this->assertNull((new SnapshotScanEngine($snapshot))->distributions(FilterCriteria::none())['recency']);

        $b = (new DashboardService($duck, new OracleConfig(), new DummyHandler()))->stats(FilterCriteria::fromArray(['meters' => ['PREPAID']]))['charts']['prepaidRecency'];
        $this->assertFalse($b['available']);
        $this->assertSame([], $b['classes'], 'no fabricated figure');
    }

    /** @return array<string, mixed> the fromArray() input of $c, for re-use with another meter list */
    private static function criteriaArray(FilterCriteria $c): array
    {
        $out = [];
        foreach (['regions', 'segmentations', 'statuses'] as $p) {
            if ($c->{$p} !== []) {
                $out[$p] = $c->{$p};
            }
        }
        if ($c->dateFrom !== null) {
            $out['dateFrom'] = $c->dateFrom->format('Y-m-d');
        }

        return $out;
    }
}
