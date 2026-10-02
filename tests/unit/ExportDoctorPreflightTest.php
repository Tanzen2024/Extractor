<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\ExportDoctor;
use App\Services\Export\ExportWorkerState;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/XlsxScratchCleanupTest.php'; // ArrayRowSource

/**
 * Where OpenSpout writes (Config\Export), and the filesystem part of
 * export:doctor: `--preflight` (systemd ExecStartPre) creates what is
 * missing and fails on what the worker cannot use; the plain doctor never
 * creates anything.
 *
 * @internal
 */
final class ExportDoctorPreflightTest extends CIUnitTestCase
{
    private string $root;
    private string $savedOpenSpoutPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_doctor_' . bin2hex(random_bytes(5));
        mkdir($this->root);
        $this->savedOpenSpoutPath = config('Export')->openSpoutTempPath;
    }

    protected function tearDown(): void
    {
        config('Export')->openSpoutTempPath = $this->savedOpenSpoutPath;
        SnapshotStore::deleteTree($this->root);

        parent::tearDown();
    }

    private function doctor(?string $openSpout = null, bool $fix = false): ExportDoctor
    {
        return new ExportDoctor(null, new ExportWorkerState($this->root . DIRECTORY_SEPARATOR . 'export-worker'), null, $this->root, $openSpout, $fix);
    }

    /** @return array<string, array{level: string, check: string, detail: string}> */
    private static function byCheck(array $results): array
    {
        return array_column($results, null, 'check');
    }

    // ── A. path ──────────────────────────────────────────────────────

    public function testOpenSpoutTempPathDefaultsToWritableTmpOpenspoutTmp(): void
    {
        $config = new Config\Export();

        // Under this checkout's writable/ (Windows or Linux), no hard-coded /var/www.
        $this->assertSame(WRITEPATH . 'tmp' . DIRECTORY_SEPARATOR . 'openspout_tmp', $config->openSpoutTempPath());

        $config->openSpoutTempPath = '  ';
        $this->assertStringEndsWith('tmp/openspout_tmp', str_replace('\\', '/', $config->openSpoutTempPath()), 'empty override falls back to the default');

        $config->openSpoutTempPath = $this->root . DIRECTORY_SEPARATOR;
        $this->assertSame($this->root, $config->openSpoutTempPath(), 'no trailing separator');
    }

    public function testDoctorAndExportServiceUseTheConfiguredPath(): void
    {
        $tmp = $this->root . DIRECTORY_SEPARATOR . 'custom_openspout';
        config('Export')->openSpoutTempPath = $tmp;

        $this->assertSame($tmp, (new ExportDoctor())->openSpoutTempPath());
        $this->assertSame($tmp, (new ExportDoctor())->workerDirectories()['openspout_tmp']);

        // The service creates it on demand (parent writable) and leaves no scratch.
        $seen    = [];
        $service = new CustomerListExportService(
            exportDir: $this->root . DIRECTORY_SEPARATOR . 'exports',
            rowSource: new ArrayRowSource(30, onRow: static function () use (&$seen, $tmp): void {
                $seen = glob($tmp . DIRECTORY_SEPARATOR . 'export_*', GLOB_ONLYDIR) ?: $seen;
            }),
        );
        $meta = $service->exportXlsx(FilterCriteria::none());

        $this->assertSame(30, $meta['rows']);
        $this->assertCount(1, $seen, 'OpenSpout scratch lived under the configured path');
        $this->assertSame([], glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [], 'and is gone after the export');
        @unlink($meta['path']);
    }

    // ── B / E. preflight creates, then is idempotent ─────────────────

    public function testPreflightCreatesMissingDirectoriesThenStaysOk(): void
    {
        $results = $this->doctor()->preflight();
        $checks  = self::byCheck($results);

        foreach (['dir.logs', 'dir.exports', 'dir.openspout_tmp', 'dir.cache', 'dir.worker_state'] as $check) {
            $this->assertSame('OK', $checks[$check]['level'], $checks[$check]['detail']);
            $this->assertStringContainsString('exists=yes (créé)', $checks[$check]['detail']);
        }
        $this->assertDirectoryExists($this->root . '/tmp/openspout_tmp');
        $this->assertNotSame(ExportDoctor::ERROR, ExportDoctor::worstLevel($results));

        // Every restart runs it again: no error, nothing re-created.
        $again = self::byCheck($this->doctor()->preflight());
        $this->assertSame('OK', $again['dir.openspout_tmp']['level']);
        $this->assertStringNotContainsString('(créé)', $again['dir.openspout_tmp']['detail']);
        $this->assertStringContainsString('write_test=yes delete_test=yes', $again['dir.openspout_tmp']['detail']);
    }

    public function testPreflightRecreatesADeletedOpenSpoutDirectory(): void
    {
        $this->doctor()->preflight();
        rmdir($this->root . '/tmp/openspout_tmp');

        $checks = self::byCheck($this->doctor()->preflight());

        $this->assertSame('OK', $checks['dir.openspout_tmp']['level']);
        $this->assertDirectoryExists($this->root . '/tmp/openspout_tmp');
    }

    // ── C. not usable ────────────────────────────────────────────────

    public function testPreflightFailsWhenTheWorkerCannotUseTheOpenSpoutDirectory(): void
    {
        // A file where the directory should be: unusable for any user, any OS.
        mkdir($this->root . '/tmp');
        file_put_contents($this->root . '/tmp/openspout_tmp', '');

        $results = $this->doctor()->preflight();
        $check   = self::byCheck($results)['dir.openspout_tmp'];

        $this->assertSame(ExportDoctor::ERROR, $check['level']);
        $this->assertSame(ExportDoctor::ERROR, ExportDoctor::worstLevel($results), 'systemd ExecStartPre gets exit 1');
        $this->assertStringContainsString("n'est pas un répertoire", $check['detail']);
        $this->assertStringContainsString('supprimer ou renommer ce fichier', $check['detail'], 'tells what to do');
        $this->assertSame('', file_get_contents($this->root . '/tmp/openspout_tmp'), 'the file itself is never touched');
    }

    public function testRootOwnedLikeDirectoryIsAnErrorOnlyIfReallyNotWritable(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('needs POSIX permissions and a non-root user.');
        }

        $dir = $this->root . '/tmp/openspout_tmp';
        mkdir($dir, 0775, true);
        chmod($dir, 0555); // what root:root 0755 is for www-data

        $check = self::byCheck($this->doctor()->preflight())['dir.openspout_tmp'];
        chmod($dir, 0775);

        $this->assertSame(ExportDoctor::ERROR, $check['level']);
        $this->assertStringContainsString('write_test=no', $check['detail']);
    }

    // ── plain doctor: never creates ──────────────────────────────────

    public function testPlainDoctorCreatesNothingAndOnlyWarnsAboutCreatableDirectories(): void
    {
        $doctor = $this->doctor();
        $method = new ReflectionMethod($doctor, 'checkDirectories');
        $method->invoke($doctor);
        $results = (new ReflectionProperty($doctor, 'results'))->getValue($doctor);
        $check   = self::byCheck($results)['dir.openspout_tmp'];

        $this->assertSame(ExportDoctor::WARNING, $check['level']);
        $this->assertStringContainsString('--fix', $check['detail']);
        $this->assertDirectoryDoesNotExist($this->root . '/tmp');
    }

    public function testCategoriesSeparateHistoryFromCurrentProblems(): void
    {
        $this->assertSame('history', ExportDoctor::category('jobs.last_error'));
        $this->assertSame('history', ExportDoctor::category('jobs.status'));
        $this->assertSame('filesystem', ExportDoctor::category('dir.openspout_tmp'));
        $this->assertSame('filesystem', ExportDoctor::category('disk.free'));
        $this->assertSame('worker', ExportDoctor::category('worker.watch'));
        $this->assertSame('configuration', ExportDoctor::category('db.migrations'));

        // INFO (an old failed job) never makes the result an error or a warning.
        $this->assertSame(ExportDoctor::OK, ExportDoctor::worstLevel([
            ['level' => ExportDoctor::OK, 'check' => 'dir.logs', 'detail' => ''],
            ['level' => ExportDoctor::INFO, 'check' => 'jobs.last_error', 'detail' => ''],
        ]));
    }
}
