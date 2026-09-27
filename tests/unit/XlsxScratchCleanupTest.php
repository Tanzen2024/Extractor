<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\RowSource;
use App\Services\Snapshot\SnapshotStore;
use PHPUnit\Framework\TestCase;

/**
 * OpenSpout scratch isolation (the "rmdir(.../worksheets-temp): Directory
 * not empty" family): each XLSX export works in its own
 * <openSpoutTempDir>/export_<rand>/ folder, outside the final export dir,
 * and that folder is gone after the export — succeeded or failed — so a
 * failed export can never block the next one.
 *
 * @internal
 */
final class XlsxScratchCleanupTest extends TestCase
{
    private string $root;
    private string $exportDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->root      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_xlsx_' . bin2hex(random_bytes(5));
        $this->exportDir = $this->root . DIRECTORY_SEPARATOR . 'exports';
        $this->tmpDir    = $this->root . DIRECTORY_SEPARATOR . 'openspout';
        mkdir($this->exportDir, 0777, true);
    }

    protected function tearDown(): void
    {
        SnapshotStore::deleteTree($this->root);
    }

    private function service(RowSource $source): CustomerListExportService
    {
        return new CustomerListExportService(exportDir: $this->exportDir, openSpoutTempDir: $this->tmpDir, rowSource: $source);
    }

    /** @return list<string> */
    private function scratchDirs(): array
    {
        return glob($this->tmpDir . DIRECTORY_SEPARATOR . 'export_*', GLOB_ONLYDIR) ?: [];
    }

    public function testSuccessfulExportLeavesNoScratchAndKeepsFinalFileApart(): void
    {
        $meta = $this->service(new ArrayRowSource(500))->exportXlsx(FilterCriteria::none());

        $this->assertSame(500, $meta['rows']);
        $this->assertFileExists($meta['path']);
        $this->assertSame([], $this->scratchDirs(), 'scratch folder removed after success');
        $this->assertSame([basename($meta['path'])], array_values(array_diff(scandir($this->exportDir), ['.', '..'])), 'only the final file in the export dir');
    }

    public function testFailedExportLeavesNeitherScratchNorPartialFile(): void
    {
        try {
            $this->service(new ArrayRowSource(500, failAfter: 200))->exportXlsx(FilterCriteria::none());
            $this->fail('the export should have failed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source lost', $e->getMessage());
        }

        $this->assertSame([], $this->scratchDirs(), 'scratch folder removed after failure');
        $this->assertSame([], array_values(array_diff(scandir($this->exportDir), ['.', '..'])), 'no partial workbook');

        // ...and the next export is not blocked.
        $meta = $this->service(new ArrayRowSource(10))->exportXlsx(FilterCriteria::none());
        $this->assertSame(10, $meta['rows']);
        $this->assertSame([], $this->scratchDirs());
    }

    public function testInterleavedExportsUseSeparateScratchFolders(): void
    {
        $seen   = [];
        $inner  = $this->service(new ArrayRowSource(5));
        $source = new ArrayRowSource(50, onRow: function (int $n) use (&$seen, $inner): void {
            if ($n === 25) {
                // A second export runs to completion while the first is mid-stream.
                $seen['during'] = $this->scratchDirs();
                $inner->exportXlsx(FilterCriteria::none());
                $seen['after_inner'] = $this->scratchDirs();
            }
        });

        $meta = $this->service($source)->exportXlsx(FilterCriteria::none());

        $this->assertCount(1, $seen['during'], 'the outer export has its own folder');
        $this->assertSame($seen['during'], $seen['after_inner'], "the inner export's cleanup never touches the outer one's folder");
        $this->assertSame(50, $meta['rows']);
        $this->assertSame([], $this->scratchDirs());
    }

    public function testOrphanScratchIsSweptButARecentOneIsKept(): void
    {
        mkdir($this->tmpDir . '/export_old/worksheets-temp', 0777, true);
        file_put_contents($this->tmpDir . '/export_old/worksheets-temp/sheet1.xml', 'x');
        touch($this->tmpDir . '/export_old', time() - 7 * 3600);
        mkdir($this->tmpDir . '/export_running', 0777, true);

        $this->service(new ArrayRowSource(1));

        $this->assertDirectoryDoesNotExist($this->tmpDir . '/export_old', 'orphan (> 6 h) swept');
        $this->assertDirectoryExists($this->tmpDir . '/export_running', 'a folder possibly in use is left alone');
    }
}

final class ArrayRowSource implements RowSource
{
    /** @var callable(int): void|null */
    private $onRow;

    public function __construct(private int $rows, private ?int $failAfter = null, ?callable $onRow = null)
    {
        $this->onRow = $onRow;
    }

    public function stream(FilterCriteria $criteria, callable $onRow): int
    {
        for ($i = 1; $i <= $this->rows; $i++) {
            if ($this->failAfter !== null && $i > $this->failAfter) {
                throw new RuntimeException('source lost mid-stream');
            }
            $row = array_fill_keys(CustomerListExportService::COLUMNS, 'v' . $i);
            $onRow($row);
            if ($this->onRow !== null) {
                ($this->onRow)($i);
            }
        }

        return $this->rows;
    }

    public function label(): string
    {
        return 'test';
    }
}
