<?php

use App\Database\Migrations\AddProgressToExportJobs;
use App\Database\Migrations\CreateExportJobsTable;
use App\Database\Migrations\EnsureExportJobsSchema;
use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\ExportJobFailure;
use App\Services\Export\ExportJobRunner;
use App\Services\Export\ExportJobSchema;
use App\Services\Export\ReportsProgress;
use App\Services\Export\RowSource;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\TestLogger;

// Migration files are named <version>_<Class>.php (not PSR-4 loadable).
foreach (['2026-08-29-120000_CreateExportJobsTable', '2026-09-29-120000_AddProgressToExportJobs', '2026-10-01-120000_EnsureExportJobsSchema'] as $migration) {
    require_once APPPATH . "Database/Migrations/{$migration}.php";
}

/**
 * What must hold identically on a developer box and on the Linux server:
 *
 *   - `php spark migrate` always ends with the schema ExportJobModel writes
 *     (whatever the history: fresh DB, table created by hand, columns added
 *     by hand before the migration was recorded, a column missing);
 *   - the worker detects a missing column instead of failing every job;
 *   - a failing export ends 'error' with a short reference AND a full,
 *     secret-free report in the log (class, message, file, line, trace);
 *   - the error write itself failing never kills the worker;
 *   - jobs left 'running' by a dead worker are ended;
 *   - XLSX jobs report progress like CSV ones.
 *
 * @internal
 */
