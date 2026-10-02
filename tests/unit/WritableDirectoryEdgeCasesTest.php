<?php

use App\Services\Export\ExportDoctor;
use App\Services\Export\ExportWorkerState;
use App\Services\Export\WritableDirectory;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Edge cases of the directory check behind export:doctor / --preflight that
 * a plain temp folder cannot reproduce: a failure in the middle of the probe
 * (cleanup in finally), a symlinked openspout_tmp, and the real server layout
 * root:www-data 2775 seen from www-data (only when the suite runs as root).
 *
 * @internal
 */
final class WritableDirectoryEdgeCasesTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_wdir_edge_' . bin2hex(random_bytes(5));
        mkdir($this->root);
        ProbeFailingStream::reset();
    }

    protected function tearDown(): void
    {
        if (in_array(ProbeFailingStream::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(ProbeFailingStream::SCHEME);
        }
        // The link alone, before the tree (never recurse through it).
        $link = $this->root . DIRECTORY_SEPARATOR . 'openspout_tmp';
        if (WritableDirectory::linkTarget($link) !== null) {
            @rmdir($link) || @unlink($link);
        }
        SnapshotStore::deleteTree($this->root);

        parent::tearDown();
    }

    private function registerFailingStream(string $failOn): string
    {
        stream_wrapper_register(ProbeFailingStream::SCHEME, ProbeFailingStream::class);
        ProbeFailingStream::$failOn = $failOn;
        ProbeFailingStream::$dirs   = ['dir' => true];

        return ProbeFailingStream::SCHEME . '://dir';
    }

    // ── cleanup after an exception ───────────────────────────────────

    public function testProbeFileIsRemovedWhenWritingItThrows(): void
    {
        $dir = $this->registerFailingStream('write');

        try {
            WritableDirectory::probe($dir);
            $this->fail('the exception thrown while writing must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure: write', $e->getMessage());
        }

        $this->assertCount(1, ProbeFailingStream::$created, 'the probe file had been created');
        $this->assertStringContainsString('.doctor_probe_', ProbeFailingStream::$created[0]);
        $this->assertSame([], ProbeFailingStream::$files, 'and the finally block removed it');
        $this->assertSame(ProbeFailingStream::$created, ProbeFailingStream::$unlinked);
    }

    public function testProbeFileIsRemovedByFinallyWhenTheFirstDeleteThrows(): void
    {
        $dir = $this->registerFailingStream('unlink-once');

        try {
            WritableDirectory::probe($dir);
            $this->fail('the exception thrown by unlink() must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure: unlink', $e->getMessage());
        }

        $this->assertSame([], ProbeFailingStream::$files, 'second unlink() in finally removed the probe file');
        $this->assertSame(['dir' => true], ProbeFailingStream::$dirs, 'no probe sub-folder left either');
    }

    public function testProbeSubFolderIsRemovedWhenRemovingItThrows(): void
    {
        $dir = $this->registerFailingStream('rmdir-once');

        try {
            WritableDirectory::probe($dir);
            $this->fail('the exception thrown by rmdir() must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure: rmdir', $e->getMessage());
        }

        $this->assertSame([], ProbeFailingStream::$files);
        $this->assertSame(['dir' => true], ProbeFailingStream::$dirs, 'finally removed the probe sub-folder');
    }

    // ── symlinked openspout_tmp ──────────────────────────────────────

    private function symlinkOpenSpoutTmp(): array
    {
        $target = $this->root . DIRECTORY_SEPARATOR . 'elsewhere';
        $link   = $this->root . DIRECTORY_SEPARATOR . 'openspout_tmp';
        mkdir($target);

        if (! @symlink($target, $link) && PHP_OS_FAMILY === 'Windows') {
            // symlink() needs a privilege on Windows, a junction does not.
            exec('cmd /c mklink /J "' . $link . '" "' . $target . '" >NUL 2>&1');
        }
        if (WritableDirectory::linkTarget($link) === null) {
            $this->markTestSkipped('cannot create a symlink / junction here.');
        }

        return [$link, $target];
    }

    public function testSymlinkedDirectoryIsRefusedAndNothingIsWrittenThroughIt(): void
    {
        [$link, $target] = $this->symlinkOpenSpoutTmp();

        $result = WritableDirectory::probe($link);

        $this->assertFalse(WritableDirectory::usable($result));
        $this->assertStringContainsString('lien symbolique', (string) $result['error']);
        $this->assertStringContainsString($target, (string) $result['error'], 'shows the real path behind the link');
        $this->assertFalse($result['write_test'], 'no probe written through the link');
        $this->assertSame(['.', '..'], scandir($target));
    }

    public function testDoctorReportsASymlinkedOpenSpoutTmpAsAnError(): void
    {
        [$link] = $this->symlinkOpenSpoutTmp();

        $doctor = new ExportDoctor(null, new ExportWorkerState($this->root . DIRECTORY_SEPARATOR . 'export-worker'), null, $this->root, $link);
        $check  = array_column($doctor->preflight(), null, 'check')['dir.openspout_tmp'];

        $this->assertSame(ExportDoctor::ERROR, $check['level']);
        $this->assertStringContainsString('lien symbolique', $check['detail']);
        $this->assertNotNull(WritableDirectory::linkTarget($link), 'the link itself is never replaced');
    }

    public function testLinkedParentIsAcceptedOnlyTheManagedLeafIsChecked(): void
    {
        // writable/ (or APP_DIR) behind a link is a legitimate layout.
        $realParent = $this->root . DIRECTORY_SEPARATOR . 'elsewhere';
        mkdir($realParent . DIRECTORY_SEPARATOR . 'tmp', 0775, true);
        $link = $this->root . DIRECTORY_SEPARATOR . 'openspout_tmp'; // reused as "writable" link, cleaned in tearDown
        if (! @symlink($realParent, $link) && PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J "' . $link . '" "' . $realParent . '" >NUL 2>&1');
        }
        if (WritableDirectory::linkTarget($link) === null) {
            $this->markTestSkipped('cannot create a symlink / junction here.');
        }

        $result = WritableDirectory::probe($link . DIRECTORY_SEPARATOR . 'tmp');

        $this->assertNull($result['error'], (string) $result['error']);
        $this->assertTrue(WritableDirectory::usable($result));
        $this->assertSame(['.', '..'], scandir($realParent . DIRECTORY_SEPARATOR . 'tmp'));
    }

    // ── root:www-data 2775, as www-data ──────────────────────────────

    /**
     * The production layout that must be OK: a directory owned by root but in
     * group www-data with 2775 — www-data writes through the group bits.
     * Needs root (to chown and to switch the effective uid/gid), POSIX and a
     * www-data account: run the suite with `sudo` on a Linux box.
     */
    public function testRootOwnedGroupWritableDirectoryIsOkForTheGroupMember(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('needs Linux + posix + running as root (sudo vendor/bin/phpunit ...).');
        }
        $www = posix_getpwnam('www-data');
        if ($www === false) {
            $this->markTestSkipped('no www-data account on this machine.');
        }

        chmod($this->root, 0755);
        $dir = $this->root . DIRECTORY_SEPARATOR . 'openspout_tmp';
        mkdir($dir);
        chown($dir, 0);
        chgrp($dir, $www['gid']);
        chmod($dir, 02775);
        clearstatcache();
        $this->assertSame('2775', substr(sprintf('%o', fileperms($dir)), -4));

        posix_setegid($www['gid']);
        posix_seteuid($www['uid']);

        try {
            $result = WritableDirectory::probe($dir);
        } finally {
            posix_seteuid(0);
            posix_setegid(0);
        }

        $this->assertStringContainsString('www-data', $result['user']);
        $this->assertStringContainsString('root', $result['owner'], 'owner is root ...');
        $this->assertTrue(WritableDirectory::usable($result), '... and that is fine: ' . WritableDirectory::describe($result));
        $this->assertSame(['.', '..'], scandir($dir), 'no probe left behind');
    }
}

