<?php

use App\Controllers\DashboardController;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Snapshot\SnapshotRowSource;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * Dashboard ↔ snapshot ↔ export coherence: in snapshot mode the dashboard's
 * filter options, its row count (GET /dashboard/count) and the export all
 * read the SAME active snapshot with the SAME filter logic — so the count a
 * user sees is the count they export. No Oracle is involved: the snapshot is
 * a test fixture (Config\Snapshot is pointed at it through the environment).
 *
 * @internal
 */
final class DashboardSnapshotCountTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    private SnapshotFixture $fx;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = new SnapshotFixture();
        $this->setEnv('snapshot.baseDir', $this->fx->baseDir);
        $this->setEnv('snapshot.exportSource', 'snapshot');
        $this->setEnv('snapshot.publishedLink', $this->fx->config->publishedLink);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        $this->fx->cleanup();
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $this->envBackup[$key] ??= getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /** 30 rows: ZZ-NORTH x12 (8 ACTIVE), ZZ-SOUTH x18 — region names that exist nowhere in Oracle. */
    private function install(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = SnapshotFixture::row($i, [
                'REGION' => $i <= 12 ? 'ZZ-NORTH' : 'ZZ-SOUTH',
                'STATUS' => $i <= 8 ? 'ACTIVE' : 'INACTIVE',
            ]);
        }
        $this->fx->install($rows);
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function get(string $method, array $query = []): array
    {
        $_GET = $query;
        $request = service('request');
        $request->setGlobal('get', $query);

        $result = $this->withRequest($request)->controller(DashboardController::class)->execute($method);

        return [$result->response()->getStatusCode(), json_decode((string) $result->response()->getBody(), true)];
    }

    public function testUnfilteredCountIsTheSnapshotTotalWithItsRealMetadata(): void
    {
        $this->install();

        [$status, $json] = $this->get('count');

        $this->assertSame(200, $status);
        $this->assertSame(30, $json['count']);
        $this->assertSame('snapshot', $json['source']);
        $this->assertSame(30, $json['snapshot']['rows']);
        $this->assertSame((new SnapshotRowSource(store: $this->fx->store))->snapshot()->id, $json['snapshot']['id']);
        $this->assertArrayHasKey('generatedAt', $json['snapshot']);
        // Only dates actually present in the meta; the fixture manifest has
        // no source_updated_at, so none is made up.
        $this->assertNull($json['snapshot']['sourceUpdatedAt']);
    }

    public function testFilteredCountEqualsTheExportCountAndTheExportedRows(): void
    {
        $this->install();

        [$status, $json] = $this->get('count', ['region' => ['ZZ-NORTH'], 'status' => ['ACTIVE']]);
        $this->assertSame(200, $status);
        $this->assertSame(8, $json['count']);

        // What POST /dashboard/export counts (SnapshotRowSource::count) and
        // what the CSV export actually writes, for the same request filters.
        $source   = new SnapshotRowSource(store: $this->fx->store, cache: new MockCache());
        $criteria = FilterCriteria::fromRequest(['region' => ['ZZ-NORTH'], 'status' => ['ACTIVE']], $source->allowedValues());
        $meta     = (new CustomerListExportService(exportDir: $this->fx->baseDir . '/exports', rowSource: $source))->exportCsv($criteria);

        $this->assertSame($json['count'], $source->count($criteria));
        $this->assertSame($json['count'], $meta['rows']);
        $this->assertSame($json['count'] + 1, count(file($meta['path'], FILE_SKIP_EMPTY_LINES))); // + header
    }

    public function testFilterOptionsComeFromTheSnapshot(): void
    {
        $this->install();

        [$status, $json] = $this->get('filterOptions');

        $this->assertSame(200, $status);
        $this->assertEqualsCanonicalizing(
            [['value' => 'ZZ-NORTH', 'count' => 12], ['value' => 'ZZ-SOUTH', 'count' => 18]],
            $json['regions'],
        );
    }

    public function testAValueAbsentFromTheSnapshotIsRejected(): void
    {
        $this->install();

        [$status, $json] = $this->get('count', ['region' => ['DCUD']]); // real Oracle region, not in this snapshot

        $this->assertSame(422, $status);
        $this->assertSame('filter', $json['error']);
    }

    public function testNoValidSnapshotMeansNoCountRatherThanAnotherBase(): void
    {
        // Nothing installed.
        [$status, $json] = $this->get('count');

        $this->assertSame(503, $status);
        $this->assertSame('snapshot_unavailable', $json['error']);
    }

    public function testARejectedRefreshLeavesTheDashboardOnThePreviousSnapshot(): void
    {
        $this->install();
        $before = $this->get('count')[1];

        // A truncated 50-row delivery (manifest of the full file): refused.
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = SnapshotFixture::row($i, ['REGION' => 'ZZ-NORTH']);
        }
        $full = SnapshotFixture::csv($rows);
        $this->fx->deliver(substr($full, 0, (int) (strlen($full) / 2)), [], $full);
        $result = (new \App\Services\Snapshot\SnapshotInstaller($this->fx->store))->install();
        $this->assertSame(\App\Services\Snapshot\SnapshotInstaller::RESULT_REJECTED, $result['result']);

        [$status, $after] = $this->get('count');
        $this->assertSame(200, $status);
        $this->assertSame(30, $after['count']);                        // never 50, never a partial figure
        $this->assertSame($before['snapshot']['id'], $after['snapshot']['id']);
        $this->assertSame(12, $this->get('count', ['region' => ['ZZ-NORTH']])[1]['count']);
    }

    public function testANewlyActivatedSnapshotIsUsedAtOnce(): void
    {
        $this->install();
        $this->assertSame(30, $this->get('count')[1]['count']);

        // customers:refresh / snapshot:install publishing a bigger version.
        $rows = [];
        for ($i = 1; $i <= 35; $i++) {
            $rows[] = SnapshotFixture::row($i, ['REGION' => 'ZZ-NORTH']);
        }
        $this->fx->install($rows);

        [, $json] = $this->get('count');
        $this->assertSame(35, $json['count']);
        $this->assertSame(35, $json['snapshot']['rows']);
    }
}
