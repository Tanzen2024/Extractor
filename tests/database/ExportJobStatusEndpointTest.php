<?php

use App\Controllers\ExportJobController;
use App\Models\ExportJobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * GET /exports/{id} — the JSON the dashboard polls: `progress` for
 * pending / running / done, error payload unchanged.
 *
 * @internal
 */
final class ExportJobStatusEndpointTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    // Same reason as ExportJobModelTest: forge only this table.
    protected $migrate = false;

    private ExportJobModel $model;

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

        $this->model = new ExportJobModel();
        session()->set('username', 'alice');
    }

    protected function tearDown(): void
    {
        Config\Database::forge()->dropTable('export_jobs', true);
        session()->remove('username');
        parent::tearDown();
    }

    /** @param array<string, mixed> $fields */
    private function job(array $fields): int
    {
        return $this->model->insert($fields + ['requested_by' => 'alice', 'format' => 'csv', 'row_count' => 3_302_841], true);
    }

    /** @return array<string, mixed> */
    private function show(int $id): array
    {
        $result = $this->controller(ExportJobController::class)->execute('show', $id);

        return json_decode($result->response()->getBody(), true);
    }

    public function testPendingReportsZero(): void
    {
        $json = $this->show($this->job(['status' => 'pending']));

        $this->assertSame('pending', $json['status']);
        $this->assertSame(['percent' => 0, 'processed' => 0, 'total' => null, 'exported' => 0], $json['progress']);
        $this->assertArrayNotHasKey('downloadUrl', $json);
    }

    public function testRunningReportsTheScanPercentage(): void
    {
        $json = $this->show($this->job([
            'status' => 'running', 'rows_total' => 3_302_841, 'rows_processed' => 2_378_046, 'rows_exported' => 2_378_046,
        ]));

        $this->assertSame(['percent' => 72, 'processed' => 2_378_046, 'total' => 3_302_841, 'exported' => 2_378_046], $json['progress']);
        $this->assertArrayNotHasKey('downloadUrl', $json);
    }

    public function testDoneReports100AndKeepsTheDownloadFields(): void
    {
        $json = $this->show($this->job([
            'status' => 'done', 'rows_total' => 3_302_841, 'rows_processed' => 3_302_841, 'rows_exported' => 3_302_841,
            'file_path' => '/x/customer_list_x.csv', 'file_name' => 'customer_list_x.csv', 'file_size' => 815_000_000,
        ]));

        $this->assertSame(100, $json['progress']['percent']);
        $this->assertStringEndsWith('/download', $json['downloadUrl']);
        $this->assertSame('customer_list_x.csv', $json['fileName']);
        $this->assertSame(3_302_841, $json['rowCount']);
    }

    public function testErrorPayloadIsUnchanged(): void
    {
        $json = $this->show($this->job(['status' => 'error', 'error_reference' => 'EXPJOB-20260929-00001', 'rows_processed' => 10]));

        $this->assertSame('error', $json['status']);
        $this->assertSame('EXPJOB-20260929-00001', $json['reference']);
        $this->assertArrayNotHasKey('progress', $json);
        $this->assertArrayNotHasKey('downloadUrl', $json);
    }

    public function testTimingPendingIsQueueTimeOnly(): void
    {
        $json = $this->show($this->job(["status" => "pending"]));

        $this->assertIsInt($json["timing"]["waitSeconds"]);
        $this->assertNull($json["timing"]["elapsedSeconds"]);
        $this->assertFalse($json["timing"]["final"]);
    }

    public function testTimingRunningCountsFromStartedAt(): void
    {
        $json = $this->show($this->job(["status" => "running", "started_at" => date("Y-m-d H:i:s", time() - 102)]));

        $this->assertEqualsWithDelta(102, $json["timing"]["elapsedSeconds"], 2);
        $this->assertNull($json["timing"]["waitSeconds"]);
        $this->assertFalse($json["timing"]["final"]);
    }

    public function testTimingDoneAndErrorAreFixedTotals(): void
    {
        foreach (["done", "error"] as $status) {
            $json = $this->show($this->job([
                "status" => $status, "started_at" => "2026-09-30 16:42:41", "finished_at" => "2026-09-30 16:50:01",
            ]));

            $this->assertSame(["waitSeconds" => null, "elapsedSeconds" => 440, "final" => true], $json["timing"], $status);
        }
    }

    public function testTimingIsNullWithoutTimestamps(): void
    {
        $this->assertSame(
            ["waitSeconds" => null, "elapsedSeconds" => null, "final" => false],
            App\Models\ExportJobModel::timing(["status" => "running", "started_at" => null])
        );
    }

    public function testAnotherUsersJobIsNotVisible(): void
    {
        $id     = $this->job(['status' => 'running', 'requested_by' => 'bob']);
        $result = $this->controller(ExportJobController::class)->execute('show', $id);

        $this->assertSame(404, $result->response()->getStatusCode());
    }
}
