<?php

use App\Controllers\DashboardController;
use App\Database\Migrations\AddProgressToExportJobs;
use App\Database\Migrations\AddSnapshotVersionToExportJobs;
use App\Database\Migrations\CreateExportJobsTable;
use App\Database\Migrations\EnsureExportJobsSchema;
use App\Models\ExportJobModel;
use App\Services\Export\ExportJobRunner;
use App\Services\OracleExtractionService;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;
use Tests\Support\Snapshot\SnapshotFixture;

foreach (['2026-08-29-120000_CreateExportJobsTable', '2026-09-29-120000_AddProgressToExportJobs', '2026-10-01-120000_EnsureExportJobsSchema', '2026-10-02-120000_AddSnapshotVersionToExportJobs'] as $migration) {
    require_once APPPATH . "Database/Migrations/{$migration}.php";
}

/**
 * The whole user flow on the real controller, model, runner and snapshot
 * store — filter options, filter validation, count, KPIs / charts,
 * segmentation counts, table, sync export, queued export, worker — with an
 * instrumented Oracle client: not one connection attempt
 * (OracleExtractionService::$connectionAttempts stays 0). No snapshot / no
 * index: 503 everywhere, still without Oracle. A job counted on V1 is
 * generated from V1 after V2 (and V3) were activated.
 *
 * @internal
 */
