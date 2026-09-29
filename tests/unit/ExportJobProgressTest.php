<?php

use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\ReportsProgress;
use App\Services\Export\ThrottledProgress;
use App\Services\Snapshot\SnapshotRowSource;
use CodeIgniter\Test\Mock\MockCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * Real progress of an asynchronous CSV export:
 *   - the percentage GET /exports/{id} reports (ExportJobModel::progress());
 *   - batching of the DB writes (ThrottledProgress — never per row);
 *   - what SnapshotRowSource reports while scanning (scan, not matches).
 *
 * @internal
 */
final class ExportJobProgressTest extends TestCase
{
    private const TOTAL = 3_302_841;

    // ── percentage (endpoint) ────────────────────────────────────────

    private static function job(string $status, ?int $total, int $processed = 0, int $exported = 0): array
    {
        return ['status' => $status, 'rows_total' => $total, 'rows_processed' => $processed, 'rows_exported' => $exported];
    }

    public function testPendingIsZero(): void
    {
        $this->assertSame(
            ['percent' => 0, 'processed' => 0, 'total' => null, 'exported' => 0],
            ExportJobModel::progress(self::job('pending', null)),
        );
    }

    public function testRunningIsTheShareOfTheSnapshotScanned(): void
    {
        $p = ExportJobModel::progress(self::job('running', self::TOTAL, 1_550_000, 12_000));

        $this->assertSame(46, $p['percent']); // 1 550 000 / 3 302 841 = 46.9 %, floored
        $this->assertSame(1_550_000, $p['processed']);
        $this->assertSame(self::TOTAL, $p['total']);
        $this->assertSame(12_000, $p['exported']); // selective filter: few rows written, bar still moves
    }

    public function testRunningAtHalf(): void
    {
        $this->assertSame(50, ExportJobModel::progress(self::job('running', 2_000_000, 1_000_000))['percent']);
    }

    public function testRunningAt99(): void
    {
        $this->assertSame(99, ExportJobModel::progress(self::job('running', 1_000, 995))['percent']);
    }

    public function testRunningNeverReaches100BeforeDone(): void
    {
        // Scan complete, file still being flushed/renamed.
        $this->assertSame(99, ExportJobModel::progress(self::job('running', self::TOTAL, self::TOTAL))['percent']);
    }

    public function testNeverAbove100EvenWithInconsistentCounters(): void
    {
        $this->assertSame(99, ExportJobModel::progress(self::job('running', 100, 250))['percent']);
        $this->assertSame(100, ExportJobModel::progress(self::job('done', 100, 250))['percent']);
    }

    public function testDoneIs100(): void
    {
        $this->assertSame(100, ExportJobModel::progress(self::job('done', self::TOTAL, self::TOTAL, self::TOTAL))['percent']);
        $this->assertSame(100, ExportJobModel::progress(self::job('done', null))['percent']); // job finished before this feature
    }

    public function testZeroOrUnknownTotalIsZero(): void
    {
        $this->assertSame(0, ExportJobModel::progress(self::job('running', 0, 500))['percent']);
        $this->assertSame(0, ExportJobModel::progress(self::job('running', null, 500))['percent']);
        $this->assertSame(0, ExportJobModel::progress(['status' => 'running'])['percent']); // columns absent
    }

    // ── batching of the writes ───────────────────────────────────────

    public function testThrottleWritesAtMostOncePerIntervalButAlwaysFirstAndLast(): void
    {
        $now    = 0.0;
        $writes = [];
        $t      = new ThrottledProgress(
            static function (int $p, int $total, int $e) use (&$writes): void { $writes[] = [$p, $total, $e]; },
            1.0,
            static function () use (&$now): float { return $now; },
        );

        $t(0, 100_000, 0);            // first: written
        $now = 0.4; $t(25_000, 100_000, 10);  // < 1 s: skipped
        $now = 0.9; $t(50_000, 100_000, 20);  // < 1 s: skipped
        $now = 1.0; $t(75_000, 100_000, 30);  // 1 s: written
        $now = 1.2; $t(100_000, 100_000, 40); // completion: always written

        $this->assertSame([[0, 100_000, 0], [75_000, 100_000, 30], [100_000, 100_000, 40]], $writes);
        $this->assertSame(3, $t->writes());
    }

