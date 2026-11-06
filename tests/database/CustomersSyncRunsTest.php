<?php

use App\Database\Migrations\CreateCustomersSyncRunsTable;
use App\Models\CustomersSyncRunModel;
use App\Services\Refresh\SyncRunRecorder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * customers_sync_runs: what a run leaves behind, including when it dies.
 *
 * @internal
 */
final class CustomersSyncRunsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = false;
    protected $migrate = false;

    private CustomersSyncRunModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        require_once APPPATH . 'Database/Migrations/2026-11-06-090000_CreateCustomersSyncRunsTable.php';

        $forge = Database::forge($this->DBGroup ?? 'tests');
        $forge->dropTable('customers_sync_runs', true);
        (new CreateCustomersSyncRunsTable($forge))->up();

        $this->model = new CustomersSyncRunModel($this->db);
    }

    protected function tearDown(): void
    {
        Database::forge($this->DBGroup ?? 'tests')->dropTable('customers_sync_runs', true);
        parent::tearDown();
    }

    private function recorder(array &$logs = []): SyncRunRecorder
    {
        return new SyncRunRecorder(fn () => $this->model, static function (string $level, string $message) use (&$logs): void {
            $logs[] = "{$level}: {$message}";
        });
    }

    public function testMigrationIsIdempotent(): void
    {
        (new CreateCustomersSyncRunsTable(Database::forge($this->DBGroup ?? 'tests')))->up();

        $this->assertTrue($this->db->tableExists('customers_sync_runs'));
    }

    public function testSuccessfulRunIsRecordedWithItsFigures(): void
    {
        $recorder = $this->recorder();
        $recorder->start('scheduled', '20261003T092809_b6cf9aa6');

        $row = $this->model->latest();
        $this->assertSame(CustomersSyncRunModel::STATUS_RUNNING, $row['status']);
        $this->assertNull($row['finished_at']);

        $recorder->finish(CustomersSyncRunModel::STATUS_SUCCESS, ['rows_read' => 3328478, 'rows_added' => 21541, 'snapshot_version' => '20261106T100000_aaaaaaaa']);

        $row = $this->model->latest();
        $this->assertSame(CustomersSyncRunModel::STATUS_SUCCESS, $row['status']);
        $this->assertSame('scheduled', $row['trigger_type']);
        $this->assertSame('20261003T092809_b6cf9aa6', $row['previous_version']);
        $this->assertSame(3328478, (int) $row['rows_read']);
        $this->assertNotNull($row['finished_at']);
        $this->assertNotNull($row['duration_seconds']);
    }

    public function testFailedRunKeepsItsReasonAndIsNeverASuccess(): void
    {
        $recorder = $this->recorder();
        $recorder->start('manual', null);
        $recorder->finish(CustomersSyncRunModel::STATUS_FAILED, [
            'error_count'   => 1,
            'error_reason'  => 'oracle_or_internal_error',
            'error_message' => str_repeat('ORA-03113 ', 500),
        ]);

        $row = $this->model->latest();
        $this->assertSame(CustomersSyncRunModel::STATUS_FAILED, $row['status']);
        $this->assertSame('oracle_or_internal_error', $row['error_reason']);
        $this->assertSame(1, (int) $row['error_count']);
        $this->assertLessThanOrEqual(2000, mb_strlen($row['error_message']));
        $this->assertNull($row['rows_read']);
    }

    public function testRunLeftRunningByADeadProcessIsClosedAsFailedByTheNextRun(): void
    {
        $dead = $this->recorder();
        $dead->start('scheduled', null); // killed here: finish() never runs
        $deadId = (int) $this->model->latest()['id'];

        $logs = [];
        $this->recorder($logs)->start('scheduled', null);

        $row = $this->model->find($deadId);
        $this->assertSame(CustomersSyncRunModel::STATUS_FAILED, $row['status']);
        $this->assertSame('interrupted', $row['error_reason']);
        $this->assertNotNull($row['finished_at']);
        $this->assertSame(CustomersSyncRunModel::STATUS_RUNNING, $this->model->latest()['status'], 'the new run itself stays open');
        $this->assertStringContainsString('interrompue', implode("\n", $logs));
    }

    public function testSuccessOfFindsOnlyTheSuccessfulRunOfThatVersion(): void
    {
        $recorder = $this->recorder();
        $recorder->start('scheduled', null);
        $recorder->finish(CustomersSyncRunModel::STATUS_SUCCESS, ['snapshot_version' => '20261106T103103_04547b1d']);
        $failed = $this->recorder();
        $failed->start('scheduled', null);
        $failed->finish(CustomersSyncRunModel::STATUS_FAILED);

        $this->assertNotNull($this->model->successOf('20261106T103103_04547b1d'));
        $this->assertNull($this->model->successOf('20261003T092809_b6cf9aa6'));
    }

    public function testHistoryIsNewestFirstAndBounded(): void
    {
        foreach (['success', 'failed', 'skipped'] as $status) {
            $recorder = $this->recorder();
            $recorder->start('scheduled', null);
            $recorder->finish($status);
        }

        $history = $this->model->history(2);

        $this->assertCount(2, $history);
        $this->assertSame('skipped', $history[0]['status']);
        $this->assertSame('failed', $history[1]['status']);
    }

    public function testUnavailableDatabaseNeverStopsTheRun(): void
    {
        $logs     = [];
        $recorder = new SyncRunRecorder(static fn () => throw new RuntimeException('MariaDB down'), static function (string $level, string $message) use (&$logs): void {
            $logs[] = "{$level}: {$message}";
        });

        $recorder->start('scheduled', null);
        $recorder->finish(CustomersSyncRunModel::STATUS_SUCCESS);

        $this->assertStringContainsString('MariaDB down', implode("\n", $logs));
    }
}