final class SnapshotUserFlowTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate = false;

    private SnapshotFixture $fx;
    private string $exportDir;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $forge = Config\Database::forge();
        $forge->dropTable('export_jobs', true);
        (new CreateExportJobsTable($forge))->up();
        (new AddProgressToExportJobs($forge))->up();
        (new EnsureExportJobsSchema($forge))->up();
        (new AddSnapshotVersionToExportJobs($forge))->up();
        $this->db->resetDataCache();

        $this->fx        = new SnapshotFixture();
        $this->exportDir = $this->fx->baseDir . DIRECTORY_SEPARATOR . 'exports';
        // The controller builds its own Config\Snapshot / Config\Oracle: point
        // them at the throw-away snapshot area, and make 3+ rows "large".
        $this->setEnv('snapshot.baseDir', $this->fx->baseDir);
        $this->setEnv('snapshot.publishedLink', $this->fx->config->publishedLink);
        $this->setEnv('extraction.exportSyncMaxRows', '2');

        session()->set('username', 'alice');
        OracleExtractionService::$connectionAttempts = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        Config\Database::forge()->dropTable('export_jobs', true);
        session()->remove('username');
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $this->savedEnv[$key] ??= getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /** @return list<array<string, string>> */
    private function rows(int $n, string $region): array
    {
        return array_map(static fn (int $i): array => SnapshotFixture::row($i, [
            'REGION' => $region,
            'STATUS' => $i % 3 === 0 ? 'INACTIVE.' : 'ACTIVE',
            'METER'  => $i % 2 === 0 ? 'PREPAID' : 'POSTPAID',
            'SEGMENTATION' => $i % 2 === 0 ? '1-Stable' : '1 PERFECT',
            'CONTRACT' => (string) (($region === 'DRC' ? 100000 : 200000) + $i),
        ]), range(1, $n));
    }

    private function installVersion(int $rows, string $region): string
    {
        sleep(1); // version ids are second-based
        $r = $this->fx->install($this->rows($rows, $region));
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        return (string) $r['version'];
    }

    /**
     * @param array<string, mixed> $params GET (or POST when $post)
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, array $params = [], bool $post = false): array
    {
        $config  = new App();
        $request = new IncomingRequest($config, new SiteURI($config, 'dashboard/' . $method), null, new UserAgent());
        if ($post) {
            $request = $request->withMethod('POST');
            $request->setGlobal('post', $params);
        } else {
            $request->setGlobal('get', $params);
        }

        // A fresh response per call, as per real HTTP request.
        $response = $this->withRequest($request)->withResponse(new Response($config))
            ->controller(DashboardController::class)->execute($method)->response();

        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }

    public function testTheWholeUserFlowNeverTouchesOracle(): void
    {
        $v1 = $this->installVersion(6, 'DRC');
        $filters = ['status' => ['ACTIVE'], 'meter' => ['PREPAID', 'POSTPAID']];

        [$code, $options] = $this->call('filterOptions');
        $this->assertSame(200, $code);
        $this->assertSame(['DRC'], array_column($options['regions'], 'value'));

        [$code, $count] = $this->call('count', $filters);
        $this->assertSame([200, 4, $v1], [$code, $count['count'], $count['snapshot']['id']]);

        [$code, $stats] = $this->call('stats', $filters);
        $this->assertSame([200, 4, 4], [$code, $stats['totalRows'], $stats['kpis']['actifs']['value']]);
        $this->assertSame([['value' => 'DRC', 'count' => 4]], $stats['charts']['region']);

        [$code, $segs] = $this->call('segmentationCounts', $filters + ['segmentation' => ['1-Stable']]);
        $this->assertSame(200, $code);
        $this->assertSame(4, array_sum(array_column($segs['counts'], 'count')), 'segmentation filter ignored for its own counts');

        [$code, $rows] = $this->call('rows', $filters + ['sort' => 'CONTRACT', 'dir' => 'desc', 'per_page' => 20]);
        $this->assertSame([200, 4, 4], [$code, $rows['total'], count($rows['data'])]);
        $this->assertSame('100005', $rows['data'][0]['CONTRACT']);

        [$code] = $this->call('count', ['region' => ['NOT_IN_SNAPSHOT']]);
        $this->assertSame(422, $code, 'a value the version does not hold is refused');

        // Small export (2 rows <= exportSyncMaxRows): generated at once, from the snapshot.
        [$code, $sync] = $this->call('export', ['format' => 'csv', 'status' => ['ACTIVE'], 'meter' => ['PREPAID']], true);
        $this->assertSame([200, 'sync', 2], [$code, $sync['mode'], $sync['rows']]);
        parse_str((string) parse_url($sync['downloadUrl'], PHP_URL_QUERY), $q);
        @unlink(WRITEPATH . 'uploads/exports/' . $q['f']);

        // Large export: queued job, then the worker.
        [$code, $async] = $this->call('export', ['format' => 'csv'] + $filters, true);
        $this->assertSame([200, 'async', 4], [$code, $async['mode'], $async['count']]);
        $job = (new ExportJobModel())->find($async['jobId']);
        $this->assertSame($v1, $job['snapshot_version'], 'the job records the version it was counted on');

        $result = $this->runWorker();
        $this->assertSame(ExportJobRunner::DONE, $result['status']);
        $this->assertSame(4, (int) $result['job']['rows_exported']);

        $this->assertSame(0, OracleExtractionService::$connectionAttempts, 'not a single Oracle connection attempt during the user flow');
    }

    public function testAJobCountedOnV1IsGeneratedFromV1AfterNewerVersionsWereActivated(): void
    {
        $v1 = $this->installVersion(5, 'DRC');

        // 04:59 — queued on V1 (all 5 rows).
        [, $async] = $this->call('export', ['format' => 'csv'], true);
        $this->assertSame([5, $v1], [$async['count'], (new ExportJobModel())->find($async['jobId'])['snapshot_version']]);

        // 05:00 — refreshes activate V2 then V3 (keepVersions = 2 would
        // normally delete V1 at V3: the pending job pins it).
        $v2 = $this->installVersion(9, 'DRE');
        $v3 = $this->installVersion(11, 'DRE');
        $this->assertSame($v3, $this->fx->store->active()->id);
        $this->assertDirectoryExists($this->fx->store->versionDir($v1), 'still needed by the queued job');

        // 05:01 — the worker generates the job: V1's 5 rows, never V2/V3's.
        $result = $this->runWorker();
        $this->assertSame(ExportJobRunner::DONE, $result['status']);
        $this->assertSame(5, (int) $result['job']['rows_exported']);
        $this->assertSame(5, (int) $result['job']['rows_total'], "V1's row count, not V3's 11");

        $csv = (string) file_get_contents($result['job']['file_path']);
        $this->assertStringContainsString('100001', $csv);
        $this->assertStringNotContainsString('200001', $csv);
        $this->assertNotSame($v2, $v1);
        $this->assertSame(0, OracleExtractionService::$connectionAttempts);
    }

    /**
     * XLSX, through the PRODUCTION worker wiring (ExportJobRunner without an
     * injected factory, as `export:process` builds it): the job queued on V1
     * is generated from V1 after V2 was activated.
     */
    public function testAQueuedXlsxJobIsGeneratedFromItsVersionByTheProductionRunner(): void
    {
        $v1 = $this->installVersion(4, 'DRC');
        [, $async] = $this->call('export', ['format' => 'xlsx'], true);
        $this->assertSame([4, $v1], [$async['count'], (new ExportJobModel())->find($async['jobId'])['snapshot_version']]);

        $this->installVersion(9, 'DRE');

        $jobs   = new ExportJobModel();
        $status = (new ExportJobRunner($jobs))->run($jobs->claimNext());
        $job    = $jobs->find($async['jobId']);
        $reader = null;

        try {
            $this->assertSame(ExportJobRunner::DONE, $status);
            $this->assertSame([4, 4], [(int) $job['rows_exported'], (int) $job['rows_total']]);
            $this->assertStringEndsWith('.xlsx', $job['file_name']);

            $reader = new OpenSpout\Reader\XLSX\Reader();
            $reader->open($job['file_path']);
            $contracts = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $contracts[] = (string) $row->toArray()[array_search('CONTRACT', \App\Services\CustomerListExportService::COLUMNS, true)];
                }
            }
            $this->assertSame(['CONTRACT', '100001', '100002', '100003', '100004'], $contracts, "V1's rows only");
        } finally {
            $reader?->close(); // Windows: an open file cannot be deleted
            @unlink((string) $job['file_path']);
        }
        $this->assertSame(0, OracleExtractionService::$connectionAttempts);
    }

    public function testAJobWhoseVersionIsGoneFailsCleanlyWithoutAnotherSource(): void
    {
        $v1 = $this->installVersion(5, 'DRC');
        [, $async] = $this->call('export', ['format' => 'csv'], true);
        $this->installVersion(7, 'DRE');
        SnapshotStore::deleteTree($this->fx->store->versionDir($v1)); // deleted by hand

        $result = $this->runWorker();

        $this->assertSame(ExportJobRunner::ERROR, $result['status']);
        $this->assertNull($result['job']['file_path']);
        $this->assertSame(0, OracleExtractionService::$connectionAttempts);
    }

    public function testNoSnapshotMeans503EverywhereAndStillNoOracle(): void
    {
        foreach (['filterOptions', 'count', 'stats', 'rows', 'segmentationCounts'] as $method) {
            [$code, $body] = $this->call($method);
            $this->assertSame(503, $code, $method);
            $this->assertSame('snapshot_unavailable', $body['error'], $method);
            $this->assertNotEmpty($body['reference']);
        }
        [$code, $body] = $this->call('export', ['format' => 'csv'], true);
        $this->assertSame([503, 'snapshot_unavailable'], [$code, $body['error']]);

        $this->assertSame(0, OracleExtractionService::$connectionAttempts, 'no snapshot is never a reason to read Oracle');
    }

    public function testAnActiveVersionWithoutItsIndexIs503NotAnotherSource(): void
    {
        $this->installVersion(3, 'DRC');
        unlink((new SnapshotIndex($this->fx->config))->path($this->fx->store->active()));

        [$code, $body] = $this->call('stats');
        $this->assertSame([503, 'snapshot_unavailable'], [$code, $body['error']]);
        $this->assertSame(0, OracleExtractionService::$connectionAttempts);
    }

    /**
     * Claims the next job and runs it with the production pinning logic
     * (ExportJobRunner::snapshotService), files in the test's own folders.
     *
     * @return array{status: string, job: array<string, mixed>}
     */
    private function runWorker(): array
    {
        $jobs  = new ExportJobModel();
        $job   = $jobs->claimNext();
        $this->assertNotNull($job, 'a job to run');
        $store = $this->fx->store;
        $dir   = $this->exportDir;

        $status = (new ExportJobRunner($jobs, static fn (array $j) => ExportJobRunner::snapshotService($j, $store, $dir, $dir . DIRECTORY_SEPARATOR . 'tmp')))->run($job);

        return ['status' => $status, 'job' => $jobs->find($job['id'])];
    }
}
