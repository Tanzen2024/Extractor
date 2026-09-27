<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Export\ReportsStreamStats;
use App\Services\Export\RowSource;
use App\Services\Snapshot\RowMatcher;
use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotStore;
use App\Services\Snapshot\SnapshotUnavailableException;
use CodeIgniter\Test\Mock\MockCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * FILTRES -> RÉSULTAT -> EXPORT CSV, end to end on a real snapshot file:
 * the request filters (same parameters as the screen) become a
 * FilterCriteria, SnapshotRowSource streams the matching rows, and
 * CustomerListExportService writes them to <final>.tmp then renames it.
 *
 * @internal
 */
final class CsvExportFromSnapshotTest extends TestCase
{
    private SnapshotFixture $fx;
    private string $exportDir;

    protected function setUp(): void
    {
        $this->fx        = new SnapshotFixture();
        $this->exportDir = $this->fx->baseDir . DIRECTORY_SEPARATOR . 'exports';
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    /** 6 rows: 2 regions x statuses x dates, with CSV-hostile characters. */
    private function installSample(): void
    {
        $rows = [
            SnapshotFixture::row(1, ['REGION' => 'DCUD', 'STATUS' => 'ACTIVE', 'DATE_AB' => '15/01/2020', 'CUST_NAME' => 'Dupont; "Le Grand"']),
            SnapshotFixture::row(2, ['REGION' => 'DCUD', 'STATUS' => 'INACTIVE', 'DATE_AB' => '20/06/2021', 'CUST_NAME' => 'Élève Ngo\'o']),
            SnapshotFixture::row(3, ['REGION' => 'DCUY', 'STATUS' => 'ACTIVE', 'DATE_AB' => '01/03/2022', 'CUST_NAME' => 'A;B;C']),
            SnapshotFixture::row(4, ['REGION' => 'DCUY', 'STATUS' => 'ACTIVE', 'DATE_AB' => '31/12/2023', 'CUST_NAME' => '"quoted"']),
            SnapshotFixture::row(5, ['REGION' => 'DRE', 'STATUS' => 'SUSPENDED', 'DATE_AB' => '10/10/2019', 'CUST_NAME' => 'Ouédraogo & Fils']),
            SnapshotFixture::row(6, ['REGION' => 'DCUD', 'STATUS' => 'ACTIVE', 'DATE_AB' => '', 'CUST_NAME' => '=SUM(A1)']),
        ];
        $r = $this->fx->install($rows);
        $this->assertSame('activated', $r['result'], (string) $r['message']);
    }

    private function source(): SnapshotRowSource
    {
        return new SnapshotRowSource(store: $this->fx->store, cache: new MockCache());
    }

    private function service(?RowSource $source = null): CustomerListExportService
    {
        return new CustomerListExportService(exportDir: $this->exportDir, rowSource: $source ?? $this->source());
    }

    /** Same path as DashboardController::export: request params validated against the snapshot's values. */
    private function criteria(array $post): FilterCriteria
    {
        return FilterCriteria::fromRequest($post, $this->source()->allowedValues());
    }

    /**
     * Reads an exported file back with the exact dialect CsvRowWriter writes.
     *
     * @return array{header: list<string>, rows: list<array<string, string>>, bom: bool}
     */
    private function readExport(string $path): array
    {
        $h   = fopen($path, 'rb');
        $bom = fread($h, 3) === "\xEF\xBB\xBF";
        if (! $bom) {
            rewind($h);
        }
        $header = fgetcsv($h, 0, ';', '"', '\\');
        $rows   = [];
        while (($line = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
            $rows[] = array_combine($header, $line);
        }
        fclose($h);

        return ['header' => $header, 'rows' => $rows, 'bom' => $bom];
    }

    /** @return list<string> */
    private function exportDirEntries(): array
    {
        return array_values(array_diff(scandir($this->exportDir) ?: [], ['.', '..']));
    }

    public function testExportWithoutFilterContainsEveryRowInTheExistingFormat(): void
    {
        $this->installSample();

        $meta = $this->service()->exportCsv(FilterCriteria::none());
        $out  = $this->readExport($meta['path']);

        $this->assertTrue($out['bom'], 'UTF-8 BOM kept for Excel');
        $this->assertSame(CustomerListExportService::COLUMNS, $out['header']);
        $this->assertCount(6, $out['rows']);
        $this->assertSame([6, 6, 6, 6], [$meta['rows'], $meta['rowsRead'], $meta['rowsMatched'], $meta['rowsExported']]);
        $this->assertSame(0.0, $meta['filterDurationMs'], 'no filter evaluated when nothing is filtered');
        $this->assertSame(filesize($meta['path']), $meta['fileSize']);
    }

    public function testExportWithOneFilter(): void
    {
        $this->installSample();

        $meta = $this->service()->exportCsv($this->criteria(['status' => ['ACTIVE']]));
        $out  = $this->readExport($meta['path']);

        $this->assertSame(['CLI1', 'CLI3', 'CLI4', 'CLI6'], array_column($out['rows'], 'COD_CLI'));
        $this->assertSame(6, $meta['rowsRead']);
        $this->assertSame(4, $meta['rowsMatched']);
        $this->assertSame(4, $meta['rowsExported']);
    }

    public function testExportWithSeveralFiltersIncludingMultiValueAndDates(): void
    {
        $this->installSample();

        $criteria = $this->criteria([
            'region'    => ['DCUD', 'DCUY'],
            'status'    => ['ACTIVE'],
            'date_from' => '2020-01-15', // inclusive
            'date_to'   => '2023-12-31', // inclusive, whole day
        ]);
        $meta = $this->service()->exportCsv($criteria);

        // Row 6 is ACTIVE/DCUD but has no DATE_AB: never matches a date filter (SQL NULL rule).
        $this->assertSame(['CLI1', 'CLI3', 'CLI4'], array_column($this->readExport($meta['path'])['rows'], 'COD_CLI'));
        $this->assertSame($this->source()->count($criteria), $meta['rowsExported'], 'export == displayed count for the same filters');
    }

    public function testNoMatchingRowGivesHeaderOnlyFile(): void
    {
        $this->installSample();

        $meta = $this->service()->exportCsv($this->criteria(['region' => ['DRE'], 'status' => ['ACTIVE']]));
        $out  = $this->readExport($meta['path']);

        $this->assertSame(0, $meta['rowsExported']);
        $this->assertSame(6, $meta['rowsRead']);
        $this->assertSame(CustomerListExportService::COLUMNS, $out['header']);
        $this->assertSame([], $out['rows']);
    }

    public function testSpecialCharactersSeparatorsAndQuotesSurviveExactly(): void
    {
        $this->installSample();

        $rows = $this->readExport($this->service()->exportCsv(FilterCriteria::none())['path'])['rows'];
        $names = array_column($rows, 'CUST_NAME', 'COD_CLI');

        $this->assertSame('Dupont; "Le Grand"', $names['CLI1']);
        $this->assertSame('Élève Ngo\'o', $names['CLI2']);
        $this->assertSame('A;B;C', $names['CLI3']);
        $this->assertSame('"quoted"', $names['CLI4']);
        $this->assertSame('Ouédraogo & Fils', $names['CLI5']);
        $this->assertSame('=SUM(A1)', $names['CLI6']);
        $this->assertSame(' ', $rows[0]['E_MAIL'], 'values passed through as in the snapshot');
        $this->assertSame('', $rows[0]['DATE_RESILIATION'], 'empty value stays empty');
    }

    public function testMissingSourceFailsCleanlyWithoutAnyFile(): void
    {
        $this->installSample();
        $active = $this->fx->store->active();
        unlink($active->csvPath);

        try {
            $this->service()->exportCsv(FilterCriteria::none());
            $this->fail('export must fail when the snapshot file is gone');
        } catch (SnapshotUnavailableException) {
            // resolving the source (SnapshotStore::load) refuses a version without its file
        }
        $this->assertSame([], is_dir($this->exportDir) ? $this->exportDirEntries() : []);
    }

    public function testSourceDisappearingAfterResolutionFailsWithoutAnyFile(): void
    {
        $this->installSample();
        $source = $this->source();
        unlink($source->snapshot()->csvPath);

        $this->expectException(SnapshotException::class);
        try {
            $this->service($source)->exportCsv(FilterCriteria::none());
        } finally {
            $this->assertSame([], $this->exportDirEntries(), 'neither .tmp nor final file');
        }
    }

    public function testEmptySourceFileFailsWithoutAnyFile(): void
    {
        $this->installSample();
        $source = $this->source();
        file_put_contents($source->snapshot()->csvPath, '');

        try {
            $this->service($source)->exportCsv(FilterCriteria::none());
            $this->fail('an empty snapshot file must not produce an export');
        } catch (SnapshotException $e) {
            $this->assertSame('snapshot_empty', $e->reason);
        }
        $this->assertSame([], $this->exportDirEntries());
    }

    public function testUnwritableExportDirectoryIsReported(): void
    {
        $this->installSample();
        $blocker = $this->fx->baseDir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'x');

        // mkdir() fails; CodeIgniter turns its warning into an ErrorException.
        $this->expectException(Exception::class);
        new CustomerListExportService(exportDir: $blocker . DIRECTORY_SEPARATOR . 'exports', rowSource: $this->source());
    }

    public function testFailureMidStreamDeletesTheTemporaryFileAndPublishesNothing(): void
    {
        $this->installSample();
        $seenTmp = [];
        $failing = new ProbeRowSource($this->source(), function (int $n) use (&$seenTmp): void {
            $seenTmp = $this->exportDirEntries();
            if ($n === 3) {
                throw new RuntimeException('disk gone');
            }
        });

        try {
            $this->service($failing)->exportCsv(FilterCriteria::none());
            $this->fail('the export should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('disk gone', $e->getMessage());
        }

        $this->assertCount(1, $seenTmp);
        $this->assertStringEndsWith('.csv.tmp', $seenTmp[0], 'rows are written to a .tmp file');
        $this->assertSame([], $this->exportDirEntries(), '.tmp deleted, no final file');
    }

    public function testFinalFileAppearsOnlyAfterSuccess(): void
    {
        $this->installSample();
        $during = [];
        $probe  = new ProbeRowSource($this->source(), function () use (&$during): void {
            $during = $this->exportDirEntries();
        });

        $meta = $this->service($probe)->exportCsv(FilterCriteria::none());

        $this->assertSame([$meta['filename'] . '.tmp'], $during, 'only the .tmp exists while writing');
        $this->assertSame([$meta['filename']], $this->exportDirEntries(), 'only the final file exists afterwards');
    }

    public function testLargeVolumeStreamsWithFlatMemory(): void
    {
        $rows = 200_000;
        $csv  = implode('#', SnapshotFixture::HEADER) . "\n";
        $base = SnapshotFixture::row(0, ['CUST_NAME' => 'Client "streaming"; test Élève']);
        $h    = fopen('php://temp', 'w+b');
        fwrite($h, $csv);
        for ($i = 1; $i <= $rows; $i++) {
            $base['COD_CLI']  = 'CLI' . $i;
            $base['CONTRACT'] = (string) (900000 + $i);
            $base['STATUS']   = $i % 3 === 0 ? 'INACTIVE' : 'ACTIVE';
            fwrite($h, implode('#', array_map(static fn (string $c): string => $base[$c], SnapshotFixture::HEADER)) . "\n");
        }
        rewind($h);
        $this->fx->deliver((string) stream_get_contents($h));
        fclose($h);
        $this->assertSame('activated', (new \App\Services\Snapshot\SnapshotInstaller($this->fx->store))->install()['result']);

        $samples = [];
        $probe   = new ProbeRowSource($this->source(), static function (int $n) use (&$samples): void {
            if ($n % 10_000 === 0) {
                $samples[] = memory_get_usage();
            }
        });

        $meta = $this->service($probe)->exportCsv($this->criteria(['status' => ['ACTIVE']]));

        $expected = $rows - intdiv($rows, 3);
        $this->assertSame($expected, $meta['rowsExported']);
        $this->assertSame($rows, $meta['rowsRead']);
        $lines = 0;
        $fh    = fopen($meta['path'], 'rb');
        while (fgets($fh) !== false) {
            $lines++;
        }
        fclose($fh);
        $this->assertSame($expected + 1, $lines, 'header + one line per exported row');

        // Memory must not grow with the number of rows: the spread between
        // samples taken from 10k to 133k exported rows stays under 2 MB
        // (the whole file is ~40 MB).
        $this->assertGreaterThan(10, count($samples));
        $this->assertLessThan(2 * 1048576, max($samples) - min($samples), 'memory grows with row count');
    }

    /**
     * Guard against the screen and the export drifting apart: the screen's
     * SQL (QueryBuilder::where) and the export's PHP predicate (RowMatcher)
     * must filter on exactly the same columns.
     */
    public function testScreenSqlAndExportMatcherFilterOnTheSameColumns(): void
    {
        $all = new AllowedValues(
            regions: ['R'], divisions: ['D'], agences: ['A'], statuses: ['S'], segmentations: ['G'],
            segmentsTresor: ['T'], meters: ['M'], voltages: ['V'], niuQualities: ['N'],
        );
        $criteria = FilterCriteria::fromRequest([
            'region' => ['R'], 'division' => ['D'], 'agence' => ['A'], 'status' => ['S'], 'segmentation' => ['G'],
            'segment_tresor' => ['T'], 'meter' => ['M'], 'voltage' => ['V'], 'niu_qc' => ['N'],
            'date_from' => '2020-01-01', 'date_to' => '2020-12-31',
        ], $all);

        preg_match_all('/\b([A-Z_]+) (?:IN \(|>=|<)/', (new QueryBuilder())->where($criteria)['sql'], $m);
        $sqlColumns = array_values(array_unique($m[1]));
        $phpColumns = array_merge([FilterCriteria::DATE_COLUMN], array_keys(RowMatcher::LIST_FILTERS));

        sort($sqlColumns);
        sort($phpColumns);
        $this->assertSame($sqlColumns, $phpColumns);
    }
}

/** Decorator: forwards rows, calling $probe(rowNumber) after each one reaches the writer. */
final class ProbeRowSource implements RowSource, ReportsStreamStats
{
    /** @var callable(int): void */
    private $probe;

    public function __construct(private RowSource $inner, callable $probe)
    {
        $this->probe = $probe;
    }

    public function stream(FilterCriteria $criteria, callable $onRow): int
    {
        $n = 0;

        return $this->inner->stream($criteria, function (array $row) use ($onRow, &$n): void {
            $onRow($row);
            ($this->probe)(++$n);
        });
    }

    public function label(): string
    {
        return $this->inner->label();
    }

    public function lastStreamStats(): array
    {
        return $this->inner instanceof ReportsStreamStats
            ? $this->inner->lastStreamStats()
            : ['rows_read' => 0, 'rows_matched' => 0, 'read_s' => 0.0, 'filter_s' => 0.0];
    }
}
