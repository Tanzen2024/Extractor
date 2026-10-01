<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Snapshot\RowMatcher;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotReader;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotUnavailableException;
use CodeIgniter\Test\Mock\MockCache;
use Config\Oracle as OracleConfig;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * User exports read from the snapshot: CSV/XLSX content, filters (the PHP
 * twin of QueryBuilder::where()), concurrency with a new snapshot arriving,
 * and proof that Oracle is never involved.
 *
 * @internal
 */
final class SnapshotExportTest extends TestCase
{
    private SnapshotFixture $fx;
    private string $exportDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->fx        = new SnapshotFixture();
        $this->exportDir = $this->fx->baseDir . DIRECTORY_SEPARATOR . 'exports';
        $this->tmpDir    = $this->fx->baseDir . DIRECTORY_SEPARATOR . 'openspout';
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    /**
     * Oracle deliberately left unconfigured: any Oracle call would throw
     * "Oracle connection is not configured".
     */
    private function unconfiguredOracle(): OracleConfig
    {
        $config           = new OracleConfig();
        $config->dsn      = '';
        $config->username = '';
        $config->password = '';

        return $config;
    }

    /** Service resolving its source from config only — the production path. */
    private function service(): CustomerListExportService
    {
        return new CustomerListExportService(
            config: $this->unconfiguredOracle(),
            exportDir: $this->exportDir,
            openSpoutTempDir: $this->tmpDir,
            snapshotConfig: $this->fx->config,
        );
    }

    /** @return list<array<string, string>> */
    private function sampleRows(): array
    {
        return [
            SnapshotFixture::row(1, ['REGION' => 'DRC', 'STATUS' => 'ACTIVE', 'METER' => 'PREPAID', 'DATE_AB' => '2020-01-01', 'SEGMENTATION' => '1 PERFECT']),
            SnapshotFixture::row(2, ['REGION' => 'DRC', 'STATUS' => 'SUSPENDED', 'METER' => 'POSTPAID', 'DATE_AB' => '2020-01-31', 'SEGMENTATION' => '8 Autre']),
            SnapshotFixture::row(3, ['REGION' => 'DRE', 'STATUS' => 'ACTIVE', 'METER' => 'Compteurs Communicants', 'DATE_AB' => '2020-02-01', 'NUI_QC' => 'NUI a RECLASSER']),
            SnapshotFixture::row(4, ['REGION' => 'DRE', 'STATUS' => 'ACTIVE (PENDING BILLING)', 'METER' => 'PREPAID', 'DATE_AB' => '2019-12-31', 'VOLTAGE' => 'MV']),
            SnapshotFixture::row(5, ['REGION' => 'DRO', 'STATUS' => 'ACTIVE', 'METER' => ' ', 'DATE_AB' => 'n/a', 'SEGMENT_TRESOR' => 'PUBLIC']),
        ];
    }

    private function installSample(): void
    {
        $r = $this->fx->install($this->sampleRows());
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $h = fopen($path, 'rb');
        $this->assertSame("\xEF\xBB\xBF", fread($h, 3));
        $lines = [];
        while (($line = fgetcsv($h, null, ';', '"', '\\')) !== false) {
            $lines[] = $line;
        }
        fclose($h);

        return $lines;
    }

    private function contracts(FilterCriteria $criteria): array
    {
        $out = [];
        (new SnapshotRowSource(store: $this->fx->store))->stream($criteria, static function (array $row) use (&$out): void {
            $out[] = $row['CONTRACT'];
        });

        return $out;
    }

    public function testCsvExportFromSnapshotHasTheUsualColumnsAndAllRows(): void
    {
        $this->installSample();

        $meta = $this->service()->exportCsv(FilterCriteria::none());

        $this->assertStringStartsWith('snapshot:', $meta['source']);
        $this->assertSame(5, $meta['rows']);
        $lines = $this->readCsv($meta['path']);
        $this->assertSame(CustomerListExportService::COLUMNS, $lines[0], 'same 25 columns/order as the Oracle export');
        $this->assertCount(6, $lines);
        $first = array_combine($lines[0], $lines[1]);
        $this->assertSame('900001', $first['CONTRACT']);
        $this->assertSame('Client Élève 1', $first['CUST_NAME']);
        $this->assertSame('2026-09-25 06:00:00', $first['UPDATED_AT']);
        $this->assertArrayNotHasKey('XCOORD', $first, 'snapshot-only columns are not exported');
    }

    public function testSnapshotKeepsAll27SourceColumnsIncludingCoordinates(): void
    {
        $this->installSample();
        $active = $this->fx->store->active();

        $this->assertSame(27, $active->meta['columns']);
        $this->assertSame(SnapshotFixture::HEADER, $active->reader()->header(), 'the stored snapshot is the source file, untouched');
        $this->assertContains('XCOORD', $active->reader()->header());
        $this->assertContains('YCOORD', $active->reader()->header());
    }

