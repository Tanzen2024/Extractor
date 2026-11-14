<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\BillingValue;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotScanEngine;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * Amount billed over 12 months + Value through the whole Extractor chain:
 * CSV snapshot -> DuckDB index -> table / filters / sort, and the CSV export,
 * with the PHP reference engine (SnapshotScanEngine) and the export count as
 * oracles. Also: a snapshot without the columns (every version installed
 * before them) keeps working, and the PREPAID / POSTPAID segmentations are
 * untouched by the new columns.
 *
 * @internal
 */
final class SnapshotBillingValueTest extends CIUnitTestCase
{
    private const HEADER = [...SnapshotFixture::HEADER, BillingValue::AMOUNT_COLUMN, BillingValue::SEGMENT_COLUMN];

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
        $values   = [BillingValue::TOP, BillingValue::MEDIUM, BillingValue::BOTTOM, ''];
        $rows     = [];
        for ($n = 1; $n <= 96; $n++) {
            $meter = ['POSTPAID', 'PREPAID', 'Compteurs Communicants', 'POSTPAID'][$n % 4];
            $rows[] = SnapshotFixture::row($n, [
                'REGION'        => ['DRC', 'DRE', 'DRO'][$n % 3],
                'STATUS'        => $statuses[intdiv($n, 4) % 4],
                'METER'         => $meter,
                'SEGMENTATION'  => $meter === 'PREPAID' ? '1-Stable' : ['1 PERFECT', '6 Défaillant'][$n % 2],
                'DATE_AB'       => $n % 2 === 0 ? '2020-01-15' : '2024-06-30',
                'PHONE_NUMBERS' => $n % 5 === 0 ? '' : '690000000',
                // Amounts whose text order differs from their numeric order (9 < 100 < 1 000 000), a negative net, zeros.
                BillingValue::AMOUNT_COLUMN  => $meter === 'PREPAID' ? '0' : (string) [9, 100, 1_000_000, 0, -50, 25_000][$n % 6],
                BillingValue::SEGMENT_COLUMN => $meter === 'PREPAID' ? '' : $values[intdiv($n, 3) % 4],
            ]);
        }