/**
 * In-memory filesystem whose operations can throw, to prove the probe's
 * finally block cleans up whatever it created before the failure.
 *
 * @internal
 */
final class ProbeFailingStream
{
    public const SCHEME = 'probefail';

    /** write | unlink-once | rmdir-once */
    public static string $failOn = '';

    /** @var array<string, string> */
    public static array $files = [];

    /** @var array<string, true> */
    public static array $dirs = [];

    /** @var list<string> */
    public static array $created = [];

    /** @var list<string> */
    public static array $unlinked = [];

    /** @var resource|null */
    public $context;

    private string $path = '';

    public static function reset(): void
    {
        self::$failOn  = '';
        self::$files   = self::$dirs = [];
        self::$created = self::$unlinked = [];
    }

    private static function key(string $url): string
    {
        return rtrim(str_replace('\\', '/', substr($url, strlen(self::SCHEME . '://'))), '/');
    }

    public function stream_open(string $url, string $mode): bool
    {
        $key = self::key($url);
        if (str_contains($mode, 'x') && (isset(self::$files[$key]) || isset(self::$dirs[$key]))) {
            return false;
        }
        self::$files[$key] = '';
        self::$created[]   = $key;
        $this->path        = $key;

        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$failOn === 'write') {
            throw new RuntimeException('simulated failure: write');
        }
        self::$files[$this->path] .= $data;

        return strlen($data);
    }

    public function stream_close(): void
    {
    }

    public function stream_stat(): array
    {
        return $this->url_stat(self::SCHEME . '://' . $this->path, 0) ?: [];
    }

    public function unlink(string $url): bool
    {
        if (self::$failOn === 'unlink-once') {
            self::$failOn = '';

            throw new RuntimeException('simulated failure: unlink');
        }
        $key = self::key($url);
        unset(self::$files[$key]);
        self::$unlinked[] = $key;

        return true;
    }

    public function mkdir(string $url, int $mode, int $options): bool
    {
        self::$dirs[self::key($url)] = true;

        return true;
    }

    public function rmdir(string $url, int $options): bool
    {
        if (self::$failOn === 'rmdir-once') {
            self::$failOn = '';

            throw new RuntimeException('simulated failure: rmdir');
        }
        unset(self::$dirs[self::key($url)]);

        return true;
    }

    public function url_stat(string $url, int $flags): array|false
    {
        $key = self::key($url);
        if (isset(self::$dirs[$key])) {
            return ['mode' => 040777, 'size' => 0, 'uid' => 0, 'gid' => 0];
        }
        if (isset(self::$files[$key])) {
            return ['mode' => 0100666, 'size' => strlen(self::$files[$key]), 'uid' => 0, 'gid' => 0];
        }

        return false;
    }
}
