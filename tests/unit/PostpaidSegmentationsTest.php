<?php

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\PostpaidSegmentations;
use App\Services\CustomersList\PrepaidSegmentations;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotScanEngine;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * The single POSTPAID / Compteurs Communicants profile rule: its exact SQL
 * text (read from customers_list.sql, not a copy) on every boundary, then the
 * values it produces through filters, charts and exports.
 *
 * @internal
 */
final class PostpaidSegmentationsTest extends CIUnitTestCase
{
    private const SQL_FILE = __DIR__ . '/../../docs/snapshot/source/customers_list.sql';
    private const OBSOLETE = ['Always Late', 'sometime Paid', 'Never Paid', '8 Autre', '10 Other'];

    private function sql(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::SQL_FILE));
    }

    private function rule(): string
    {
        $this->assertSame(1, preg_match('/CASE\s+WHEN c\.bill_number = 0.*?END AS profile/s', $this->sql(), $m));

        return substr($m[0], 0, -strlen(' AS profile'));
    }

    /** @return array<string, array{int, float|null, string|null}> */
    public static function ruleCases(): array
    {
        return [
            'bill_number 0'  => [0, 1.0, null],
            'rate 1'         => [1, 1.0, '1 PERFECT'],
            'rate 0.9999'    => [1, 0.9999, '2 RELIABLE'],
            'rate 0.75'      => [1, 0.75, '2 RELIABLE'],
            'rate 0.7499'    => [1, 0.7499, '3 Occasionnal'],
            'rate 0.50'      => [1, 0.50, '3 Occasionnal'],
            'rate 0.4999'    => [1, 0.4999, '4 At-Risk'],
            'rate 0.25'      => [1, 0.25, '4 At-Risk'],
            'rate 0.2499'    => [1, 0.2499, '5 Delinquant'],
            'rate 0.0001'    => [1, 0.0001, '5 Delinquant'],
            'rate 0'         => [1, 0.0, '6 Défaillant'],
            'rate -0.1'      => [1, -0.1, '6 Défaillant'],
            'rate 1.1'       => [1, 1.1, '6 Défaillant'],
            'rate NULL'      => [1, null, '6 Défaillant'],
        ];
    }

    /** @dataProvider ruleCases */
    public function testTheRuleOnEveryBoundary(int $billNumber, ?float $rate, ?string $expected): void
    {
        if (! class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension absent.');
        }
        $db   = new SQLite3(':memory:');
        $stmt = $db->prepare('SELECT ' . $this->rule() . ' AS profile FROM (SELECT :b AS bill_number, :r AS rate) c');
        $stmt->bindValue(':b', $billNumber, SQLITE3_INTEGER);
        $stmt->bindValue(':r', $rate, $rate === null ? SQLITE3_NULL : SQLITE3_FLOAT);

        $this->assertSame($expected, $stmt->execute()->fetchArray(SQLITE3_ASSOC)['profile']);
        $db->close();
    }

    /** @return array<string, array{int, float|null, int, int}> */
    public static function formerSubCases(): array
    {
        return [
            'Always Late'   => [4, 0.0, 4, 0],
            'Never Paid'    => [4, 0.0, 0, 4],
            'sometime Paid' => [4, 0.0, 1, 2],
            'Other'         => [4, 0.0, 0, 5],
            'Other, no rate' => [4, null, 0, 0],
        ];
    }

    /**
     * bad_count and no_paid_count no longer split the profile: whatever the
     * situation, a billed customer with no rate above 0 is one profile.
     *
     * @dataProvider formerSubCases
     */
    public function testFormerSubCasesAllBecomeDefaillant(int $billNumber, ?float $rate, int $badCount, int $noPaidCount): void
    {
        if (! class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension absent.');
        }
        $db   = new SQLite3(':memory:');
        $stmt = $db->prepare('SELECT ' . $this->rule() . ' AS profile FROM (SELECT :b AS bill_number, :r AS rate, :bad AS bad_count, :np AS no_paid_count) c');
        $stmt->bindValue(':b', $billNumber, SQLITE3_INTEGER);
        $stmt->bindValue(':r', $rate, $rate === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':bad', $badCount, SQLITE3_INTEGER);
        $stmt->bindValue(':np', $noPaidCount, SQLITE3_INTEGER);

        $this->assertSame('6 Défaillant', $stmt->execute()->fetchArray(SQLITE3_ASSOC)['profile']);
        $db->close();
    }

    public function testTheRuleReadsOnlyBillNumberAndRate(): void
    {
        preg_match_all('/c\.(\w+)/', $this->rule(), $m);
        $this->assertSame(['bill_number', 'rate'], array_values(array_unique($m[1])));
    }

    public function testOneRuleForBothPopulationsAndItsLabelsAreTheApplicationList(): void
    {
        $sql = $this->sql();
        $this->assertSame(1, substr_count($sql, 'profile_calculation AS ('));
        $this->assertSame(1, substr_count($sql, 'LEFT JOIN profile_calculation pp'));
        $this->assertSame(1, substr_count($sql, 'THEN pp.PROFILE'), 'one branch feeds POSTPAID and Compteurs Communicants');

        preg_match_all("/(?:THEN|ELSE) '([^']+)'/", $this->rule(), $m);
        $this->assertSame(PostpaidSegmentations::VALUES, $m[1]);
    }

    public function testNoObsoleteProfileAndNoCarryOverOfAnOlderProfile(): void
    {
        $sql = $this->sql();
        foreach (self::OBSOLETE as $label) {
            $this->assertStringNotContainsString($label, $sql);
        }
        // Skipping rows without a profile would hand a bill_number = 0
        // customer the profile of an older month.
        $this->assertStringNotContainsString('WHERE PROFILE IS NOT NULL', $sql);
    }

    public function testPrepaidKeepsItsOwnBranches(): void
    {
        $sql = preg_replace('/\s+/', ' ', $this->sql());
        $this->assertStringContainsString('WHEN p.METER_PREP IS NOT NULL AND rfm.METER IS NOT NULL THEN rfm.SEGMENT_RFM_2', $sql);
        $this->assertStringContainsString("AND om.METER_PREP IS NOT NULL THEN '5-Dormant'", $sql);
        $this->assertStringContainsString("AND om.METER_PREP IS NULL THEN '6-Never Vending'", $sql);
        $this->assertSame(
            ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6-Never Vending'],
            PrepaidSegmentations::values(),
        );
    }

    public function testTheDashboardMirrorsTheList(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/dashboard.js');
        $this->assertSame(1, preg_match('/var POSTPAID_SEGMENTATIONS = \[(.*?)\];/s', $js, $m));
        preg_match_all("/'([^']+)'/", $m[1], $values);
        $this->assertSame(PostpaidSegmentations::VALUES, $values[1]);

        foreach (self::OBSOLETE as $label) {
            $this->assertStringNotContainsString("'" . $label, $js);
        }
    }

    public function testMissingProfilesAreOfferedAtZero(): void
    {
        $out = PostpaidSegmentations::completeOptions(['segmentations' => [['value' => '4 At-Risk', 'count' => 7]]]);

        $this->assertContains(['value' => '4 At-Risk', 'count' => 7], $out['segmentations']);
        $this->assertContains(['value' => '6 Défaillant', 'count' => 0], $out['segmentations']);
        $this->assertCount(6, $out['segmentations']);
    }

    public function testFiltersChartsAndExportsAgreeAndUnclassifiedStaysApart(): void
    {
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        // Profile => [POSTPAID rows, Compteurs Communicants rows].
        $expected = ['1 PERFECT' => [1, 1], '2 RELIABLE' => [2, 1], '3 Occasionnal' => [3, 1], '4 At-Risk' => [1, 2], '5 Delinquant' => [2, 2], '6 Défaillant' => [4, 3], '' => [2, 1]];
        $rows     = [];
        foreach ($expected as $profile => [$postpaid, $smart]) {
            foreach (['POSTPAID' => $postpaid, 'Compteurs Communicants' => $smart] as $meter => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => $meter, 'SEGMENTATION' => (string) $profile]);
                }
            }
        }
        foreach (['1-Stable', '3-At Risk', '3-At Risk'] as $segment) {
            $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => 'PREPAID', 'SEGMENTATION' => $segment]);
        }

        $fx = new SnapshotFixture();

        try {
            $result = $fx->install($rows);
            $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $result['result'], (string) $result['message']);

            $snapshot = $fx->store->active();
            $duck     = (new SnapshotIndex($fx->config))->engine($snapshot);
            $scan     = new SnapshotScanEngine($snapshot);
            $export   = new SnapshotRowSource(snapshot: $snapshot, store: $fx->store);

            $scope = FilterCriteria::fromArray(['meters' => ['POSTPAID', 'Compteurs Communicants']]);
            $chart = $duck->segmentationCounts($scope);

            $classified = 0;
            foreach (PostpaidSegmentations::VALUES as $profile) {
                $n        = array_sum($expected[$profile]);
                $filtered = FilterCriteria::fromArray(['meters' => ['POSTPAID', 'Compteurs Communicants'], 'segmentations' => [$profile]]);

                $this->assertSame($n, $chart[$profile], $profile);
                $this->assertSame($n, $duck->count($filtered), $profile);
                $this->assertSame($n, $scan->count($filtered), $profile);
                $this->assertSame($n, $export->count($filtered), $profile);
                $classified += $n;
            }

            $unclassified = array_sum($expected['']);
            $this->assertSame($unclassified, $chart[''] ?? 0);
            $this->assertSame($classified + $unclassified, array_sum($chart));
            $this->assertSame($classified + $unclassified, $export->count($scope));

            // Both populations carry the same labels, and PREPAID keeps its own.
            $this->assertSame(['1-Stable' => 1, '3-At Risk' => 2], $this->sorted($duck->segmentationCounts(FilterCriteria::fromArray(['meters' => ['PREPAID']]))));
            $this->assertSame(array_keys($this->sorted($duck->segmentationCounts(FilterCriteria::fromArray(['meters' => ['POSTPAID']])))), array_keys($this->sorted($duck->segmentationCounts(FilterCriteria::fromArray(['meters' => ['Compteurs Communicants']])))));
        } finally {
            $fx->cleanup();
        }
    }

    public function testAFormerSnapshotShowsOnlyTheSixProfilesEverywhere(): void
    {
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $values = ['1 PERFECT', '6 Always Late', '7 sometime Paid', '8 Never Paid', '10 Other', '8 Autre'];
        $rows   = [];
        foreach ($values as $value) {
            $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => 'POSTPAID', 'SEGMENTATION' => $value]);
        }
        $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => 'PREPAID', 'SEGMENTATION' => 'Other']);
        $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => 'PREPAID', 'SEGMENTATION' => '6 Old_Dormant']);

        $fx = new SnapshotFixture();

        try {
            $result = $fx->install($rows);
            $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $result['result'], (string) $result['message']);

            $snapshot = $fx->store->active();
            $duck     = (new SnapshotIndex($fx->config))->engine($snapshot);
            $scan     = new SnapshotScanEngine($snapshot);
            $export   = new SnapshotRowSource(snapshot: $snapshot, store: $fx->store);
            $expected = ['' => 1, '1 PERFECT' => 1, '6 Défaillant' => 4];
            $postpaid = FilterCriteria::fromArray(['meters' => ['POSTPAID']]);
            $failing  = FilterCriteria::fromArray(['segmentations' => ['6 Défaillant']]);

            $this->assertSame($expected, $this->sorted($duck->segmentationCounts($postpaid)));
            $this->assertSame($expected, $this->sorted($scan->segmentationCounts($postpaid)));
            $this->assertSame($expected, $this->sorted($duck->distributions($postpaid)['segmentation']));

            foreach ([$duck->count($failing), $scan->count($failing), $export->count($failing)] as $count) {
                $this->assertSame(4, $count);
            }

            $exported = [];
            $export->stream($postpaid, static function (array $row) use (&$exported): void {
                $exported[] = $row['SEGMENTATION'];
            });
            sort($exported);
            $this->assertSame(['', '1 PERFECT', '6 Défaillant', '6 Défaillant', '6 Défaillant', '6 Défaillant'], $exported);

            foreach ([$duck->filterOptions(), $scan->filterOptions(), $snapshot->filterOptions()] as $options) {
                $offered = array_column($options['segmentations'], 'count', 'value');
                ksort($offered);
                $this->assertSame(['1 PERFECT' => 1, '5-Dormant' => 1, '6 Défaillant' => 4], $offered);
            }

            $this->assertSame(['' => 1, '5-Dormant' => 1], $this->sorted($duck->segmentationCounts(FilterCriteria::fromArray(['meters' => ['PREPAID']]))));
            $this->assertSame(count($rows), $duck->count(FilterCriteria::none()), 'no customer lost or counted twice');
        } finally {
            $fx->cleanup();
        }
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