        return $rows;
    }

    private function install(bool $withColumns = true): void
    {
        $rows = self::rows();
        if (! $withColumns) {
            $rows = array_map(static function (array $r): array {
                unset($r[BillingValue::AMOUNT_COLUMN], $r[BillingValue::SEGMENT_COLUMN]);

                return $r;
            }, $rows);
        }
        $r = $this->fx->install($rows, $withColumns ? self::HEADER : null);
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
    }

    /** @return array<string, FilterCriteria> */
    private static function criteriaSets(): array
    {
        return [
            'Top'                       => FilterCriteria::fromArray(['values' => [BillingValue::TOP]]),
            'Top + Bottom'              => FilterCriteria::fromArray(['values' => [BillingValue::TOP, BillingValue::BOTTOM]]),
            'Medium + région'           => FilterCriteria::fromArray(['values' => [BillingValue::MEDIUM], 'regions' => ['DRE']]),
            'Top + Postpaid + statut'   => FilterCriteria::fromArray(['values' => [BillingValue::TOP], 'meters' => ['POSTPAID'], 'statuses' => ['ACTIVE']]),
            'Bottom + date + contact'   => FilterCriteria::fromArray(['values' => [BillingValue::BOTTOM], 'dateFrom' => '2024-01-01', 'hasContact' => true]),
            'Top + segmentation'        => FilterCriteria::fromArray(['values' => [BillingValue::TOP], 'segmentations' => ['6 Défaillant']]),
            'Top + Prepaid (aucun)'     => FilterCriteria::fromArray(['values' => [BillingValue::TOP], 'meters' => ['PREPAID']]),
            'aucun filtre'              => FilterCriteria::none(),
        ];
    }

    public function testTheValueFilterCombinesWithEveryOtherFilterIdenticallyInBothEnginesAndTheExport(): void
    {
        $this->install();
        $snapshot  = $this->fx->store->active();
        $duck      = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $scan      = new SnapshotScanEngine($snapshot);
        $rowSource = new SnapshotRowSource(snapshot: $snapshot, store: $this->fx->store);

        foreach (self::criteriaSets() as $name => $c) {
            $count = $scan->count($c);
            $this->assertSame($count, $duck->count($c), "count — {$name}");
            $this->assertSame($count, $rowSource->count($c), "export count — {$name}");
            $this->assertSame($scan->page($c, '', null, 'asc', 0, 100), $duck->page($c, '', null, 'asc', 0, 100), "page — {$name}");

            // Every row of a Value selection has one of the selected values; the count is the hand count.
            $expected = count(array_filter(self::rows(), static fn (array $r): bool => self::inScope($r, $c)));
            $this->assertSame($expected, $count, "hand count — {$name}");
            foreach ($duck->page($c, '', null, 'asc', 0, 100) as $row) {
                if ($c->values !== []) {
                    $this->assertContains($row[BillingValue::SEGMENT_COLUMN], $c->values, $name);
                }
            }
        }
        $this->assertSame(0, $duck->count(self::criteriaSets()['Top + Prepaid (aucun)']), 'PREPAID is never ranked');
    }

    /** Hand rule of the fixture, independent of both engines. */
    private static function inScope(array $r, FilterCriteria $c): bool
    {
        if (! in_array($r['STATUS'], $c->effectiveStatuses(), true)) {
            return false;
        }
        foreach (['regions' => 'REGION', 'meters' => 'METER', 'segmentations' => 'SEGMENTATION'] as $p => $col) {
            if ($c->{$p} !== [] && ! in_array($r[$col], $c->{$p}, true)) {
                return false;
            }
        }
        if ($c->values !== [] && ! in_array($r[BillingValue::SEGMENT_COLUMN], $c->values, true)) {
            return false;
        }
        if ($c->dateFrom !== null && $r['DATE_AB'] < $c->dateFrom->format('Y-m-d')) {
            return false;
        }
        if ($c->hasContact !== null && (trim($r['PHONE_NUMBERS']) !== '' || trim($r['E_MAIL']) !== '') !== $c->hasContact) {
            return false;
        }

        return true;
    }

    public function testTheAmountSortsNumericallyInBothEngines(): void
    {
        $this->install();
        $snapshot = $this->fx->store->active();
        $duck     = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $scan     = new SnapshotScanEngine($snapshot);
        $c        = FilterCriteria::fromArray(['meters' => ['POSTPAID', 'Compteurs Communicants']]);

        foreach (['asc', 'desc'] as $dir) {
            foreach ([[0, 10], [7, 9], [20, 50]] as [$offset, $limit]) {
                $this->assertSame($scan->page($c, '', BillingValue::AMOUNT_COLUMN, $dir, $offset, $limit), $duck->page($c, '', BillingValue::AMOUNT_COLUMN, $dir, $offset, $limit), "{$dir} @{$offset}");
            }
            $amounts = array_map(static fn (array $r): int => (int) $r[BillingValue::AMOUNT_COLUMN], $duck->page($c, '', BillingValue::AMOUNT_COLUMN, $dir, 0, 1000));
            $sorted  = $amounts;
            $dir === 'asc' ? sort($sorted) : rsort($sorted);
            $this->assertSame($sorted, $amounts, "numeric {$dir}: -50 < 0 < 9 < 100 < 25 000 < 1 000 000, never text order");
        }
    }

    public function testFilterOptionsOfferTheThreeCategoriesInBusinessOrderWithTheirCounts(): void
    {
        $this->install();
        $snapshot = $this->fx->store->active();
        $duck     = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $scan     = new SnapshotScanEngine($snapshot);

        $options = $duck->filterOptions();
        $this->assertSame($scan->filterOptions(), $options, 'same payload from both engines');
        $this->assertSame($snapshot->filterOptions()['values'], $options['values'], 'install-time options (meta) = live options');
        $this->assertSame(BillingValue::VALUES, array_column($options['values'], 'value'));
        foreach ($options['values'] as $o) {
            $this->assertSame($duck->count(FilterCriteria::fromArray(['values' => [$o['value']]])), $o['count'], 'ticking a category gives the number shown next to it');
        }
    }

    public function testTheExportCarriesBothColumnsAfterTheUsualOnes(): void
    {
        $this->install();
        $snapshot = $this->fx->store->active();
        $out      = [];
        (new SnapshotRowSource(snapshot: $snapshot, store: $this->fx->store))->stream(FilterCriteria::fromArray(['values' => [BillingValue::TOP]]), static function (array $row) use (&$out): void {
            $out[] = $row;
        });

        $this->assertNotEmpty($out);
        $this->assertSame(CustomerListExportService::EXPORT_COLUMNS, array_keys($out[0]));
        foreach ($out as $row) {
            $this->assertSame(BillingValue::TOP, $row[BillingValue::SEGMENT_COLUMN]);
            $this->assertMatchesRegularExpression('/^-?\d+$/', $row[BillingValue::AMOUNT_COLUMN], 'raw whole FCFA in the file (no separator, no unit)');
        }
    }

    public function testUpdatedAtIsTheLastColumnOfTheTableAndOfTheExport(): void
    {
        $all = \App\Services\CustomersList\QueryBuilder::ALL_COLUMNS;
        $this->assertSame('UPDATED_AT', end($all));
        $export = CustomerListExportService::EXPORT_COLUMNS;
        $this->assertSame('UPDATED_AT', end($export));
        $expected = [...CustomerListExportService::COLUMNS, ...BillingValue::COLUMNS];
        sort($expected);
        sort($export);
        $this->assertSame($expected, $export, 'same columns, only the order changes');

        // Fast path of a 25-column file (no optional columns): UPDATED_AT value moved, not lost.
        $this->install(false);
        $out = [];
        (new SnapshotRowSource(snapshot: $this->fx->store->active(), store: $this->fx->store))->stream(FilterCriteria::none(), static function (array $row) use (&$out): void {
            $out[] = $row;
        });
        $this->assertSame(CustomerListExportService::EXPORT_COLUMNS, array_keys($out[0]));
        $this->assertSame('2026-09-25 06:00:00', $out[0]['UPDATED_AT']);
        $this->assertSame('', $out[0][BillingValue::AMOUNT_COLUMN]);
        $this->assertNotSame('', $out[0]['SEGMENTATION']);
    }

    public function testASnapshotWithoutTheColumnsKeepsWorking(): void
    {
        $this->install(false);
        $snapshot = $this->fx->store->active();
        $duck     = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $scan     = new SnapshotScanEngine($snapshot);
        $rows     = new SnapshotRowSource(snapshot: $snapshot, store: $this->fx->store);
        $top      = FilterCriteria::fromArray(['values' => [BillingValue::TOP]]);

        $this->assertSame([], $duck->filterOptions()['values'], 'no option: the filter is not shown');
        $this->assertSame(0, $duck->count($top), 'a Value filter sent anyway matches no row — never every row');
        $this->assertSame(0, $scan->count($top));
        $this->assertSame(0, $rows->count($top));

        $page = $duck->page(FilterCriteria::none(), '', BillingValue::AMOUNT_COLUMN, 'desc', 0, 5);
        $this->assertSame($scan->page(FilterCriteria::none(), '', BillingValue::AMOUNT_COLUMN, 'desc', 0, 5), $page, 'sort falls back to CONTRACT in both');
        $this->assertSame('', $page[0][BillingValue::AMOUNT_COLUMN]);
        $this->assertSame('', $page[0][BillingValue::SEGMENT_COLUMN]);

        $out = [];
        $rows->stream(FilterCriteria::none(), static function (array $row) use (&$out): void {
            $out[] = $row;
        });
        $this->assertSame(CustomerListExportService::EXPORT_COLUMNS, array_keys($out[0]), 'same file layout, columns empty');
        $this->assertSame('', $out[0][BillingValue::AMOUNT_COLUMN]);
    }

    public function testThePrepaidAndPostpaidSegmentationsAreUntouched(): void
    {
        $this->install(false);
        $before = (new SnapshotIndex($this->fx->config))->engine($this->fx->store->active())->distributions(FilterCriteria::none());

        $fx2      = new SnapshotFixture();
        $this->fx->cleanup();
        $this->fx = $fx2;
        $this->install(true);
        $after = (new SnapshotIndex($this->fx->config))->engine($this->fx->store->active())->distributions(FilterCriteria::none());

        foreach (['segmentation', 'failure', 'meterType', 'status', 'region'] as $dim) {
            ksort($before[$dim]);
            ksort($after[$dim]);
            $this->assertSame($before[$dim], $after[$dim], "{$dim}: same counts with and without the new columns");
        }
    }

    public function testTheRequestFilterIsValidatedAndNormalised(): void
    {
        $allowed = new AllowedValues([], [], [], [], [], [], [], []);

        $c = FilterCriteria::fromRequest(['value' => [BillingValue::BOTTOM, BillingValue::TOP, BillingValue::TOP]], $allowed);
        $this->assertSame([BillingValue::TOP, BillingValue::BOTTOM], $c->values, 'deduplicated, business order');
        $this->assertSame($c->values, FilterCriteria::fromArray($c->toArray())->values, 'export jobs replay it');
        $this->assertSame($c->values, $c->withoutSegmentations()->values);
        $this->assertNotSame(FilterCriteria::none()->cacheKey(), $c->cacheKey());
        $this->assertSame('Top 20%, Bottom 30%', $c->describe()[lang('Glossary.value')]);

        $this->assertSame([], FilterCriteria::fromRequest(['value' => BillingValue::VALUES], $allowed)->values, 'all three ticked = no restriction, like every other list');

        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromRequest(['value' => ['Top 10%']], $allowed);
    }
}
