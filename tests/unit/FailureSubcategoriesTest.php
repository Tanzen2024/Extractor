<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FailureSubcategories;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\CustomersList\PostpaidSegmentations;
use App\Services\Refresh\OracleSnapshotExtractor;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotQueryEngine;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotScanEngine;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * The analytic breakdown of the 6 Défaillant profile: its SQL rule (read from
 * customers_list.sql, not a copy), its figures on both kinds of snapshot,
 * through every engine — and that it stays out of the official segmentation.
 *
 * @internal
 */
final class FailureSubcategoriesTest extends CIUnitTestCase
{
    private const SQL_FILE = __DIR__ . '/../../docs/snapshot/source/customers_list.sql';

    private ?SnapshotFixture $fx = null;

    protected function tearDown(): void
    {
        $this->fx?->cleanup();
        parent::tearDown();
    }

    private function sql(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::SQL_FILE));
    }

    /** The column's whole CASE, as written in customers_list.sql. */
    private function rule(): string
    {
        $this->assertSame(1, preg_match("/CASE\\s+WHEN p\\.METER_PREP IS NULL\\s+AND pp\\.PROFILE = '6 Défaillant'.*?END AS FAILURE_SUBCATEGORY/s", $this->sql(), $m));

        return substr($m[0], 0, -strlen(' AS FAILURE_SUBCATEGORY'));
    }

    /** @return array<string, array{string|null, int, float|null, int, int, string|null}> */
    public static function ruleCases(): array
    {
        $d = '6 Défaillant';

        return [
            'all late'                  => [$d, 4, 0.0, 4, 0, 'ALWAYS_LATE'],
            'none paid'                 => [$d, 4, 0.0, 0, 4, 'NEVER_PAID'],
            'some paid'                 => [$d, 4, 0.0, 1, 2, 'SOMETIME_PAID'],
            'overlap late + unpaid'     => [$d, 4, 0.0, 4, 4, 'ALWAYS_LATE'],
            'overlap late + some paid'  => [$d, 4, 0.0, 4, 1, 'ALWAYS_LATE'],
            'counter above bill_number' => [$d, 4, 0.0, 0, 5, 'OTHER'],
            'rate NULL'                 => [$d, 4, null, 0, 0, 'OTHER'],
            'rate below 0'              => [$d, 4, -0.1, 4, 0, 'OTHER'],
            'rate above 1'              => [$d, 4, 1.1, 0, 4, 'OTHER'],
            'another profile'           => ['5 Delinquant', 4, 0.1, 4, 0, null],
            'unclassified'              => [null, 0, null, 0, 0, null],
        ];
    }

    /** @dataProvider ruleCases */
    public function testTheRuleGivesEveryDefaillantExactlyOneComponent(?string $profile, int $bills, ?float $rate, int $bad, int $noPaid, ?string $expected): void
    {
        if (! class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension absent.');
        }
        $db   = new SQLite3(':memory:');
        $stmt = $db->prepare('SELECT ' . $this->rule() . ' AS c FROM (SELECT NULL AS METER_PREP) p, '
            . '(SELECT :profile AS PROFILE, :bills AS BILL_NUMBER, :rate AS RATE, :bad AS BAD_COUNT, :np AS NO_PAID_COUNT) pp');
        $stmt->bindValue(':profile', $profile, $profile === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':bills', $bills, SQLITE3_INTEGER);
        $stmt->bindValue(':rate', $rate, $rate === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':bad', $bad, SQLITE3_INTEGER);
        $stmt->bindValue(':np', $noPaid, SQLITE3_INTEGER);

        $this->assertSame($expected, $stmt->execute()->fetchArray(SQLITE3_ASSOC)['c']);
        $db->close();
    }

    public function testThePrepaidNeverGetsAComponentAndTheCodesAreTheApplicationList(): void
    {
        $rule = $this->rule();
        $this->assertStringContainsString('WHEN p.METER_PREP IS NULL', $rule);

        preg_match_all("/(?:THEN|ELSE) '([A-Z_]+)'/", $rule, $m);
        $codes = $m[1];
        sort($codes);
        $values = FailureSubcategories::VALUES;
        sort($values);
        $this->assertSame($values, $codes);
    }

    public function testBreakdownAddsUpAndReportsWhatItCannotPlace(): void
    {
        $out = FailureSubcategories::breakdown(['6 Défaillant' => 10, '1 PERFECT' => 99], ['NEVER_PAID' => 6, 'ALWAYS_LATE' => 4]);
        $this->assertSame(10, $out['total']);
        $this->assertSame(0, $out['unassigned']);
        $this->assertSame(FailureSubcategories::VALUES, array_column($out['components'], 'value'));
        $this->assertSame([4, 0, 6, 0], array_column($out['components'], 'count'));

        $this->assertSame(10, FailureSubcategories::breakdown(['6 Défaillant' => 10], [])['unassigned']);
        $this->assertSame(0, FailureSubcategories::breakdown([], [])['total']);
    }

    public function testCriteriaValidatesRoundTripsAndDescribes(): void
    {
        $allowed = new AllowedValues(
            regions: [], divisions: [], agences: [], statuses: ['ACTIVE'], segmentations: [], segmentsTresor: [],
            meters: [], voltages: [], niuQualities: [], dateBounds: ['min' => null, 'max' => null],
        );
        $c = FilterCriteria::fromRequest(['failure_subcategory' => ['NEVER_PAID']], $allowed);

        $this->assertSame(['NEVER_PAID'], $c->failureSubcategories);
        $this->assertSame(['NEVER_PAID'], FilterCriteria::fromArray($c->toArray())->failureSubcategories);
        $this->assertSame(['NEVER_PAID'], $c->withoutSegmentations()->failureSubcategories);
        $this->assertNotSame(FilterCriteria::none()->cacheKey(), $c->cacheKey());
        $this->assertSame('Never Paid', $c->describe()['Composante Défaillant']);

        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['failure_subcategory' => ['8 Never Paid']], $allowed);
    }

    /** @return list<array<string, string>> 2 + 3 + 1 Défaillant, 1 other profile, 1 excluded status, 1 prepaid */
    private function rows(string $always, string $sometime, string $never, array $component = []): array
    {
        $post = static fn (int $n, string $seg, string $key = '', string $status = 'ACTIVE'): array => SnapshotFixture::row($n, [
            'METER' => 'POSTPAID', 'SEGMENTATION' => $seg, 'STATUS' => $status, 'FAILURE_SUBCATEGORY' => $component[$key] ?? '',
        ]);

        return [
            $post(1, $always, 'a'), $post(2, $always, 'a'),
            $post(3, $sometime, 's'), $post(4, $sometime, 's'), $post(5, $sometime, 's'),
            $post(6, $never, 'n'),
            $post(7, '2 RELIABLE'),
            $post(8, $never, 'n', 'INACTIVE.'),
            SnapshotFixture::row(9, ['METER' => 'PREPAID', 'SEGMENTATION' => '3-At Risk']),
        ];
    }

    /** @return array<string, array{SnapshotQueryEngine, SnapshotRowSource}> */
    private function engines(): array
    {
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }
        $snapshot = $this->fx->store->active();
        $source   = new SnapshotRowSource(snapshot: $snapshot, store: $this->fx->store);

        return [
            'duckdb' => [(new SnapshotIndex($this->fx->config))->engine($snapshot), $source],
            'scan'   => [new SnapshotScanEngine($snapshot), $source],
        ];
    }

    private function assertBreakdownEverywhere(): void
    {
        foreach ($this->engines() as $name => [$engine, $source]) {
            $dist = $engine->distributions(FilterCriteria::none());
            $out  = FailureSubcategories::breakdown($dist['segmentation'], $dist['failure']);

            $this->assertSame(6, $out['total'], $name);
            $this->assertSame([2, 3, 1, 0], array_column($out['components'], 'count'), $name);
            $this->assertSame(0, $out['unassigned'], $name);

            // Official segmentation: one profile, whatever the components.
            $this->assertSame(6, $dist['segmentation'][PostpaidSegmentations::DEFAILLANT], $name);
            $this->assertSame([], array_values(array_intersect(array_keys($dist['segmentation']), FailureSubcategories::VALUES)), $name);
            foreach (array_keys(FailureSubcategories::LEGACY) as $former) {
                $this->assertArrayNotHasKey($former, $dist['segmentation'], $name);
            }

            // Detail list + export of one component: same population as its figure.
            $c = FilterCriteria::fromArray(['failureSubcategories' => ['SOMETIME_PAID']]);
            $this->assertSame(3, $engine->count($c), $name);
            $this->assertSame(3, $source->count($c), $name);
            $page = $engine->page($c, '', null, 'asc', 0, 50);
            $this->assertSame(['900003', '900004', '900005'], array_column($page, 'CONTRACT'), $name);
            $this->assertSame([PostpaidSegmentations::DEFAILLANT], array_values(array_unique(array_column($page, 'SEGMENTATION'))), $name);

            $exported = [];
            $source->stream($c, static function (array $row) use (&$exported): void {
                $exported[] = $row;
            });
            $this->assertCount(3, $exported, $name);
            $this->assertSame(CustomerListExportService::COLUMNS, array_keys($exported[0]), $name . ': no analytic column in the export');
            $this->assertSame(PostpaidSegmentations::DEFAILLANT, trim($exported[0]['SEGMENTATION']), $name);
        }
    }

    public function testSnapshotHoldingTheFormerLabels(): void
    {
        $this->fx = new SnapshotFixture();
        $r = $this->fx->install($this->rows('6 Always Late', '7 sometime Paid', '8 Never Paid'));
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $this->assertBreakdownEverywhere();
    }

    public function testSnapshotCarryingTheColumn(): void
    {
        $this->fx = new SnapshotFixture();
        $rows = $this->rows('6 Défaillant', '6 Défaillant', '6 Défaillant', ['a' => 'ALWAYS_LATE', 's' => 'SOMETIME_PAID', 'n' => 'NEVER_PAID']);
        $this->fx->deliver(SnapshotFixture::csv($rows, array_merge(SnapshotFixture::HEADER, [FailureSubcategories::COLUMN])));
        $r = (new SnapshotInstaller($this->fx->store))->install();
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $this->assertBreakdownEverywhere();
    }

    public function testFiltersOfferNoComponent(): void
    {
        $this->fx = new SnapshotFixture();
        $this->fx->install($this->rows('6 Always Late', '7 sometime Paid', '8 Never Paid'));

        foreach ($this->engines() as $name => [$engine]) {
            $offered = array_column(PostpaidSegmentations::foldOptions($engine->filterOptions())['segmentations'], 'value');
            $this->assertSame([], array_values(array_intersect($offered, array_merge(FailureSubcategories::VALUES, array_keys(FailureSubcategories::LEGACY)))), $name);
        }
    }

    public function testRefreshReadsTheColumnOnlyWhenTheTableHasIt(): void
    {
        $this->assertStringNotContainsString(FailureSubcategories::COLUMN, OracleSnapshotExtractor::selectSql());
        $this->assertStringContainsString(FailureSubcategories::COLUMN, OracleSnapshotExtractor::selectSql([FailureSubcategories::COLUMN]));
        $this->assertNotContains(FailureSubcategories::COLUMN, CustomerListExportService::COLUMNS);
    }
}
