<?php

use App\Services\Export\WritableDirectory;
use App\Services\Snapshot\SnapshotStore;
use PHPUnit\Framework\TestCase;

/**
 * The directory check behind export:doctor and the worker preflight: judged
 * on a real create/delete as the current user, never leaves anything behind,
 * never touches existing files, creates only when asked.
 *
 * @internal
 */
final class WritableDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_wdir_' . bin2hex(random_bytes(5));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            @chmod($this->root . DIRECTORY_SEPARATOR . 'ro', 0775);
            SnapshotStore::deleteTree($this->root);
        }
    }

    /** @return list<string> */
    private function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    public function testExistingWritableDirectoryPassesTheRealWriteDeleteTest(): void
    {
        $result = WritableDirectory::probe($this->root);

        $this->assertTrue(WritableDirectory::usable($result));
        $this->assertTrue($result['exists']);
        $this->assertFalse($result['created']);
        $this->assertTrue($result['write_test']);
        $this->assertTrue($result['delete_test']);
        $this->assertTrue($result['subdir_test']);
        $this->assertNull($result['error']);
        $this->assertSame([], $this->entries($this->root), 'no probe file / folder left behind');
    }

    public function testRepeatedProbesLeaveNoFileAndNeverTouchExistingOnes(): void
    {
        file_put_contents($this->root . '/customer_list_20261002_000000_abcd.xlsx', 'EXPORT');
        mkdir($this->root . '/export_20261002_000000_0123456789ab');

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue(WritableDirectory::usable(WritableDirectory::probe($this->root)));
        }

        $this->assertSame(['customer_list_20261002_000000_abcd.xlsx', 'export_20261002_000000_0123456789ab'], $this->entries($this->root));
        $this->assertSame('EXPORT', file_get_contents($this->root . '/customer_list_20261002_000000_abcd.xlsx'));
    }

    public function testMissingDirectoryIsReportedButNotCreatedByDefault(): void
    {
        $dir    = $this->root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'openspout_tmp';
        $result = WritableDirectory::probe($dir);

        $this->assertFalse(WritableDirectory::usable($result));
        $this->assertSame('absent', $result['error']);
        $this->assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'tmp');
        $this->assertTrue(WritableDirectory::creatable($dir), 'parent writable: the worker could create it');
    }

    public function testMissingDirectoryIsCreatedWhenAskedAndAllowed(): void
    {
        $dir    = $this->root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'openspout_tmp';
        $result = WritableDirectory::probe($dir, true);

        $this->assertTrue($result['created']);
        $this->assertTrue(WritableDirectory::usable($result));
        $this->assertDirectoryExists($dir);
        $this->assertSame([], $this->entries($dir));

        // Second time: already there, nothing to create, no error.
        $again = WritableDirectory::probe($dir, true);
        $this->assertFalse($again['created']);
        $this->assertTrue(WritableDirectory::usable($again));
    }

    public function testAFileInPlaceOfTheDirectoryIsAnError(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'openspout_tmp';
        file_put_contents($path, 'not a dir');

        $result = WritableDirectory::probe($path, true);

        $this->assertFalse(WritableDirectory::usable($result));
        $this->assertStringContainsString("n'est pas un répertoire", $result['error']);
        $this->assertSame('not a dir', file_get_contents($path), 'the file is left untouched');
    }

    public function testDirectoryThatCannotBeCreatedIsAnError(): void
    {
        // Parent is a regular file: mkdir fails on every OS, as any user.
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'tmp', '');
        $dir = $this->root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'openspout_tmp';

        $result = WritableDirectory::probe($dir, true);

        $this->assertFalse(WritableDirectory::usable($result));
        $this->assertStringContainsString('impossible à créer', $result['error']);
        $this->assertFalse(WritableDirectory::creatable($dir));
    }

    public function testExistingButNotWritableDirectoryIsAnError(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod cannot make a directory read-only on Windows (covered by the "cannot be created" case).');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes everywhere.');
        }

        $dir = $this->root . DIRECTORY_SEPARATOR . 'ro';
        mkdir($dir);
        chmod($dir, 0555);

        $result = WritableDirectory::probe($dir);

        $this->assertTrue($result['exists']);
        $this->assertFalse($result['write_test']);
        $this->assertFalse(WritableDirectory::usable($result));
        $this->assertStringContainsString('NON utilisable par', $result['error']);
    }

    public function testDescribeShowsTheRealBehaviourNotJustTheOwner(): void
    {
        $line = WritableDirectory::describe(WritableDirectory::probe($this->root));

        $this->assertStringContainsString('exists=yes writable=yes write_test=yes delete_test=yes subdir_test=yes user=', $line);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringNotContainsString('mode=', $line, 'POSIX mode is meaningless on Windows');
        }
    }
}
