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
        $this->assertSame(1, preg_match("/CASE\\s+WHEN p\\.METER_PREP IS NULL\\s+AND COALESCE\\(pp\\.PROFILE, '6 Défaillant'\\) = '6 Défaillant'.*?END AS FAILURE_SUBCATEGORY/s", $this->sql(), $m));

        return substr($m[0], 0, -strlen(' AS FAILURE_SUBCATEGORY'));
    }

    /** @return array<string, array{string|null, int|null, float|null, int|null, int|null, string|null}> */
    public static function ruleCases(): array
    {
        $d = '6 Défaillant';

        return [
            'all late'                  => [$d, 4, 0.0, 4, 0, 'ALWAYS_LATE'],
            'none paid'                 => [$d, 4, 0.0, 0, 4, 'NEVER_PAID'],
            'some paid'                 => [$d, 4, 0.0, 1, 2, 'SOMETIME_PAID'],
            'overlap late + unpaid'     => [$d, 4, 0.0, 4, 4, 'ALWAYS_LATE'],
            'overlap late + some paid'  => [$d, 4, 0.0, 4, 1, 'ALWAYS_LATE'],
            // The former OTHER cases: NEVER_PAID since 2026-11-09.
            'counter above bill_number' => [$d, 4, 0.0, 0, 5, 'NEVER_PAID'],
            'rate NULL'                 => [$d, 4, null, 0, 0, 'NEVER_PAID'],
            'rate below 0'              => [$d, 4, -0.1, 4, 0, 'NEVER_PAID'],
            'rate above 1'              => [$d, 4, 1.1, 0, 4, 'NEVER_PAID'],
            'bill_number 0 in profile'  => [$d, 0, 0.0, 0, 0, 'NEVER_PAID'],
            'another profile'           => ['5 Delinquant', 4, 0.1, 4, 0, null],
            // No usable billing history (no profile computed): 6 Défaillant
            // by the last resort of SEGMENTATION, so NEVER_PAID here (2026-11-09).
            'no profile, bill_number 0' => [null, 0, 0.0, 0, 0, 'NEVER_PAID'],
            'no profile row at all'     => [null, null, null, null, null, 'NEVER_PAID'],
        ];
    }

    /** @dataProvider ruleCases */
    public function testTheRuleGivesEveryDefaillantExactlyOneComponent(?string $profile, ?int $bills, ?float $rate, ?int $bad, ?int $noPaid, ?string $expected): void
    {
        if (! class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension absent.');
        }
        $db   = new SQLite3(':memory:');
        $stmt = $db->prepare('SELECT ' . $this->rule() . ' AS c FROM (SELECT NULL AS METER_PREP) p, '
            . '(SELECT :profile AS PROFILE, :bills AS BILL_NUMBER, :rate AS RATE, :bad AS BAD_COUNT, :np AS NO_PAID_COUNT) pp');
        $stmt->bindValue(':profile', $profile, $profile === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':bills', $bills, $bills === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':rate', $rate, $rate === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':bad', $bad, $bad === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':np', $noPaid, $noPaid === null ? SQLITE3_NULL : SQLITE3_INTEGER);

        $this->assertSame($expected, $stmt->execute()->fetchArray(SQLITE3_ASSOC)['c']);
        $db->close();
    }

    public function testThePrepaidNeverGetsAComponentAndTheCodesAreTheApplicationList(): void
    {
        $rule = $this->rule();
        $this->assertStringContainsString('WHEN p.METER_PREP IS NULL', $rule);

        preg_match_all("/(?:THEN|ELSE) '([A-Z_]+)'/", $rule, $m);
        $codes = array_values(array_unique($m[1]));
        sort($codes);
        $this->assertStringNotContainsString("'OTHER'", $rule);
        $this->assertSame(['ALWAYS_LATE', 'NEVER_PAID', 'SOMETIME_PAID'], FailureSubcategories::VALUES);
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
        $this->assertSame([4, 6, 0], array_column($out['components'], 'count'));
        $this->assertSame($out['total'], array_sum(array_column($out['components'], 'count')));

        // A stray OTHER is not a component: it shows as unassigned, never hidden.
        $stray = FailureSubcategories::breakdown(['6 Défaillant' => 10], ['NEVER_PAID' => 6, 'ALWAYS_LATE' => 3, 'OTHER' => 1]);
        $this->assertSame([3, 6, 0], array_column($stray['components'], 'count'));
        $this->assertSame(1, $stray['unassigned']);

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

        foreach (['8 Never Paid', 'OTHER'] as $refused) {
            try {
                FilterCriteria::fromRequest(['failure_subcategory' => [$refused]], $allowed);
                $this->fail("{$refused} accepted");
            } catch (InvalidFilterException) {
                $this->addToAssertionCount(1);
            }
        }
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
            $this->assertSame([2, 1, 3], array_column($out['components'], 'count'), $name);
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
            $this->assertSame(CustomerListExportService::EXPORT_COLUMNS, array_keys($exported[0]), $name . ': no analytic column in the export');
            $this->assertNotContains(FailureSubcategories::COLUMN, array_keys($exported[0]));
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

    /** The former "10 Other" label: counted in Never Paid, figure = list = export, total kept. */
    public function testFormerOtherLabelIsCountedInNeverPaid(): void
    {
        $this->fx = new SnapshotFixture();
        $rows     = $this->rows('6 Always Late', '7 sometime Paid', '8 Never Paid');
        $rows[]   = SnapshotFixture::row(10, ['METER' => 'POSTPAID', 'SEGMENTATION' => '10 Other', 'STATUS' => 'ACTIVE']);
        $r        = $this->fx->install($rows);
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        foreach ($this->engines() as $name => [$engine, $source]) {
            $dist = $engine->distributions(FilterCriteria::none());
            $out  = FailureSubcategories::breakdown($dist['segmentation'], $dist['failure']);

            $this->assertSame(7, $out['total'], $name);
            $this->assertSame([2, 2, 3], array_column($out['components'], 'count'), $name);
            $this->assertSame(0, $out['unassigned'], $name);
            $this->assertArrayNotHasKey('OTHER', $dist['failure'], $name);

            $c = FilterCriteria::fromArray(['failureSubcategories' => ['NEVER_PAID']]);
            $this->assertSame(2, $engine->count($c), $name);
            $this->assertSame(2, $source->count($c), $name);
            $this->assertSame(['900006', '900010'], array_column($engine->page($c, '', null, 'asc', 0, 50), 'CONTRACT'), $name);
        }
    }

    /**
     * The former unclassified customers ("8 Autre": no profile row) of a
     * snapshot produced before the rule: 6 Défaillant and NEVER_PAID through
     * every engine, the export and the filters; nobody left outside the six.
     */
    public function testFormerUnclassifiedAreDefaillantNeverPaidEverywhere(): void
    {
        $this->fx = new SnapshotFixture();
        $rows     = $this->rows('6 Always Late', '7 sometime Paid', '8 Never Paid');
        $rows[]   = SnapshotFixture::row(10, ['METER' => 'POSTPAID', 'SEGMENTATION' => '8 Autre', 'STATUS' => 'ACTIVE']);
        $rows[]   = SnapshotFixture::row(11, ['METER' => 'Compteurs Communicants', 'SEGMENTATION' => '8 Autre', 'STATUS' => 'ACTIVE']);
        $r        = $this->fx->install($rows);
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $postpaid = FilterCriteria::fromArray(['meters' => ['POSTPAID', 'Compteurs Communicants']]);
        foreach ($this->engines() as $name => [$engine, $source]) {
            $dist = $engine->distributions($postpaid);
            $out  = FailureSubcategories::breakdown($dist['segmentation'], $dist['failure']);

            $this->assertArrayNotHasKey('', $dist['segmentation'], $name . ': no unclassified');
            $this->assertArrayNotHasKey('8 Autre', $dist['segmentation'], $name);
            $this->assertSame($engine->count($postpaid), array_sum($dist['segmentation']), $name . ': six profiles = population');
            $this->assertSame(8, $out['total'], $name);
            $this->assertSame([2, 3, 3], array_column($out['components'], 'count'), $name . ': Always Late, Never Paid, Sometime Paid');
            $this->assertSame(0, $out['unassigned'], $name);
            $this->assertSame($out['total'], array_sum(array_column($out['components'], 'count')), $name);

            $c = FilterCriteria::fromArray(['failureSubcategories' => ['NEVER_PAID']]);
            $this->assertSame(3, $engine->count($c), $name);
            $this->assertSame(3, $source->count($c), $name);
            $this->assertSame(['900006', '900010', '900011'], array_column($engine->page($c, '', null, 'asc', 0, 50), 'CONTRACT'), $name);

            $d = FilterCriteria::fromArray(['segmentations' => [PostpaidSegmentations::DEFAILLANT]]);
            $this->assertSame(8, $source->count($d), $name . ': export = chart');
        }

        $exported = [];
        (new SnapshotRowSource(snapshot: $this->fx->store->active(), store: $this->fx->store))
            ->stream(FilterCriteria::fromArray(['meters' => ['POSTPAID', 'Compteurs Communicants']]), static function (array $row) use (&$exported): void {
                $exported[$row['CONTRACT']] = $row['SEGMENTATION'];
            });
        $this->assertSame(PostpaidSegmentations::DEFAILLANT, $exported['900010']);
        $this->assertSame(PostpaidSegmentations::DEFAILLANT, $exported['900011']);
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

    public function testRefreshReadsTheColumnWhenTheTableHasItAndComputesItOtherwise(): void
    {
        $this->assertStringNotContainsString(FailureSubcategories::COLUMN, OracleSnapshotExtractor::selectSql());
        $this->assertNotContains(FailureSubcategories::COLUMN, CustomerListExportService::COLUMNS);

        // Table carrying the column (32-column model): read as is, no join.
        [$columns, $derived] = OracleSnapshotExtractor::plannedColumns([FailureSubcategories::COLUMN, 'PREPAID_RECENCY_DAYS']);
        $this->assertFalse($derived);
        $this->assertSame([FailureSubcategories::COLUMN, 'PREPAID_RECENCY_DAYS'], array_slice($columns, count(CustomerListExportService::COLUMNS)));
        $read = OracleSnapshotExtractor::selectSql([FailureSubcategories::COLUMN]);
        $this->assertStringContainsString('t.' . FailureSubcategories::COLUMN, $read);
        $this->assertStringNotContainsString('JOIN', $read);

        // 27-column table (model in production since 2026-11-16): computed,
        // always right after the exported columns (OPTIONAL_COLUMNS order).
        [$columns, $derived] = OracleSnapshotExtractor::plannedColumns(['PREPAID_RECENCY_DAYS']);
        $this->assertTrue($derived);
        $this->assertSame([FailureSubcategories::COLUMN, 'PREPAID_RECENCY_DAYS'], array_slice($columns, count(CustomerListExportService::COLUMNS)));
        [$columns, $derived] = OracleSnapshotExtractor::plannedColumns([]);
        $this->assertTrue($derived);
        $this->assertSame(array_merge(CustomerListExportService::COLUMNS, [FailureSubcategories::COLUMN]), $columns);

        $sql = OracleSnapshotExtractor::selectSql([FailureSubcategories::COLUMN], true);
        $this->assertMatchesRegularExpression('/\bEND AS ' . FailureSubcategories::COLUMN . '\b/', $sql);
        $this->assertStringNotContainsString('t.' . FailureSubcategories::COLUMN, $sql, 'not read: the table has no such column');
        $this->assertStringContainsString('FROM CMS_RFC.TB_CUSTOMERS_LIST t', $sql);
        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY SERVICE_NO ORDER BY DATE_ DESC NULLS LAST) AS RN', $sql);
        $this->assertStringContainsString('FROM ' . FailureSubcategories::SOURCE_TABLE, $sql);
        $this->assertStringContainsString('WHERE RN = 1', $sql, 'one row per contract: the join never multiplies a customer');
        $this->assertStringContainsString('LEFT JOIN', $sql, 'a customer without history is kept (and gets the last resort)');
        $this->assertStringContainsString('pp ON pp.SERVICE_NO = t.CONTRACT', $sql);
        $this->assertStringContainsString(FailureSubcategories::oracleRule('pp'), $sql);
        $this->assertSame(1, preg_match('/^[\x20-\x7E\n]*$/', $sql), 'ASCII only: no session character set can alter a label');

        // The rows that get a component: every spelling of 6 Défaillant, nothing else.
        $labels = FailureSubcategories::defaillantLabels();
        $this->assertContains(PostpaidSegmentations::DEFAILLANT, $labels);
        $this->assertContains(PostpaidSegmentations::DEFAILLANT_CORRUPTED, $labels);
        $this->assertContains('8 Autre', $labels);
        foreach (PostpaidSegmentations::VALUES as $profile) {
            $this->assertSame($profile === PostpaidSegmentations::DEFAILLANT, in_array($profile, $labels, true), $profile);
        }
        foreach ($labels as $label) {
            $this->assertStringContainsString(OracleSnapshotExtractor::oracleText($label), $sql, $label);
            $this->assertSame(PostpaidSegmentations::DEFAILLANT, PostpaidSegmentations::LEGACY[$label] ?? $label, $label);
        }
    }

    public function testOracleTextIsAsciiAndExact(): void
    {
        $this->assertSame("'8 Autre'", OracleSnapshotExtractor::oracleText('8 Autre'));
        $this->assertSame("'6 D' || UNISTR('\\00E9') || 'faillant'", OracleSnapshotExtractor::oracleText('6 Défaillant'));
        $this->assertSame("'6 D' || UNISTR('\\FFFD') || UNISTR('\\FFFD') || 'faillant'", OracleSnapshotExtractor::oracleText(PostpaidSegmentations::DEFAILLANT_CORRUPTED));
        $this->assertSame("'l''an'", OracleSnapshotExtractor::oracleText("l'an"));
        $this->assertSame("''", OracleSnapshotExtractor::oracleText(''));
        $this->assertSame("UNISTR('\\D83D\\DE00')", OracleSnapshotExtractor::oracleText("\u{1F600}"));
    }

    /**
     * The expression the refresh runs is the rule of customers_list.sql:
     * same conditions, same order (the source's own CASE, once the profile
     * test is set aside), and the same answer on every case of ruleCases().
     *
     * @dataProvider ruleCases
     */
    public function testTheComputedColumnIsTheSourceRule(?string $profile, ?int $bills, ?float $rate, ?int $bad, ?int $noPaid, ?string $expected): void
    {
        $norm = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));
        $this->assertSame(1, preg_match('/THEN\s+(CASE\s+WHEN pp\.RATE.*?END)\s+END$/s', $this->rule(), $m));
        $this->assertSame($norm($m[1]), $norm(FailureSubcategories::oracleRule('pp')));

        if ($expected === null) {
            $this->addToAssertionCount(1); // another profile: no component (the SEGMENTATION condition)

            return;
        }
        if (! class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension absent.');
        }
        $db   = new SQLite3(':memory:');
        $stmt = $db->prepare('SELECT ' . FailureSubcategories::oracleRule('pp') . ' AS c FROM '
            . '(SELECT :bills AS BILL_NUMBER, :rate AS RATE, :bad AS BAD_COUNT, :np AS NO_PAID_COUNT) pp');
        $stmt->bindValue(':bills', $bills, $bills === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':rate', $rate, $rate === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':bad', $bad, $bad === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':np', $noPaid, $noPaid === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $this->assertSame($expected, $stmt->execute()->fetchArray(SQLITE3_ASSOC)['c']);
        $db->close();
    }

    /**
     * Production shape of 2026-11-17 after the fix: the corrupted spelling
     * and '8 Autre' carry the computed component. Every Défaillant customer
     * counted once, in exactly one of the 3 components, sum = total, under
     * any filter, in every engine; the export of a component = its figure.
     */
    public function testComputedComponentsAddUpToTheTotalUnderAnyFilter(): void
    {
        $bad  = PostpaidSegmentations::DEFAILLANT_CORRUPTED;
        $rows = [];
        foreach ([
            [$bad, 'ALWAYS_LATE', 'DRC'], [$bad, 'NEVER_PAID', 'DRC'], [$bad, 'SOMETIME_PAID', 'DRC'], [$bad, 'SOMETIME_PAID', 'DRE'],
            [$bad, 'NEVER_PAID', 'DRE'], ['8 Autre', 'NEVER_PAID', 'DRE'], ['8 Autre', 'NEVER_PAID', 'DRC'], ['6 Défaillant', 'ALWAYS_LATE', 'DRE'],
            ['1 PERFECT', '', 'DRC'], ['4 At-Risk', '', 'DRE'],
        ] as [$seg, $component, $region]) {
            $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => 'POSTPAID', 'SEGMENTATION' => $seg, 'STATUS' => 'ACTIVE', 'REGION' => $region, 'FAILURE_SUBCATEGORY' => $component]);
        }
        // Outside the active perimeter: never counted, component or not.
        $rows[] = SnapshotFixture::row(11, ['METER' => 'POSTPAID', 'SEGMENTATION' => $bad, 'STATUS' => 'INACTIVE.', 'REGION' => 'DRC', 'FAILURE_SUBCATEGORY' => 'NEVER_PAID']);
        $rows[] = SnapshotFixture::row(12, ['METER' => 'PREPAID', 'SEGMENTATION' => '3-At Risk', 'REGION' => 'DRC']);

        $this->fx = new SnapshotFixture();
        $this->fx->deliver(SnapshotFixture::csv($rows, array_merge(SnapshotFixture::HEADER, [FailureSubcategories::COLUMN])));
        $r = (new SnapshotInstaller($this->fx->store))->install();
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $cases = [
            'no filter'  => [FilterCriteria::none(), 8, [2, 4, 2]],
            'region DRC' => [FilterCriteria::fromArray(['regions' => ['DRC']]), 4, [1, 2, 1]],
            'region DRE' => [FilterCriteria::fromArray(['regions' => ['DRE']]), 4, [1, 2, 1]],
            'Défaillant' => [FilterCriteria::fromArray(['segmentations' => [PostpaidSegmentations::DEFAILLANT]]), 8, [2, 4, 2]],
            'other only' => [FilterCriteria::fromArray(['segmentations' => ['1 PERFECT']]), 0, [0, 0, 0]],
        ];
        foreach ($this->engines() as $name => [$engine, $source]) {
            foreach ($cases as $case => [$criteria, $total, $counts]) {
                $dist = $engine->distributions($criteria);
                $out  = FailureSubcategories::breakdown($dist['segmentation'], $dist['failure']);
                $this->assertSame($total, $out['total'], "{$name} / {$case}");
                $this->assertSame($counts, array_column($out['components'], 'count'), "{$name} / {$case}");
                $this->assertSame($out['total'], array_sum($counts), "{$name} / {$case}: sum = total");
                $this->assertTrue($out['consistent'], "{$name} / {$case}");
                $this->assertSame(0, $out['unassigned'], "{$name} / {$case}");
            }

            // Each customer once: the three lists are disjoint and together the profile.
            $seen = [];
            foreach (FailureSubcategories::VALUES as $value) {
                $c    = FilterCriteria::fromArray(['failureSubcategories' => [$value]]);
                $page = array_column($engine->page($c, '', null, 'asc', 0, 50), 'CONTRACT');
                $this->assertSame($engine->count($c), count($page), "{$name} / {$value}");
                $this->assertSame($source->count($c), count($page), "{$name} / {$value}: export = figure");
                $seen = array_merge($seen, $page);
            }
            sort($seen);
            $this->assertSame(['900001', '900002', '900003', '900004', '900005', '900006', '900007', '900008'], $seen, $name);
        }
    }

    /** A snapshot produced before the column was computed: the control flags it, nothing is placed. */
    public function testBreakdownWithoutComponentsIsFlaggedInconsistent(): void
    {
        $out = FailureSubcategories::breakdown(['6 Défaillant' => 492123], ['NEVER_PAID' => 125348]);
        $this->assertFalse($out['consistent']);
        $this->assertSame(366775, $out['unassigned']);
        $this->assertSame([0, 125348, 0], array_column($out['components'], 'count'), 'never balanced into a component');

        $this->assertTrue(FailureSubcategories::breakdown([], [])['consistent'], 'no Défaillant: nothing to break down');
        $this->assertTrue(FailureSubcategories::breakdown(['6 Défaillant' => 3], ['ALWAYS_LATE' => 1, 'NEVER_PAID' => 1, 'SOMETIME_PAID' => 1])['consistent']);
    }
}