    public function testExportMapsColumnsByNameNotByPosition(): void
    {
        $rows = $this->sampleRows();

        // Same data, 27 columns in the canonical order...
        $this->installSample();
        $canonical = $this->readCsv($this->service()->exportCsv(FilterCriteria::none())['path']);

        // ...then in a completely different physical order.
        $shuffled = SnapshotFixture::HEADER;
        mt_srand(7);
        shuffle($shuffled);
        $this->assertNotSame(SnapshotFixture::HEADER, $shuffled);
        sleep(1);
        $this->fx->deliver(SnapshotFixture::csv($rows, $shuffled));
        $r = (new SnapshotInstaller($this->fx->store))->install();
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $reordered = $this->readCsv($this->service()->exportCsv(FilterCriteria::none())['path']);

        $this->assertSame($canonical, $reordered, 'identical 25-column export whatever the source column order');

        // Filters also resolve columns by name.
        $allowed = (new SnapshotRowSource(store: $this->fx->store))->allowedValues();
        $this->assertSame(['900003', '900004'], $this->contracts(FilterCriteria::fromRequest(['region' => ['DRE']], $allowed)));
    }

    public function testXlsxExportFromSnapshot(): void
    {
        $this->installSample();

        $meta = $this->service()->exportXlsx(FilterCriteria::none());

        $this->assertSame(5, $meta['rows']);
        $reader = new Reader();
        $reader->open($meta['path']);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(CustomerListExportService::COLUMNS, $rows[0]);
        $this->assertCount(6, $rows);
        $this->assertSame('900005', $rows[5][array_search('CONTRACT', CustomerListExportService::COLUMNS, true)]);
    }

    public function testFilteredExportContainsExactlyTheMatchingRows(): void
    {
        $this->installSample();
        $allowed  = (new SnapshotRowSource(store: $this->fx->store))->allowedValues();
        $criteria = FilterCriteria::fromRequest(['region' => ['DRE'], 'status' => ['ACTIVE']], $allowed);

        $meta  = $this->service()->exportCsv($criteria);
        $lines = $this->readCsv($meta['path']);

        $this->assertSame(1, $meta['rows']);
        $this->assertSame('900003', array_combine($lines[0], $lines[1])['CONTRACT']);
    }

    public function testListFiltersFollowSqlInSemantics(): void
    {
        $this->installSample();
        $allowed = (new SnapshotRowSource(store: $this->fx->store))->allowedValues();
        $c       = static fn (array $get) => FilterCriteria::fromRequest($get, $allowed);

        $this->assertSame(['900001', '900002'], $this->contracts($c(['region' => ['DRC']])));
        $this->assertSame(['900001', '900003', '900005'], $this->contracts($c(['status' => ['ACTIVE']])), 'exact value: ACTIVE (PENDING BILLING) is a different value');
        // Every meter type ticked = no restriction (same as nothing ticked):
        // the blank-METER row is included, as with no filter at all.
        $this->assertSame(['900001', '900002', '900003', '900004', '900005'], $this->contracts($c(['meter' => ['PREPAID', 'POSTPAID', 'Compteurs Communicants']])), 'all ticked = no restriction');
        $this->assertSame($this->contracts($c([])), $this->contracts($c(['meter' => ['PREPAID', 'POSTPAID', 'Compteurs Communicants']])));
        $this->assertSame(['900003'], $this->contracts($c(['niu_qc' => ['NUI a RECLASSER']])));
        $this->assertSame(['900004'], $this->contracts($c(['voltage' => ['MV']])));
        $this->assertSame(['900005'], $this->contracts($c(['segment_tresor' => ['PUBLIC']])));
        $this->assertSame(['900002'], $this->contracts($c(['segmentation' => ['8 Autre']])));
        $this->assertSame([], $this->contracts($c(['region' => ['DRC'], 'status' => ['ACTIVE'], 'meter' => ['POSTPAID']])), 'dimensions are ANDed');
    }

    public function testDateFilterIsInclusiveAtDayGranularityAndSkipsUnreadableDates(): void
    {
        $this->installSample();
        $allowed = (new SnapshotRowSource(store: $this->fx->store))->allowedValues();

        $jan = FilterCriteria::fromRequest(['date_from' => '2020-01-01', 'date_to' => '2020-01-31'], $allowed);
        $this->assertSame(['900001', '900002'], $this->contracts($jan));

        $from = FilterCriteria::fromRequest(['date_from' => '2020-01-31'], $allowed);
        $this->assertSame(['900002', '900003'], $this->contracts($from), "row 5 ('n/a') never matches a date filter");
    }

