<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\OracleExtractionService;
use App\Services\Refresh\CustomersRefresher;
use App\Services\Refresh\OracleSnapshotExtractor;
use App\Services\Snapshot\SnapshotRowSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * customers:refresh — Oracle -> customers_list.csv.tmp -> active snapshot.
 * The invariant checked throughout: whatever goes wrong, the temporary file
 * is gone and the previously active version is still the one served.
 *
 * @internal
 */
final class CustomersRefreshTest extends TestCase
{
    private SnapshotFixture $fx;

    protected function setUp(): void
    {
        $this->fx = new SnapshotFixture();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    /** @return list<array<string, string>> Oracle rows as oci_fetch_assoc returns them (25 columns). */
    private static function oracleRows(int $n, array $overrides = []): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $row = array_intersect_key(SnapshotFixture::row($i), array_flip(CustomerListExportService::COLUMNS));
            $row['DATE_AB']      = '15/04/2024';
            $row['LAST_VC_DATE'] = '01/08/2028';
            $row['E_MAIL']       = null; // NULL comes back as null
            $rows[] = array_merge($row, $overrides[$i] ?? []);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function refresher(array $rows, ?int $count = null, ?Throwable $failCount = null, ?int $failAfter = null, ?callable $stop = null, array &$logs = []): CustomersRefresher
    {
        $oracle = new RefreshFakeOracle($rows, $count ?? count($rows), $failCount, $failAfter);
        $log    = static function (string $level, string $message) use (&$logs): void {
            $logs[] = "{$level}: {$message}";
        };

        return new CustomersRefresher(
            $this->fx->store,
            new OracleSnapshotExtractor($oracle, null, $log, $stop, progressEvery: 2),
            $log,
        );
    }

    private function baseline(int $rows = 10): string
    {
        $r = $this->refresher(self::oracleRows($rows))->refresh();
        $this->assertSame(CustomersRefresher::RESULT_ACTIVATED, $r['result'], $r['message']);

        return $r['version'];
    }

    private function assertFailedKeeping(array $r, string $reason, ?string $previous): void
    {
        $this->assertSame(CustomersRefresher::RESULT_FAILED, $r['result'], $r['message']);
        $this->assertSame($reason, $r['reason'], $r['message']);
        $this->assertFileDoesNotExist($this->fx->config->refreshTmpFile, 'no partial file may survive');
        $this->assertFileDoesNotExist($this->fx->config->refreshTmpFile . '.manifest');
        $this->assertSame($previous, $this->fx->store->activeId(), 'the previous version must stay active');
        if ($previous !== null) {
            $this->assertSame(10, $this->fx->store->active()->rows());
            $this->assertFileExists($this->fx->store->active()->csvPath);
        }
    }

    public function testSelectIsExplicitAndRendersDatesWithFourDigitYears(): void
    {
        $sql = OracleSnapshotExtractor::selectSql();

        $this->assertStringNotContainsString('*', $sql);
        $this->assertStringContainsString('FROM CMS_RFC.TB_CUSTOMERS_LIST', $sql);
        foreach (CustomerListExportService::COLUMNS as $column) {
            $this->assertMatchesRegularExpression('/\b' . $column . '\b/', $sql);
        }
        foreach (OracleSnapshotExtractor::DATE_COLUMNS as $column) {
            $this->assertStringContainsString("TO_CHAR({$column}, 'DD/MM/YYYY') AS {$column}", $sql);
        }
    }

    public function testSmallVolumeBecomesTheActiveWorkingFile(): void
    {
        $logs = [];
        $r    = $this->refresher(self::oracleRows(5), logs: $logs)->refresh();

        $this->assertSame(CustomersRefresher::RESULT_ACTIVATED, $r['result'], $r['message']);
        $active = $this->fx->store->active();
        $this->assertSame($r['version'], $active->id);
        $this->assertSame(5, $active->rows());
        $this->assertSame('d/m/Y', $active->dateFormat(), 'DD/MM/YYYY must be detected for the DATE_AB filter');
        $this->assertSame('oracle:CMS_RFC.TB_CUSTOMERS_LIST', $active->meta['manifest']['source']);

        $lines = file($active->csvPath, FILE_IGNORE_NEW_LINES);
        $this->assertSame(implode('#', CustomerListExportService::COLUMNS), $lines[0]);
        $this->assertCount(6, $lines);
        $this->assertStringContainsString('#15/04/2024#', $lines[1]);
        $this->assertFileDoesNotExist($this->fx->config->refreshTmpFile);
        $this->assertNotEmpty(preg_grep('/rows extracted/', $logs), 'progress must be logged');

        // The working file serves filters + export rows with the same 25 columns.
        $source = new SnapshotRowSource(store: $this->fx->store);
        $crit   = FilterCriteria::fromArray(['dateFrom' => '2024-04-01', 'dateTo' => '2024-04-30']);
        $this->assertSame(5, $source->count($crit));
        $out = [];
        $source->stream(FilterCriteria::none(), static function (array $row) use (&$out): void {
            $out[] = $row;
        });
        $this->assertSame(CustomerListExportService::COLUMNS, array_keys($out[0]));
        $this->assertSame('', $out[0]['E_MAIL'], 'NULL becomes an empty field');

        // The new version comes with its dashboard index, built from this CSV
        // before activation: the dashboard answers like the export.
        if ((new \App\Services\Snapshot\DuckDb($this->fx->config))->version() !== null) {
            $engine = (new \App\Services\Snapshot\SnapshotIndex($this->fx->config))->engine($active);
            $this->assertSame($r['version'], $engine->version());
            $this->assertSame(5, $engine->count($crit));
        }
    }

    public function testOracleConnectionFailureKeepsPreviousVersion(): void
    {
        $previous = $this->baseline();

        $r = $this->refresher(self::oracleRows(10), failCount: new RuntimeException('Oracle connection failed: ORA-12541'))->refresh();

        $this->assertFailedKeeping($r, 'oracle_or_internal_error', $previous);
        $this->assertStringContainsString('ORA-12541', $r['message']);
    }

    public function testOracleFailureMidStreamKeepsPreviousVersion(): void
    {
        $previous = $this->baseline();

        $r = $this->refresher(self::oracleRows(10), failAfter: 4)->refresh();

        $this->assertFailedKeeping($r, 'oracle_or_internal_error', $previous);
        $this->assertStringContainsString('ORA-08103', $r['message']);
    }

    public function testInterruptionDuringExtractionKeepsPreviousVersion(): void
    {
        $previous = $this->baseline();
        $rows     = self::oracleRows(12_000); // the stop flag is polled every 5000 rows

        $r = $this->refresher($rows, stop: static fn (): bool => true)->refresh();

        $this->assertFailedKeeping($r, 'interrupted', $previous);
    }

    public function testUnwritableTemporaryFileKeepsPreviousVersion(): void
    {
        $previous = $this->baseline();

        // A regular file where the temp file's directory should be.
        $blocker = $this->fx->baseDir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'x');
        $this->fx->config->refreshTmpFile = $blocker . DIRECTORY_SEPARATOR . 'customers_list.csv.tmp';

        $r = $this->refresher(self::oracleRows(10))->refresh();

        $this->assertFailedKeeping($r, 'write_failed', $previous);
    }

