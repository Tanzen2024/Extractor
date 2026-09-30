<?php

use App\Models\ExportJobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Lifecycle of an asynchronous export job: claim → done / error, plus the
 * "claim only touches a pending row" rule that keeps two workers from
 * grabbing the same job.
 *
 * @internal
 */
final class ExportJobModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // The full app migration set is not replayable from scratch (an older
    // migration parses a since-replaced app/Models/Extractor.sql), so this
    // test forges only the one table it needs instead of $migrate-ing.
    protected $migrate = false;

    private ExportJobModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = Config\Database::forge();
        $forge->dropTable('export_jobs', true);
        $this->createTable($forge);

        $this->model = new ExportJobModel();
    }

    protected function tearDown(): void
    {
        Config\Database::forge()->dropTable('export_jobs', true);
        parent::tearDown();
    }

    private function createTable(\CodeIgniter\Database\Forge $forge): void
    {
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
    }

    private function makeJob(string $status = 'pending'): int
    {
        return $this->model->insert([
            'requested_by' => 'admin',
            'format'       => 'csv',
            'filters'      => json_encode(['regions' => ['DCUD']]),
            'status'       => $status,
            'row_count'    => 1234,
        ], true);
    }

    public function testInsertAssignsAUuid(): void
    {
        $id  = $this->makeJob();
        $row = $this->model->find($id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['uuid']);
        $this->assertSame('pending', $row['status']);
    }

    public function testClaimNextTakesTheOldestPendingJobAndMarksItRunning(): void
    {
        $first  = $this->makeJob();
        $second = $this->makeJob();

        $claimed = $this->model->claimNext();

        $this->assertSame($first, (int) $claimed['id']);
        $this->assertSame('running', $claimed['status']);
        $this->assertNotNull($claimed['started_at']);

        // The second call gets the next pending one, not the same job again.
        $next = $this->model->claimNext();
        $this->assertSame($second, (int) $next['id']);

        // Nothing left.
        $this->assertNull($this->model->claimNext());
    }

    public function testClaimNextIgnoresJobsNotInPending(): void
    {
        $this->makeJob('running');
        $this->makeJob('done');
        $this->makeJob('error');

        $this->assertNull($this->model->claimNext());
    }

    public function testMarkDoneRecordsTheFile(): void
    {
        $id = $this->makeJob();
        $this->model->claimNext();

        $this->model->markDone($id, '/tmp/customer_list_x.csv', 'customer_list_x.csv', 2048, 999);

        $row = $this->model->find($id);
        $this->assertSame('done', $row['status']);
        $this->assertSame('customer_list_x.csv', $row['file_name']);
        $this->assertSame('2048', (string) $row['file_size']);
        $this->assertSame('999', (string) $row['row_count']);
        $this->assertNotNull($row['finished_at']);
    }

    public function testMarkErrorStoresAReference(): void
    {
        $id = $this->makeJob();
        $this->model->claimNext(); // only a running job can fail

        $this->model->markError($id, 'EXPJOB-20260829-00042');

        $row = $this->model->find($id);
        $this->assertSame('error', $row['status']);
        $this->assertSame('EXPJOB-20260829-00042', $row['error_reference']);
    }

    public function testUpdateProgressStoresTheCountersOfARunningJob(): void
    {
        $id = $this->makeJob();
        $this->model->claimNext();

        $this->model->updateProgress($id, 1_651_421, 3_302_841, 100_000);

        $row = $this->model->find($id);
        $this->assertSame('1651421', (string) $row['rows_processed']);
        $this->assertSame('3302841', (string) $row['rows_total']);
        $this->assertSame('100000', (string) $row['rows_exported']);
        $this->assertSame(50, ExportJobModel::progress($row)['percent']);
    }

    public function testUpdateProgressNeverTouchesAJobThatIsNotRunning(): void
    {
        foreach (['pending', 'done', 'error'] as $status) {
            $id = $this->makeJob($status);

            $this->model->updateProgress($id, 10, 20, 5);

            $row = $this->model->find($id);
            $this->assertSame($status, $row['status']);
            $this->assertSame('0', (string) $row['rows_processed'], $status);
            $this->assertNull($row['rows_total'], $status);
        }
    }

    public function testANewJobStartsAtZeroProgress(): void
    {
        $row = $this->model->find($this->makeJob());

        $this->assertSame(['percent' => 0, 'processed' => 0, 'total' => null, 'exported' => 0], ExportJobModel::progress($row));
    }

    public function testFiltersJsonRoundTrips(): void
    {
        $id  = $this->makeJob();
        $row = $this->model->find($id);

        $this->assertSame(['regions' => ['DCUD']], json_decode($row['filters'], true));
    }
}