    public function testUnknownFilterValueIsRejectedByTheSnapshotAllowedValues(): void
    {
        $this->installSample();
        $allowed = (new SnapshotRowSource(store: $this->fx->store))->allowedValues();

        $this->expectException(\App\Services\CustomersList\InvalidFilterException::class);
        FilterCriteria::fromRequest(['region' => ["DRC' OR 1=1"]], $allowed);
    }

    public function testCountMatchesTheExportedRows(): void
    {
        $this->installSample();
        $source  = new SnapshotRowSource(store: $this->fx->store, cache: new MockCache());
        $allowed = $source->allowedValues();

        $this->assertSame(5, $source->count(FilterCriteria::none()));
        foreach ([['region' => ['DRE']], ['status' => ['ACTIVE']], ['date_from' => '2020-01-15']] as $get) {
            $criteria = FilterCriteria::fromRequest($get, $allowed);
            $this->assertSame(count($this->contracts($criteria)), $source->count($criteria));
        }
    }

    public function testExportWithoutSnapshotFailsCleanlyInsteadOfCallingOracle(): void
    {
        $this->expectException(SnapshotUnavailableException::class);

        $this->service();
    }

    public function testSnapshotCodeHasNoOracleDependency(): void
    {
        foreach (glob(APPPATH . 'Services/Snapshot/*.php') as $file) {
            $code = (string) file_get_contents($file);
            $this->assertStringNotContainsString('OracleExtractionService', $code, basename($file));
            $this->assertDoesNotMatchRegularExpression('/\boci_\w+\(/', $code, basename($file));
        }
    }

    public function testTwoConcurrentReadersOfTheSameSnapshotDoNotInterfere(): void
    {
        $rows = array_map(static fn (int $i) => SnapshotFixture::row($i), range(1, 500));
        $this->fx->install($rows);
        $active = $this->fx->store->active();

        $a = $active->reader();
        $b = $active->reader();
        $seenA = [];
        $seenB = [];
        $idx   = $a->index()['CONTRACT'];

        // Interleave the two open handles in blocks of 50 records: each()
        // stops when the callback returns false and resumes where it stopped.
        $block = static function (SnapshotReader $r, array &$seen) use ($idx): int {
            $n = 0;

            return $r->each(static function (array $fields) use (&$seen, &$n, $idx): bool {
                $seen[] = $fields[$idx];

                return ++$n < 50;
            });
        };
        do {
            $readA = $block($a, $seenA);
            $readB = $block($b, $seenB);
        } while ($readA > 0 || $readB > 0);

        $this->assertCount(500, $seenA);
        $this->assertSame($seenA, $seenB);
    }

    public function testNewSnapshotArrivingDuringAnExportDoesNotAffectIt(): void
    {
        // keepVersions=1: activating B immediately tries to delete A while A
        // is still being read.
        $fx = new SnapshotFixture(keepVersions: 1);

        try {
            $fx->install(array_map(static fn (int $i) => SnapshotFixture::row($i, ['SEGMENTATION' => 'A']), range(1, 2000)));
            $versionA = $fx->store->active()->id;
            $source   = new SnapshotRowSource(store: $fx->store); // pinned to A

            $seen      = [];
            $installed = null;
            sleep(1);
            $source->stream(FilterCriteria::none(), function (array $row) use (&$seen, &$installed, $fx): void {
                $seen[] = $row['SEGMENTATION'];
                if (count($seen) === 10) {
                    $installed = $fx->install(array_map(static fn (int $i) => SnapshotFixture::row($i, ['SEGMENTATION' => 'B']), range(1, 7)));
                }
            });

            $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $installed['result']);
            $this->assertCount(2000, $seen, 'the export on A ran to completion');
            $this->assertSame(['A'], array_values(array_unique($seen)), 'no row of B leaked into the export on A');
            $this->assertNotSame($versionA, $fx->store->active()->id);
            $this->assertSame(7, $fx->store->active()->rows(), 'new exports use B');
        } finally {
            $fx->cleanup();
        }
    }

    public function testMatcherRefusesADateFilterWhenTheDateFormatIsUnknown(): void
    {
        $criteria = FilterCriteria::fromArray(['dateFrom' => '2020-01-01']);

        $this->expectException(\App\Services\Snapshot\SnapshotException::class);
        new RowMatcher($criteria, ['DATE_AB' => 0], null);
    }

    public function testUnfilteredMatcherIsDetected(): void
    {
        $matcher = new RowMatcher(FilterCriteria::none(), [], null);
        $this->assertTrue($matcher->isUnfiltered());
        $this->assertInstanceOf(AllowedValues::class, AllowedValues::fromFilterOptions([]));
    }
}
