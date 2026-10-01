<?php

use App\Controllers\ExportJobController;
use App\Models\ExportJobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * GET /exports/{id} — the JSON the dashboard polls: same shape (`progress`,
 * `timing`) for every status, plus a browser-safe `failure` for 'error'.
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

    public function testErrorPayloadHasReferenceSafeMessageAndSameShape(): void
    {
        $json = $this->show($this->job(['status' => 'error', 'error_reference' => 'EXPJOB-20260929-00001', 'rows_processed' => 10]));

        $this->assertSame('error', $json['status']);
        $this->assertSame('EXPJOB-20260929-00001', $json['reference']); // kept for older pages
        $this->assertSame('EXPJOB-20260929-00001', $json['failure']['reference']);
        $this->assertStringContainsString('EXPJOB-20260929-00001', $json['failure']['message']);
        // Same shape as the other statuses; frozen counters, no total = 0 %.
        $this->assertSame(['percent' => 0, 'processed' => 10, 'total' => null, 'exported' => 0], $json['progress']);
        $this->assertTrue($json['timing']['final']);
        $this->assertArrayNotHasKey('downloadUrl', $json);
        // Never the exception: no trace, class or server path reaches the browser.
        // ('error' itself must stay absent: the dashboard reads it as a failed poll.)
        $this->assertArrayNotHasKey('error', $json);
        $body = json_encode($json);
        foreach (['trace', 'exception', 'Exception', '.php', 'file_path'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function testEveryStatusHasTheSameCoreShape(): void
    {
        foreach (['pending', 'running', 'done', 'error', 'cancelled'] as $status) {
            $json = $this->show($this->job([
                'status' => $status, 'rows_total' => 3_305_219, 'rows_processed' => 950_000, 'rows_exported' => 700_000,
                'started_at' => $status === 'pending' ? null : date('Y-m-d H:i:s', time() - 26),
                'finished_at' => in_array($status, ['done', 'error', 'cancelled'], true) ? date('Y-m-d H:i:s') : null,
            ]));

            foreach (['id', 'status', 'format', 'rowCount', 'progress', 'timing'] as $key) {
                $this->assertArrayHasKey($key, $json, "{$status}: {$key}");
            }
            $this->assertSame($status, $json['status']);
            $this->assertSame(['percent', 'processed', 'total', 'exported'], array_keys($json['progress']), $status);
            $this->assertSame(['waitSeconds', 'elapsedSeconds', 'final'], array_keys($json['timing']), $status);

            $expected = ['pending' => 0, 'running' => 28, 'done' => 100, 'error' => 28, 'cancelled' => 28][$status];
            $this->assertSame($expected, $json['progress']['percent'], $status);
        }
    }

    public function testRunningWithZeroTotalNeverDividesByZero(): void
    {
        $json = $this->show($this->job(['status' => 'running', 'rows_total' => 0, 'rows_processed' => 5]));

        $this->assertSame(0, $json['progress']['percent']);
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