    public function testCountMismatchIsRefused(): void
    {
        $previous = $this->baseline();

        $r = $this->refresher(self::oracleRows(10), count: 11)->refresh();

        $this->assertFailedKeeping($r, 'row_count_mismatch', $previous);
    }

    public function testEmptySourceIsRefused(): void
    {
        $previous = $this->baseline();

        $r = $this->refresher([], count: 0)->refresh();

        $this->assertFailedKeeping($r, 'no_data', $previous);
    }

    public function testVolumeDropIsRefusedUnlessForced(): void
    {
        $previous = $this->baseline(10);

        $r = $this->refresher(self::oracleRows(5))->refresh();
        $this->assertFailedKeeping($r, 'volume_drop', $previous);

        $forced = $this->refresher(self::oracleRows(5))->refresh(force: true);
        $this->assertSame(CustomersRefresher::RESULT_ACTIVATED, $forced['result'], $forced['message']);
        $this->assertSame(5, $this->fx->store->active()->rows());
        $this->assertContains($previous, $this->fx->store->versions(), 'the previous version stays available for rollback');
    }

    public function testSecondConcurrentRefreshIsRejectedAsBusy(): void
    {
        $lock = fopen($this->fx->config->refreshLockFile, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $r = $this->refresher(self::oracleRows(3))->refresh();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(CustomersRefresher::RESULT_BUSY, $r['result']);
        $this->assertNull($this->fx->store->activeId());
    }

    public function testStaleTemporaryFileFromAKilledRunIsReplaced(): void
    {
        file_put_contents($this->fx->config->refreshTmpFile, "garbage from a killed run\n");

        $r = $this->refresher(self::oracleRows(3))->refresh();

        $this->assertSame(CustomersRefresher::RESULT_ACTIVATED, $r['result'], $r['message']);
        $this->assertSame(3, $this->fx->store->active()->rows());
    }

    public function testDelimiterAndNewlinesInValuesAreNeutralised(): void
    {
        $logs = [];
        $rows = self::oracleRows(2, [2 => ['CUST_NAME' => "DUPONT #2\r\nSARL"]]);

        $r = $this->refresher($rows, logs: $logs)->refresh();

        $this->assertSame(CustomersRefresher::RESULT_ACTIVATED, $r['result'], $r['message']);
        $this->assertSame(1, $r['facts']['sanitized_values']);
        $this->assertSame(2, $this->fx->store->active()->rows());
        $this->assertStringContainsString('DUPONT  2  SARL', (string) file_get_contents($this->fx->store->active()->csvPath));
    }

    public function testPublishedLinkPointsAtActiveVersion(): void
    {
        $this->baseline();
        $link = $this->fx->config->publishedLink;

        if (! is_link($link)) {
            $this->markTestSkipped('symlink() not permitted on this system (Windows without the privilege) — link is best effort.');
        }

        $this->assertSame(realpath($this->fx->store->active()->csvPath), realpath($link));
    }
}

/**
 * OracleExtractionService stand-in: the COUNT query and the streaming
 * SELECT, with optional failures.
 */
final class RefreshFakeOracle extends OracleExtractionService
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows, private int $count, private ?Throwable $failCount = null, private ?int $failAfter = null)
    {
    }

    public function select(string $sql, array $binds = [], int $maxRows = 5000): array
    {
        if ($this->failCount !== null) {
            throw $this->failCount;
        }

        return ['columns' => ['N', 'SOURCE_UPDATED_AT'], 'rows' => [['N' => (string) $this->count, 'SOURCE_UPDATED_AT' => '2026-09-27 04:32:49']]];
    }

    public function stream(string $sql, callable $onRow, array $binds = [], ?int $prefetch = null): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($this->failAfter !== null && $n === $this->failAfter) {
                throw new RuntimeException('Oracle execution failed: ORA-08103: object no longer exists');
            }
            $onRow($row);
            $n++;
        }

        return $n;
    }
}