    // ── what the snapshot scan reports ───────────────────────────────

    public function testSnapshotReportsScanProgressInBatchesForAFilteredExport(): void
    {
        $fx = new SnapshotFixture();

        try {
            $n    = ReportsProgress::PROGRESS_EVERY_ROWS * 2 + 7; // 50 007 rows
            $rows = [];
            for ($i = 1; $i <= $n; $i++) {
                $rows[] = SnapshotFixture::row($i, ['REGION' => $i % 10 === 0 ? 'DCUD' : 'DCUY']);
            }
            $fx->install($rows);

            $source = new SnapshotRowSource(store: $fx->store, cache: new MockCache());
            $calls  = [];
            $source->setProgressListener(static function (int $p, int $t, int $e) use (&$calls): void { $calls[] = [$p, $t, $e]; });

            $criteria = FilterCriteria::fromRequest(['region' => ['DCUD']], $source->allowedValues());
            $matched  = $source->stream($criteria, static function (): void {});

            $this->assertSame(intdiv($n, 10), $matched);
            // Start, every 25 000 rows scanned, completion — never per row.
            $this->assertSame([
                [0, $n, 0],
                [25_000, $n, 2_500],
                [50_000, $n, 5_000],
                [$n, $n, $matched],
            ], $calls);

            // Detached: a later stream reports nothing.
            $source->setProgressListener(null);
            $calls = [];
            $source->stream($criteria, static function (): void {});
            $this->assertSame([], $calls);
        } finally {
            $fx->cleanup();
        }
    }

    public function testCsvExportForwardsProgressAndEndsOnExactCounters(): void
    {
        $fx = new SnapshotFixture();

        try {
            $rows = [];
            for ($i = 1; $i <= 30; $i++) {
                $rows[] = SnapshotFixture::row($i, ['REGION' => $i <= 12 ? 'DCUD' : 'DCUY']);
            }
            $fx->install($rows);

            $source  = new SnapshotRowSource(store: $fx->store, cache: new MockCache());
            $service = new CustomerListExportService(exportDir: $fx->baseDir . DIRECTORY_SEPARATOR . 'exports', rowSource: $source);
            $last    = null;

            $meta = $service->exportCsv(
                FilterCriteria::fromRequest(['region' => ['DCUD']], $source->allowedValues()),
                static function (int $p, int $t, int $e) use (&$last): void { $last = [$p, $t, $e]; },
            );

            $this->assertSame(12, $meta['rows']);
            $this->assertSame([30, 30, 12], $last); // processed = total scanned, exported = rows written

            // Without a listener argument the export is unchanged and the
            // source was left detached.
            $last = null;
            $this->assertSame(12, $service->exportCsv(FilterCriteria::fromRequest(['region' => ['DCUD']], $source->allowedValues()))['rows']);
            $this->assertNull($last);
        } finally {
            $fx->cleanup();
        }
    }

    // ── Export Excel stays hidden ────────────────────────────────────

    public function testExcelExportButtonIsStillNotRendered(): void
    {
        $view = file_get_contents(APPPATH . 'Views/dashboard/index.php');
        // Drop the PHP comment blocks (open tag + /* ... */ + close tag):
        // what remains is what the browser receives.
        $rendered = preg_replace('~<\?php\s*/\*.*?\*/\s*\?>~s', '', $view);

        $this->assertStringContainsString('data-export="csv"', $rendered);
        $this->assertStringNotContainsString('data-export="xlsx"', $rendered);
        $this->assertLessThan(
            strpos($rendered, 'assets/js/dashboard.js'),
            strpos($rendered, 'assets/js/export-progress.js'),
            'export-progress.js must load before dashboard.js',
        );
    }
}
