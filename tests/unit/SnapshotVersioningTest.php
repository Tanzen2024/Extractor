<?php

use App\Commands\CustomersRefresh;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * A snapshot version is complete only with its DuckDB index, built from its
 * own CSV before activation; versions an export still needs are never
 * pruned; a newer version never changes what an older one answers.
 *
 * @internal
 */
final class SnapshotVersioningTest extends CIUnitTestCase
{
    private SnapshotFixture $fx;

    protected function setUp(): void
    {
        parent::setUp();
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }
        $this->fx = new SnapshotFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private function rows(int $n, string $region = 'DRC'): array
    {
        return array_map(static fn (int $i): array => SnapshotFixture::row($i, ['REGION' => $region]), range(1, $n));
    }

    /** Installs $n rows through the real installer; version ids are second-based. */
    private function install(int $n, string $region = 'DRC', ?callable $pinned = null, ?callable $buildIndex = null): array
    {
        sleep(1);
        $this->fx->deliver(SnapshotFixture::csv($this->rows($n, $region)));

        return (new SnapshotInstaller($this->fx->store, $pinned ?? static fn (): array => [], $buildIndex))->install();
    }

    public function testTheIndexIsBuiltFromTheVersionBeforeItBecomesActive(): void
    {
        $r = $this->install(5);

        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
        $active = $this->fx->store->active();
        $index  = new SnapshotIndex($this->fx->config);
        $this->assertFileExists($index->path($active));
        $this->assertSame(dirname($active->csvPath), dirname($index->path($active)), 'the index lives inside its version');
        $this->assertSame(5, $index->engine($active)->count(FilterCriteria::none()));
        $this->assertSame([], glob(dirname($active->csvPath) . '/*.tmp') ?: [], 'no temporary file left');
    }

    public function testAFailedIndexBuildRejectsTheVersionAndKeepsThePreviousOneActive(): void
    {
        $first = $this->install(3)['version'];

        $r = $this->install(4, 'DRE', null, static function (): never {
            throw new SnapshotException('index_failed', 'disque plein (simulé)');
        });

        $this->assertSame(SnapshotInstaller::RESULT_REJECTED, $r['result']);
        $this->assertSame('index_failed', $r['reason']);
        $this->assertSame($first, $this->fx->store->active()->id, 'previous version still active');
        $this->assertDirectoryDoesNotExist($this->fx->store->versionDir((string) $r['version']));
        $this->assertSame(3, (new SnapshotIndex($this->fx->config))->engine($this->fx->store->active())->count(FilterCriteria::none()));
    }

    public function testPruneNeverDeletesAVersionAQueuedExportStillNeeds(): void
    {
        $v1 = $this->install(3)['version'];
        $v2 = $this->install(4)['version'];
        // keepVersions = 2: installing v3 would normally delete v1 — a pending job pins it.
        $v3 = $this->install(5, 'DRC', static fn (): array => [$v1])['version'];

        $this->assertSame($v3, $this->fx->store->active()->id);
        $this->assertDirectoryExists($this->fx->store->versionDir($v1), 'pinned by an export job');
        $this->assertDirectoryExists($this->fx->store->versionDir($v2));

        // Once nothing pins it any more, the next install prunes it.
        $v4 = $this->install(6)['version'];
        $this->assertDirectoryDoesNotExist($this->fx->store->versionDir($v1));
        $this->assertSame([$v4, $v3], $this->fx->store->versions());
    }

    /**
     * The exact scenario: V10 used by a queued job, V11 active, prune() —
     * with keepVersions = 1, so only the job's pin can keep V10.
     */
    public function testV10UsedByAJobSurvivesPruneWhileV11IsActive(): void
    {
        $this->fx->cleanup();
        $this->fx = new SnapshotFixture(keepVersions: 1);

        $v10 = $this->install(3)['version'];
        $v11 = $this->install(4, 'DRC', static fn (): array => [$v10])['version'];

        $this->assertSame($v11, $this->fx->store->active()->id);
        $this->assertDirectoryExists($this->fx->store->versionDir($v10), 'kept by the installer: the job pins it');

        $this->assertSame([], $this->fx->store->prune([$v10]));
        $this->assertDirectoryExists($this->fx->store->versionDir($v10));
        $this->assertSame(3, (new SnapshotIndex($this->fx->config))->engine($this->fx->store->load($v10))->count(FilterCriteria::none()), 'still fully usable (CSV + index)');

        $this->assertSame([], $this->fx->store->prune(null), 'pins unknown: nothing deleted');
        $this->assertDirectoryExists($this->fx->store->versionDir($v10));

        // Job done: no pin any more -> deleted; the active version never is.
        $this->assertSame([$v10], $this->fx->store->prune([]));
        $this->assertDirectoryDoesNotExist($this->fx->store->versionDir($v10));
        $this->assertSame($v11, $this->fx->store->active()->id);
    }

    public function testUnknownPinsMeanNoVersionIsDeleted(): void
    {
        $v1 = $this->install(3)['version'];
        $this->install(4);
        $this->install(5, 'DRC', static function (): never {
            throw new RuntimeException('base de données injoignable (simulé)');
        });

        $this->assertDirectoryExists($this->fx->store->versionDir($v1), 'pins unreadable: nothing pruned');
        $this->assertCount(3, $this->fx->store->versions());
    }

    public function testAnOlderVersionKeepsAnsweringAfterANewerOneIsActivated(): void
    {
        $this->install(3, 'DRC');
        $v1    = $this->fx->store->active();
        $index = new SnapshotIndex($this->fx->config);
        $old   = $index->engine($v1);
        $oldRows = new SnapshotRowSource(snapshot: $v1, store: $this->fx->store);

        $this->install(8, 'DRE');

        $this->assertNotSame($v1->id, $this->fx->store->active()->id);
        // Same version, same answers — dashboard engine and export alike.
        $this->assertSame(3, $old->count(FilterCriteria::none()));
        $this->assertSame(['DRC' => 3], $old->distributions(FilterCriteria::none())['region']);
        $this->assertSame(3, $oldRows->count(FilterCriteria::none()));
        $this->assertSame(8, $index->engine($this->fx->store->active())->count(FilterCriteria::none()));
    }

    public function testConcurrentReadersOfOneIndex(): void
    {
        $this->install(50);
        $db = (new SnapshotIndex($this->fx->config))->path($this->fx->store->active());

        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open([$this->fx->config->duckdbBinary, '-readonly', '-json', $db], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], 'SELECT COUNT(*) AS n FROM cl, range(200);');
            fclose($pipes[0]);
            $procs[$i] = [$procs[$i], $pipes];
        }
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $this->assertStringContainsString('"n":10000', str_replace(' ', '', $out));
        }
    }

    public function testCatchUpRunSkipsOnlyWhenTodaysVersionIsAlreadyActive(): void
    {
        $this->assertNull(CustomersRefresh::activeExtractedToday($this->fx->config), 'no snapshot: refresh');

        $this->install(3); // fixture manifest generated_at = 2026-09-25T06:12:03+01:00
        $id = $this->fx->store->active()->id;

        $this->assertSame($id, CustomersRefresh::activeExtractedToday($this->fx->config, '2026-09-25'));
        $this->assertNull(CustomersRefresh::activeExtractedToday($this->fx->config, '2026-09-26'), "yesterday's version: refresh");
    }
}
