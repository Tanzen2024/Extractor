<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\PostpaidSegmentations;
use App\Services\CustomersList\PrepaidSegmentations;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotScanEngine;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * The 6 official PREPAID segments: the exact SQL rule (read from
 * customers_list.sql, not a copy) on every boundary, the filter validation,
 * then the former values through filters, charts and exports.
 *
 * @internal
 */
final class PrepaidSegmentationsTest extends TestCase
{
    private const ALL      = ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6-Never Vending'];
    private const SQL_FILE = __DIR__ . '/../../docs/snapshot/source/customers_list.sql';

    private function sql(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::SQL_FILE));
    }

    /** filter-options of a snapshot holding 2 of the 6 PREPAID segments. */
    private function options(): array
    {
        return [
            'segmentations' => [
                ['value' => '5-Dormant', 'count' => 1091001], ['value' => '6 Défaillant', 'count' => 817910],
                ['value' => '1 PERFECT', 'count' => 30043], ['value' => '6-Never Vending', 'count' => 9888],
            ],
            'meters' => [['value' => 'PREPAID', 'count' => 2], ['value' => 'POSTPAID', 'count' => 2]],
        ];
    }

    /** @return array<string, array{float|null, string, string|null}> */
    public static function ruleCases(): array
    {
        return [
            'no purchase'           => [null, '7 Never Vending', '6-Never Vending'],
            'no purchase, any seg'  => [null, '1-Stable', '6-Never Vending'],
            'stable today'          => [0.0, '1-Stable', '1-Stable'],
            'stable just under 30'  => [29.99, '1-Stable', '1-Stable'],
            'instable under 30'     => [12.0, '2-InStable', '2-InStable'],
            'at risk under 30'      => [29.0, '3-At Risk', '3-At Risk'],
            'exactly 30'            => [30.0, '1-Stable', '4-Suspect Dormant'],
            'just under 60'         => [59.99, '3-At Risk', '4-Suspect Dormant'],
            'exactly 60'            => [60.0, '2-InStable', '5-Dormant'],
            'at risk 698 days'      => [698.0, '3-At Risk', '5-Dormant'],
            'old dormant 699 days'  => [699.02, '6 Old_Dormant', '5-Dormant'],
            'unknown value, recent' => [5.0, 'Something else', null],
        ];
    }

    /** @dataProvider ruleCases */
    public function testTheRuleOnEveryBoundary(?float $recense, string $segment, ?string $expected): void
    {
        if (! class_exists(SQLite3::class)) {
            $this->markTestSkipped('sqlite3 extension absent.');
        }
        $this->assertSame(1, preg_match('/CASE\s+WHEN r\.RECENSE IS NULL.*?END AS SEGMENT_RFM_2/s', $this->sql(), $m));

        $db   = new SQLite3(':memory:');
        $stmt = $db->prepare('SELECT ' . $m[0] . ' FROM (SELECT :r AS RECENSE, :s AS SEGMENT_RFM_2) r');
        $stmt->bindValue(':r', $recense, $recense === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':s', $segment, SQLITE3_TEXT);

        $this->assertSame($expected, $stmt->execute()->fetchArray(SQLITE3_ASSOC)['SEGMENT_RFM_2']);
        $db->close();
    }

    public function testTheSqlProducesOnlyTheOfficialSegments(): void
    {
        $sql = $this->sql();
        foreach (['6 Old_Dormant', '7 Never Vending'] as $former) {
            $this->assertStringNotContainsString($former, $sql);
        }
        $this->assertSame(1, substr_count($sql, 'prepaid_rfm AS ('));
        $this->assertSame(1, substr_count($sql, 'LEFT JOIN prepaid_rfm rfm'));
    }

    public function testOneReferenceDateAndRecenseIsRecalculatedFromIt(): void
    {
        $sql = preg_replace('/\s+/', ' ', $this->sql());

        $this->assertSame(1, substr_count($sql, 'TRUNC(MAX(LASTPURCHASE_DATE)) AS REF_DATE'));
        $this->assertStringContainsString('ref.REF_DATE - TRUNC(l.LASTPURCHASE_DATE) AS RECENSE', $sql);
        $this->assertStringNotContainsString('SYSDATE - ', $sql);
        $this->assertStringContainsString('SELECT TRUNC(REF_DATE, \'MM\') FROM prepaid_reference', $sql);
    }

    public function testExclusivePriorityOfTheCustomersWithoutProfile(): void
    {
        $sql    = preg_replace('/\s+/', ' ', $this->sql());
        $buyer  = strpos($sql, "AND nb.METER_PREP IS NOT NULL THEN '1-Stable'");
        $dormant = strpos($sql, "AND om.METER_PREP IS NOT NULL THEN '5-Dormant'");
        $never  = strpos($sql, "AND om.METER_PREP IS NULL THEN '6-Never Vending'");

        $this->assertNotFalse($buyer);
        $this->assertTrue($buyer < $dormant && $dormant < $never, 'first matching branch wins');
        $this->assertStringContainsString("o.op_time >= ref.REF_DATE + 1 AND o.order_type = '01' AND o.status = '01'", $sql);
        $this->assertStringContainsString('b.op_time < ref.REF_DATE + 1', $sql);
    }

    public function testTheSixSegmentsInBusinessOrderWithoutDuplicate(): void
    {
        $this->assertSame(self::ALL, PrepaidSegmentations::values());
        $this->assertSame(self::ALL, array_values(array_unique(PrepaidSegmentations::values())));
    }

    public function testCompleteOptionsAddsOnlyTheMissingSegmentsAtZero(): void
    {
        $out    = PrepaidSegmentations::completeOptions($this->options());
        $counts = array_column($out['segmentations'], 'count', 'value');

        foreach (self::ALL as $value) {
            $this->assertArrayHasKey($value, $counts, $value);
        }
        $this->assertSame(0, $counts['1-Stable']);
        $this->assertSame(0, $counts['4-Suspect Dormant']);
        $this->assertSame(1091001, $counts['5-Dormant']);
        $this->assertCount(8, $out['segmentations']); // 4 existing + 4 added, no duplicate
        $this->assertSame($this->options()['meters'], $out['meters']);
    }

    public function testASegmentAbsentFromTheDataIsAccepted(): void
    {
        $c = FilterCriteria::fromRequest(['segmentation' => ['1-Stable']], AllowedValues::fromFilterOptions($this->options()));

        $this->assertSame(['1-Stable'], $c->segmentations);
    }

    public function testPartialSelectionFiltersOnTheExactSqlValues(): void
    {
        $c = FilterCriteria::fromRequest(
            ['meter' => ['PREPAID'], 'segmentation' => ['5-Dormant', '6-Never Vending']],
            AllowedValues::fromFilterOptions($this->options()),
        );

        $this->assertSame(['5-Dormant', '6-Never Vending'], $c->segmentations);
    }

    public function testAllSixUnderPrepaidEqualsNoSelection(): void
    {
        $allowed = AllowedValues::fromFilterOptions($this->options());
        $all     = FilterCriteria::fromRequest(['meter' => ['PREPAID'], 'segmentation' => array_reverse(self::ALL)], $allowed);
        $none    = FilterCriteria::fromRequest(['meter' => ['PREPAID']], $allowed);

        $this->assertSame([], $all->segmentations);
        $this->assertSame($none->toArray(), $all->toArray());
    }

    public function testAllSixWithoutAPrepaidOnlyScopeStillRestricts(): void
    {
        // Type compteur = Toutes: the 6 PREPAID values exclude the POSTPAID rows.
        $allowed = AllowedValues::fromFilterOptions($this->options());
        $c       = FilterCriteria::fromRequest(['segmentation' => self::ALL], $allowed);

        $this->assertSame(self::ALL, $c->segmentations);
    }

    public function testFormerValuesFoldIntoTheSixInTheFilterOptions(): void
    {
        $out = PostpaidSegmentations::foldOptions(['segmentations' => [
            ['value' => '5-Dormant', 'count' => 10], ['value' => '6 Old_Dormant', 'count' => 5],
            ['value' => '7 Never Vending', 'count' => 3], ['value' => 'Other', 'count' => 2],
            ['value' => '1 PERFECT', 'count' => 7],
        ]]);

        $this->assertSame(
            ['5-Dormant' => 15, '6-Never Vending' => 3, '1 PERFECT' => 7],
            array_column($out['segmentations'], 'count', 'value'),
        );
    }

    public function testAFormerSnapshotReadsTheSameEverywhereAndLeavesPostpaidAlone(): void
    {
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $rows = [];
        foreach ([['1-Stable', '1-Stable'], ['2-InStable', '2-InStable'], ['3-At Risk', '3-At Risk'], ['6 Old_Dormant', '6 Old_Dormant'], ['6 Old_Dormant', ''], ['7 Never Vending', '7 Never Vending'], ['7 Never Vending', '']] as [$segment, $rfm]) {
            $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => 'PREPAID', 'SEGMENTATION' => $segment, 'SEGMENT_RFM_2' => $rfm]);
        }
        foreach (['POSTPAID', 'Compteurs Communicants'] as $meter) {
            foreach (['1 PERFECT', '6 Défaillant'] as $profile) {
                $rows[] = SnapshotFixture::row(count($rows) + 1, ['METER' => $meter, 'SEGMENTATION' => $profile, 'SEGMENT_RFM_2' => '']);
            }
        }

        $fx = new SnapshotFixture();

        try {
            $result = $fx->install($rows);
            $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $result['result'], (string) $result['message']);

            $snapshot = $fx->store->active();
            $duck     = (new SnapshotIndex($fx->config))->engine($snapshot);
            $scan     = new SnapshotScanEngine($snapshot);
            $export   = new SnapshotRowSource(snapshot: $snapshot, store: $fx->store);
            $prepaid  = FilterCriteria::fromArray(['meters' => ['PREPAID']]);
            $expected = ['1-Stable' => 1, '2-InStable' => 1, '3-At Risk' => 1, '5-Dormant' => 2, '6-Never Vending' => 2];

            foreach ([$duck->segmentationCounts($prepaid), $scan->segmentationCounts($prepaid), $duck->distributions($prepaid)['segmentation']] as $counts) {
                ksort($counts);
                $this->assertSame($expected, $counts);
            }
            $this->assertSame(7, array_sum($expected));
            $this->assertSame(7, $export->count($prepaid));

            foreach ($expected as $segment => $n) {
                $filtered = FilterCriteria::fromArray(['meters' => ['PREPAID'], 'segmentations' => [$segment]]);
                $this->assertSame($n, $duck->count($filtered), $segment);
                $this->assertSame($n, $scan->count($filtered), $segment);
                $this->assertSame($n, $export->count($filtered), $segment);
            }

            $exported = [];
            $export->stream($prepaid, static function (array $row) use (&$exported): void {
                $exported[] = $row['SEGMENTATION'] . '|' . $row['SEGMENT_RFM_2'];
            });
            sort($exported);
            $this->assertSame([
                '1-Stable|1-Stable', '2-InStable|2-InStable', '3-At Risk|3-At Risk', '5-Dormant|', '5-Dormant|5-Dormant',
                '6-Never Vending|', '6-Never Vending|6-Never Vending',
            ], $exported);

            foreach (['POSTPAID', 'Compteurs Communicants'] as $meter) {
                $counts = $duck->segmentationCounts(FilterCriteria::fromArray(['meters' => [$meter]]));
                ksort($counts);
                $this->assertSame(['1 PERFECT' => 1, '6 Défaillant' => 1], $counts, $meter);
            }
        } finally {
            $fx->cleanup();
        }
    }
}