final class ExportJobRobustnessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // Same reason as ExportJobModelTest: the full app migration set is not
    // what is under test — the export_jobs migrations are run one by one.
    protected $migrate = false;

    private ExportJobModel $model;
    private string $exportDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forge()->dropTable('export_jobs', true);
        $this->db->resetDataCache();

        $this->exportDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_robust_' . bin2hex(random_bytes(4));
        mkdir($this->exportDir);
    }

    protected function tearDown(): void
    {
        $this->forge()->dropTable('export_jobs', true);

        App\Services\Snapshot\SnapshotStore::deleteTree($this->exportDir);

        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function forge(): CodeIgniter\Database\Forge
    {
        return Config\Database::forge();
    }

    private function migrateExportJobs(): void
    {
        (new CreateExportJobsTable($this->forge()))->up();
        (new AddProgressToExportJobs($this->forge()))->up();
        (new EnsureExportJobsSchema($this->forge()))->up();
        $this->db->resetDataCache();
        $this->model = new ExportJobModel();
    }

    /** @param array<string, mixed> $fields */
    private function job(array $fields = []): int
    {
        return $this->model->insert($fields + [
            'requested_by' => 'alice', 'format' => 'csv', 'filters' => '[]', 'status' => 'pending', 'row_count' => 3000,
        ], true);
    }

    /** @return list<string> every message logged at $level so far */
    private function logged(string $level): array
    {
        $logs = (new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue();

        return array_values(array_map(
            static fn (array $log): string => $log['message'],
            array_filter($logs, static fn (array $log): bool => strtolower((string) $log['level']) === $level),
        ));
    }

    /** Synthetic ReportsProgress source: $total rows, progress every $every rows. */
    private function source(int $total, int $every = 1000): RowSource
    {
        return new class ($total, $every) implements RowSource, ReportsProgress {
            private $listener;

            public function __construct(private int $total, private int $every)
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

            public function allowedValues(): App\Services\CustomersList\AllowedValues
            {
                throw new LogicException('unused');
            }

            public function count(FilterCriteria $criteria): int
            {
                return $this->total;
            }

            public function stream(FilterCriteria $criteria, callable $onRow): int
            {
                $row = array_fill_keys(CustomerListExportService::COLUMNS, 'x');
                $this->listener && ($this->listener)(0, $this->total, 0);
                for ($i = 1; $i <= $this->total; $i++) {
                    $row['CONTRACT'] = (string) $i;
                    $onRow($row);
                    if ($i % $this->every === 0) {
                        $this->listener && ($this->listener)($i, $this->total, $i);
                    }
                }
                $this->listener && ($this->listener)($this->total, $this->total, $this->total);

                return $this->total;
            }
        };
    }

    private function runner(callable $serviceFactory, ?ExportJobModel $model = null): ExportJobRunner
    {
        return new ExportJobRunner($model ?? $this->model, $serviceFactory, 0.0);
    }

    private function service(RowSource $source): CustomerListExportService
    {
        return new CustomerListExportService(exportDir: $this->exportDir, rowSource: $source);
    }

    // ── migrations / schema ─────────────────────────────────────────

    public function testMigrationsCreateEveryColumnTheModelWrites(): void
    {
        $this->migrateExportJobs();

        $this->assertSame([], ExportJobSchema::missingColumns($this->db));

        $model = new ExportJobModel();
        foreach ((new ReflectionProperty($model, 'allowedFields'))->getValue($model) as $field) {
            $this->assertContains($field, ExportJobSchema::requiredColumns(), "allowedFields.{$field} has no migration");
        }
    }

    public function testMigrationsAreIdempotent(): void
    {
        $this->migrateExportJobs();
        $this->job();

        // Re-running (migration row lost, columns added by hand...) must not fail.
        $this->migrateExportJobs();

        $this->assertSame([], ExportJobSchema::missingColumns($this->db));
        $this->assertSame(1, $this->db->table('export_jobs')->countAllResults());
    }

    public function testEnsureMigrationRestoresProgressColumnsOnAnOldTable(): void
    {
        // The server state behind "Unknown column 'rows_total'": the first
        // migration's table, progress migration never really applied.
        (new CreateExportJobsTable($this->forge()))->up();
        $this->db->resetDataCache();
        // (+ snapshot_version, added later still: AddSnapshotVersionToExportJobs)
        $this->assertSame(['snapshot_version', 'rows_total', 'rows_processed', 'rows_exported'], ExportJobSchema::missingColumns($this->db));

        (new EnsureExportJobsSchema($this->forge()))->up();

        $this->assertSame([], ExportJobSchema::missingColumns($this->db));
    }

    public function testEnsureMigrationCreatesAMissingTable(): void
    {
        $this->assertNull(ExportJobSchema::missingColumns($this->db));

        (new EnsureExportJobsSchema($this->forge()))->up();

        $this->assertSame([], ExportJobSchema::missingColumns($this->db));
    }

    public function testMissingColumnsIsNotFooledByTheConnectionCache(): void
    {
        $this->migrateExportJobs();
        $this->db->getFieldNames('export_jobs'); // warm CI's per-connection cache
        $this->forge()->dropColumn('export_jobs', 'rows_total');

        $this->assertSame(['rows_total'], ExportJobSchema::missingColumns($this->db));
    }

    // ── job lifecycle ───────────────────────────────────────────────

    public function testCreatedJobIsPendingWithUuidAndZeroProgress(): void
    {
        $this->migrateExportJobs();
        $job = $this->model->find($this->job());

        $this->assertSame('pending', $job['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $job['uuid']);
        $this->assertNull($job['rows_total']);
        $this->assertSame(0, (int) $job['rows_processed']);
        $this->assertSame(0, (int) $job['rows_exported']);
    }

    public function testUpdateProgressWritesAllCountersOnlyWhileRunning(): void
    {
        $this->migrateExportJobs();
        $id = $this->job();

        $this->assertFalse($this->model->updateProgress($id, 10, 100, 5), 'pending: not running yet');
        $this->model->claimNext();

        $this->assertTrue($this->model->updateProgress($id, 40, 100, -3));
        $job = $this->model->find($id);
        $this->assertSame([100, 40, 0], [(int) $job['rows_total'], (int) $job['rows_processed'], (int) $job['rows_exported']]);
    }

    public function testSuccessfulCsvJobInitialisesTotalAndEndsWithExactCounters(): void
    {
        $this->migrateExportJobs();
        $id      = $this->job();
        $model   = new class () extends ExportJobModel {
            public array $writes = [];

            public function updateProgress(int $id, int $processed, int $total, int $exported): bool
            {
                $this->writes[] = [$processed, $total, $exported];

                return parent::updateProgress($id, $processed, $total, $exported);
            }
        };

        $outcome = $this->runner(fn () => $this->service($this->source(3000)), $model)->run($model->claimNext());

        $this->assertSame(ExportJobRunner::DONE, $outcome);
        $this->assertSame([0, 3000, 0], $model->writes[0], 'rows_total known before the first row');
        $this->assertGreaterThan(2, count($model->writes), 'progress moves during the scan');
        $job = $this->model->find($id);
        $this->assertSame('done', $job['status']);
        $this->assertSame([3000, 3000, 3000], [(int) $job['rows_total'], (int) $job['rows_processed'], (int) $job['rows_exported']]);
        $this->assertSame(100, ExportJobModel::progress($job)['percent']);
    }

    public function testXlsxJobReportsProgressToo(): void
    {
        $this->migrateExportJobs();
        $id = $this->job(['format' => 'xlsx']);

        $outcome = $this->runner(fn () => $this->service($this->source(2000, 500)))->run($this->model->claimNext());

        $this->assertSame(ExportJobRunner::DONE, $outcome);
        $job = $this->model->find($id);
        $this->assertSame(2000, (int) $job['rows_total']);
        $this->assertSame(2000, (int) $job['rows_processed']);
        $this->assertFileExists($job['file_path']);
    }

    // ── failures ────────────────────────────────────────────────────

    public function testExceptionDuringExportEndsInErrorAndIsLoggedInFull(): void
    {
        $this->migrateExportJobs();
        $id  = $this->job();
        $job = $this->model->claimNext();

        // Fails before the first row, like job 45 (rows_total stays NULL).
        $failing = new class () implements RowSource {
            public function label(): string
            {
                return 'broken';
            }

            public function allowedValues(): App\Services\CustomersList\AllowedValues
            {
                throw new LogicException('unused');
            }

            public function count(FilterCriteria $criteria): int
            {
                return 0;
            }

            public function stream(FilterCriteria $criteria, callable $onRow): int
            {
                $this->open('db-password-in-an-argument');
            }

            private function open(string $secret): never
            {
                throw new RuntimeException('Snapshot illisible : Permission denied; password=hunter2 {env:database.default.password}', 13, new LogicException('cause racine'));
            }
        };

        $runner  = $this->runner(fn () => $this->service($failing));
        $outcome = $runner->run($job);

        $this->assertSame(ExportJobRunner::ERROR, $outcome);
        $row = $this->model->find($id);
        $this->assertSame('error', $row['status']);
        $this->assertMatchesRegularExpression('/^EXPJOB-\d{8}-\d{5}$/', $row['error_reference']);
        $this->assertNotNull($row['finished_at']);
        $this->assertNull($row['rows_total']);

        $reports = array_values(array_filter($this->logged('error'), static fn (string $m): bool => str_starts_with($m, '[EXPORT JOB ERROR]')));
        $this->assertCount(1, $reports);
        $report = $reports[0];
        foreach ([
            "job_id={$id}",
            "uuid={$job['uuid']}",
            "reference={$row['error_reference']}",
            'exception=RuntimeException',
            'message=Snapshot illisible : Permission denied',
            'file=' . __FILE__,
            'line=',
            'trace=',
            '->open()',
            'caused_by:',
            'exception=LogicException',
            'message=cause racine',
        ] as $expected) {
            $this->assertStringContainsString($expected, $report);
        }

        // No secret: trace arguments are never printed, credentials masked,
        // logger placeholders neutralised.
        $this->assertStringNotContainsString('db-password-in-an-argument', $report);
        $this->assertStringNotContainsString('hunter2', $report);
        $this->assertStringContainsString('password=***', $report);
        $this->assertStringNotContainsString('{env:', $report);

        // The same reference reaches the console summary.
        $this->assertSame($row['error_reference'], $runner->last['reference']);
        $this->assertStringContainsString('RuntimeException', $runner->last['summary']);
    }

    public function testFailingErrorWriteDoesNotKillTheWorker(): void
    {
        $this->migrateExportJobs();
        $this->job();
        $model = new class () extends ExportJobModel {
            public function markError(int $id, string $reference): bool
            {
                throw new RuntimeException('MySQL server has gone away');
            }
        };

        $runner  = $this->runner(static fn () => throw new RuntimeException('export cassé'), $model);
        $outcome = $runner->run($model->claimNext());

        $this->assertSame(ExportJobRunner::ERROR, $outcome);
        $this->assertStringContainsString('gone away', $runner->last['markErrorFailed']);
        $this->assertNotEmpty(array_filter($this->logged('critical'), static fn (string $m): bool => str_contains($m, 'gone away')));
    }

    public function testNextJobStillRunsAfterAFailure(): void
    {
        $this->migrateExportJobs();
        $first  = $this->job();
        $second = $this->job();
        $calls  = 0;

        $runner = $this->runner(function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('premier job cassé');
            }

            return $this->service($this->source(100));
        });

        $outcomes = [];
        while (($job = $this->model->claimNext()) !== null) {
            $outcomes[(int) $job['id']] = $runner->run($job);
        }

        $this->assertSame([$first => ExportJobRunner::ERROR, $second => ExportJobRunner::DONE], $outcomes);
    }

    public function testStaleRunningJobsAreEndedAndFreshOnesKept(): void
    {
        $this->migrateExportJobs();
        $old   = date('Y-m-d H:i:s', time() - 7200);
        $stale = $this->job(['status' => 'running', 'started_at' => $old]);
        $this->db->table('export_jobs')->where('id', $stale)->update(['updated_at' => $old]);
        $fresh   = $this->job(['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);
        $pending = $this->job();

        $failed = $this->model->failStale(3600, static fn (): string => 'EXPJOB-20261001-00042');

        $this->assertSame([$stale], array_column($failed, 'id'));
        $this->assertSame('error', $this->model->statusOf($stale));
        $this->assertSame('EXPJOB-20261001-00042', $this->model->find($stale)['error_reference']);
        $this->assertSame('running', $this->model->statusOf($fresh));
        $this->assertSame('pending', $this->model->statusOf($pending));
    }

    // ── worker state / doctor ───────────────────────────────────────

    public function testOnlyOneWatcherAtATime(): void
    {
        $dir    = $this->exportDir . DIRECTORY_SEPARATOR . 'worker';
        $first  = new App\Services\Export\ExportWorkerState($dir);
        $second = new App\Services\Export\ExportWorkerState($dir);

        $this->assertFalse($second->isWatcherRunning());
        $this->assertTrue($first->acquireLock());
        $this->assertFalse($second->acquireLock(), 'a second --watch must step aside');
        $this->assertTrue($second->isWatcherRunning());

        $first->release();
        $this->assertTrue($second->acquireLock());
        $second->release();

        $this->assertTrue($first->writeHeartbeat(['pid' => 1, 'code' => 'x']));
        $this->assertSame(['pid' => 1, 'code' => 'x'], $second->readHeartbeat());
        @unlink($first->heartbeatPath());
        @unlink($first->lockPath());
        @rmdir($dir);
    }

    public function testDoctorReportsAMissingColumnAsError(): void
    {
        (new CreateExportJobsTable($this->forge()))->up(); // no progress columns
        $this->db->resetDataCache();
        $workerDir = $this->exportDir . DIRECTORY_SEPARATOR . 'w';

        $results = (new App\Services\Export\ExportDoctor($this->db, new App\Services\Export\ExportWorkerState($workerDir), null, $this->exportDir))->run();
        $byCheck = array_column($results, null, 'check');

        $this->assertSame('ERROR', $byCheck['db.export_jobs']['level']);
        $this->assertStringContainsString('rows_total', $byCheck['db.export_jobs']['detail']);
        $this->assertSame('ERROR', App\Services\Export\ExportDoctor::worstLevel($results));
        // Missing but creatable by this user: a warning, and the doctor
        // (without --fix) creates nothing.
        $this->assertSame('WARNING', $byCheck['dir.exports']['level']);
        $this->assertDirectoryDoesNotExist($this->exportDir . DIRECTORY_SEPARATOR . 'uploads');

        // Never a secret in the output.
        $this->assertStringNotContainsString((string) config('Database')->default['password'] ?: "\0", json_encode($results));
    }

    public function testDoctorReportsAnOldFailedJobAsNonBlockingHistory(): void
    {
        $this->migrateExportJobs();
        $id = $this->job(['status' => 'error', 'error_reference' => 'EXPJOB-20261001-24093', 'finished_at' => '2026-10-01 10:00:00']);
        $workerDir = $this->exportDir . DIRECTORY_SEPARATOR . 'w';

        $results = (new App\Services\Export\ExportDoctor($this->db, new App\Services\Export\ExportWorkerState($workerDir), null, $this->exportDir, null, true))->run();
        $byCheck = array_column($results, null, 'check');

        $this->assertSame('INFO', $byCheck['jobs.last_error']['level']);
        $this->assertStringContainsString("#{$id} EXPJOB-20261001-24093", $byCheck['jobs.last_error']['detail']);
        $this->assertSame('history', App\Services\Export\ExportDoctor::category('jobs.last_error'));
        $this->assertSame('OK', $byCheck['db.export_jobs']['level']);

        // --fix created the missing worker directories (as this user) and
        // they pass the real write test.
        foreach (['dir.exports', 'dir.openspout_tmp', 'dir.worker_state', 'dir.logs', 'dir.cache'] as $check) {
            $this->assertSame('OK', $byCheck[$check]['level'], $check . ': ' . $byCheck[$check]['detail']);
            $this->assertStringContainsString('write_test=yes delete_test=yes subdir_test=yes', $byCheck[$check]['detail']);
        }

        // The old error is no reason for exit 1: nothing in history or
        // filesystem is an ERROR.
        $relevant = array_filter($results, static fn (array $r): bool => in_array(App\Services\Export\ExportDoctor::category($r['check']), ['history', 'filesystem'], true));
        $this->assertNotContains('ERROR', array_column($relevant, 'level'));
    }

    public function testFailureSanitizerMasksCredentials(): void
    {
        $this->assertSame('pwd=*** token: ***', ExportJobFailure::sanitize('pwd=abc token: "x y"'));
    }
}
