<?php

use App\Controllers\ExportJobController;
use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\ExportJobRunner;
use App\Services\Export\ReportsProgress;
use App\Services\Export\RowSource;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Real cancellation of asynchronous exports (POST /exports/{id}/cancel +
 * cooperative stop of the worker). The worker side runs the real
 * ExportJobRunner and CustomerListExportService::exportCsv() — real .tmp
 * file, real deletion — on a synthetic source that reports progress every
 * ReportsProgress::PROGRESS_EVERY_ROWS rows like the snapshot does.
 *
 * @internal
 */
final class ExportJobCancelTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    // Same reason as ExportJobModelTest: forge only this table.
    protected $migrate = false;

    private const ROWS = 120_000;

    private ExportJobModel $model;
    private string $exportDir;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = Config\Database::forge();
        $forge->dropTable('export_jobs', true);
        $forge->addField([
            'id'              => ['type' => 'INTEGER', 'auto_increment' => true],
            'uuid'            => ['type' => 'VARCHAR', 'constraint' => 36],
            'requested_by'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'format'          => ['type' => 'VARCHAR', 'constraint' => 8],
            'filters'         => ['type' => 'TEXT', 'null' => true],
            'filters_label'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'pending'],
            'row_count'       => ['type' => 'INTEGER', 'null' => true],
            'rows_total'      => ['type' => 'INTEGER', 'null' => true],
            'rows_processed'  => ['type' => 'INTEGER', 'default' => 0],
            'rows_exported'   => ['type' => 'INTEGER', 'default' => 0],
            'file_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'file_name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'file_size'       => ['type' => 'INTEGER', 'null' => true],
            'error_reference' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'started_at'      => ['type' => 'DATETIME', 'null' => true],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('export_jobs', true);

        $this->model     = new ExportJobModel();
        $this->exportDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_cancel_' . bin2hex(random_bytes(4));
        mkdir($this->exportDir);

        session()->set('username', 'alice');
    }

    protected function tearDown(): void
    {
        Config\Database::forge()->dropTable('export_jobs', true);
        session()->remove('username');

        foreach (glob($this->exportDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->exportDir);

        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $fields */
    private function job(array $fields = []): int
    {
        return $this->model->insert($fields + [
            'requested_by' => 'alice', 'format' => 'csv', 'filters' => '[]', 'status' => 'pending', 'row_count' => self::ROWS,
        ], true);
    }

    /**
     * Synthetic source: self::ROWS rows, progress like SnapshotRowSource
     * (0, every 25k scanned, end). $onScan(processed) runs just before each
     * progress call — where a user's click lands between two batches;
     * $afterScan runs once the scan is complete, before the file is closed.
     */
    private function source(?Closure $onScan = null, ?Closure $afterScan = null): RowSource
    {
        return new class (self::ROWS, $onScan, $afterScan) implements RowSource, ReportsProgress {
            public int $scanned = 0;
            private $listener;

            public function __construct(private int $total, private ?Closure $onScan, private ?Closure $afterScan)
            {
            }

            public function setProgressListener(?callable $listener): void
            {
                $this->listener = $listener;
            }

            public function label(): string
            {
                return 'fake';
            }

            public function stream(FilterCriteria $criteria, callable $onRow): int
            {
                $row = array_fill_keys(CustomerListExportService::COLUMNS, 'x');
                $this->report(0);

                for ($i = 1; $i <= $this->total; $i++) {
                    $row['CONTRACT'] = (string) $i;
                    $onRow($row);
                    $this->scanned = $i;

                    if ($i % ReportsProgress::PROGRESS_EVERY_ROWS === 0) {
                        $this->report($i);
                    }
                }
                $this->report($this->total);

                if ($this->afterScan !== null) {
                    ($this->afterScan)();
                }

                return $this->total;
            }

            private function report(int $processed): void
            {
                if ($this->onScan !== null) {
                    ($this->onScan)($processed);
                }
                if ($this->listener !== null) {
                    ($this->listener)($processed, $this->total, $processed);
                }
            }
        };
    }

    /** Worker with an unthrottled progress write: one check per 25k-row batch. */
    private function runner(RowSource $source): ExportJobRunner
    {
        return new ExportJobRunner(
            $this->model,
            fn (): CustomerListExportService => new CustomerListExportService(exportDir: $this->exportDir, rowSource: $source),
            0.0,
        );
    }

    /** Same loop as `php spark export:process`. @return array<int, string> id => outcome */
    private function work(ExportJobRunner $runner): array
    {
        $outcomes = [];
        while (($job = $this->model->claimNext()) !== null) {
            $outcomes[(int) $job['id']] = $runner->run($job);
        }

        return $outcomes;
    }

    /** @return list<string> file names in the export dir */
    private function files(): array
    {
        $names = array_map('basename', glob($this->exportDir . DIRECTORY_SEPARATOR . '*') ?: []);
        sort($names);

        return $names;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function cancelViaApi(int $id): array
    {
        $result = $this->controller(ExportJobController::class)->execute('cancel', $id);

        return [$result->response()->getStatusCode(), json_decode($result->response()->getBody(), true)];
    }

    /** @return array<string, mixed> */
    private function show(int $id): array
    {
        return json_decode($this->controller(ExportJobController::class)->execute('show', $id)->response()->getBody(), true);
    }

    // ── TEST 1 — pending ─────────────────────────────────────────────

    public function testCancellingAPendingJobIsImmediateAndTheWorkerNeverTakesIt(): void
    {
        $id = $this->job();

        [$code, $json] = $this->cancelViaApi($id);

        $this->assertSame(200, $code);
        $this->assertSame(['success' => true, 'status' => 'cancelled', 'previousStatus' => 'pending'],
            array_intersect_key($json, array_flip(['success', 'status', 'previousStatus'])));
        $this->assertSame('cancelled', $this->model->statusOf($id));

        $source = $this->source();
        $this->assertSame([], $this->work($this->runner($source)), 'the worker must not claim a cancelled job');
        $this->assertSame(0, $source->scanned);
        $this->assertSame([], $this->files(), 'no file generated');
        $this->assertNull($this->model->find($id)['started_at']);
    }

    // ── TEST 2 — running ─────────────────────────────────────────────

    public function testCancellingARunningJobStopsTheScanAndDeletesThePartialFile(): void
    {
        $id = $this->job();

        // The user clicks "Annuler" once 50 000 rows are scanned (≈ 42 %).
        $source = $this->source(function (int $processed) use ($id): void {
            if ($processed === 50_000) {
                [$code, $json] = $this->cancelViaApi($id);
                $this->assertSame(200, $code);
                $this->assertSame('running', $json['previousStatus']);
                // The partial file still exists at this point: the API says it
                // will be removed, it does not claim it already is.
                $this->assertCount(1, glob($this->exportDir . DIRECTORY_SEPARATOR . '*.csv.tmp'));
            }
        });

        $this->assertSame([$id => ExportJobRunner::CANCELLED], $this->work($this->runner($source)));

        $this->assertSame(50_000, $source->scanned, 'the scan stops at the batch where the cancellation was seen');
        $row = $this->model->find($id);
        $this->assertSame('cancelled', $row['status']);
        // Counters frozen at the last write before the cancellation — never 100 %.
        $this->assertSame('25000', (string) $row['rows_processed']);
        $this->assertSame((string) self::ROWS, (string) $row['rows_total']);
        $this->assertSame(20, ExportJobModel::progress($row)['percent']);
        $this->assertNull($row['file_path']);
        $this->assertSame([], $this->files(), 'partial .tmp deleted, no final file');
    }

    public function testNoProgressIsWrittenAfterTheCancellation(): void
    {
        $id = $this->job();
        $this->model->claimNext();
        $this->model->updateProgress($id, 60_000, self::ROWS, 60_000);
        $this->model->cancel($id);

        $this->assertFalse($this->model->updateProgress($id, 90_000, self::ROWS, 90_000), 'false = stop');
        $this->assertSame('60000', (string) $this->model->find($id)['rows_processed']);
    }

    // ── TEST 3 — no download ─────────────────────────────────────────

    public function testACancelledJobOffersNoDownload(): void
    {
        $id     = $this->job();
        $source = $this->source(function (int $processed) use ($id): void {
            if ($processed === 25_000) {
                $this->cancelViaApi($id);
            }
        });
        $this->work($this->runner($source));

        $json = $this->show($id);
        $this->assertSame('cancelled', $json['status']);
        $this->assertArrayNotHasKey('downloadUrl', $json);
        $this->assertArrayNotHasKey('fileName', $json);
        $this->assertTrue($json['timing']['final']);

        $download = $this->controller(ExportJobController::class)->execute('download', $id);
        $this->assertSame(409, $download->response()->getStatusCode());
    }

    // ── TEST 4 — race: cancel right before done ──────────────────────

    public function testAJobCancelledAfterItsScanNeverBecomesDone(): void
    {
        $id = $this->job();
        // Cancelled after the last progress write, while the file is being
        // finalised: the worker's running -> done must fail.
        $source = $this->source(null, function () use ($id): void {
            $this->assertSame('running', $this->model->cancel($id));
        });

        $this->assertSame([$id => ExportJobRunner::CANCELLED], $this->work($this->runner($source)));

        $this->assertSame(self::ROWS, $source->scanned);
        $this->assertSame('cancelled', $this->model->statusOf($id));
        $this->assertNull($this->model->find($id)['file_path']);
        $this->assertSame([], $this->files(), 'the completed file of a cancelled job is deleted too');
    }

    public function testTerminalTransitionsOnlyApplyToARunningJob(): void
    {
        $id = $this->job();
        $this->model->claimNext();
        $this->model->cancel($id);

        $this->assertFalse($this->model->markDone($id, '/x.csv', 'x.csv', 10, 10));
        $this->assertFalse($this->model->markError($id, 'EXPJOB-X'));
        $this->assertSame('cancelled', $this->model->statusOf($id));
        $this->assertNull($this->model->cancel($id), 'cancelling twice changes nothing');
    }

    // ── TEST 5 / 6 — terminal jobs are never modified ────────────────

    public function testDoneFailedAndCancelledJobsCannotBeCancelled(): void
    {
        foreach (['done', 'error', 'cancelled'] as $status) {
            $id = $this->job(['status' => $status, 'finished_at' => '2026-09-30 10:00:00', 'file_path' => $status === 'done' ? '/x.csv' : null]);

            [$code, $json] = $this->cancelViaApi($id);

            $this->assertSame(409, $code, $status);
            $this->assertFalse($json['success'], $status);
            $this->assertSame('already_finished', $json['error'], $status);
            $this->assertSame($status, $json['status'], $status);

            $row = $this->model->find($id);
            $this->assertSame($status, $row['status'], $status);
            $this->assertSame('2026-09-30 10:00:00', $row['finished_at'], $status);
        }
    }

    public function testAnotherUsersJobCannotBeCancelled(): void
    {
        $id = $this->job(['requested_by' => 'bob', 'status' => 'running']);

        [$code] = $this->cancelViaApi($id);

        $this->assertSame(404, $code);
        $this->assertSame('running', $this->model->statusOf($id));
    }

    // ── TEST 7 / 8 — several jobs, cleanup limited to the cancelled one ─

    public function testCancellingOneJobLeavesTheOthersAndTheirFilesAlone(): void
    {
        // Files of other exports already in the directory (a finished one,
        // and another worker's file being written).
        file_put_contents($this->exportDir . DIRECTORY_SEPARATOR . 'customer_list_20260930_100000_aaaa0000.csv', "old\n");
        file_put_contents($this->exportDir . DIRECTORY_SEPARATOR . 'customer_list_20260930_100001_bbbb0000.csv.tmp', "other\n");

        $first  = $this->job();
        $second = $this->job();
        $third  = $this->job();

        $runs   = 0;
        $source = $this->source(function (int $processed) use ($first, &$runs): void {
            if ($processed === 0) {
                $runs++;
            }
            if ($runs === 1 && $processed === 75_000) {
                $this->cancelViaApi($first);
            }
        });

        $this->assertSame(
            [$first => ExportJobRunner::CANCELLED, $second => ExportJobRunner::DONE, $third => ExportJobRunner::DONE],
            $this->work($this->runner($source)),
            'the worker goes on with the next jobs',
        );

        $this->assertSame('cancelled', $this->model->statusOf($first));
        foreach ([$second, $third] as $id) {
            $row = $this->model->find($id);
            $this->assertSame('done', $row['status']);
            $this->assertFileExists($row['file_path']);
            $this->assertSame((string) self::ROWS, (string) $row['row_count']);
        }

        $files = $this->files();
        $this->assertContains('customer_list_20260930_100000_aaaa0000.csv', $files);
        $this->assertContains('customer_list_20260930_100001_bbbb0000.csv.tmp', $files);
        $this->assertCount(4, $files, '2 foreign files + the 2 finished exports; nothing from the cancelled job');
        $this->assertEmpty(array_filter($files, static fn ($f) => str_ends_with($f, '.tmp') && ! str_contains($f, 'bbbb0000')));
    }
}
