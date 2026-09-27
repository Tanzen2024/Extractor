<?php

use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotUnavailableException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * Receive -> validate -> activate, and every way a delivery can be refused.
 * The invariant checked throughout: a refused or incomplete delivery NEVER
 * replaces the active snapshot.
 *
 * @internal
 */
final class SnapshotInstallerTest extends TestCase
{
    private SnapshotFixture $fx;

    protected function setUp(): void
    {
        $this->fx = new SnapshotFixture();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function installer(): SnapshotInstaller
    {
        return new SnapshotInstaller($this->fx->store);
    }

    /** @return list<array<string, string>> */
    private function rows(int $n, int $offset = 0): array
    {
        return array_map(static fn (int $i) => SnapshotFixture::row($i), range($offset + 1, $offset + $n));
    }

    /** Installs a first good snapshot and returns its id. */
    private function activateBaseline(): string
    {
        $r = $this->fx->install($this->rows(3));
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result']);

        return $r['version'];
    }

    private function assertRejectedKeeping(string $reason, string $previous): void
    {
        $r = $this->installer()->install();

        $this->assertSame(SnapshotInstaller::RESULT_REJECTED, $r['result'], (string) $r['message']);
        $this->assertSame($reason, $r['reason'], (string) $r['message']);
        $this->assertSame($previous, $this->fx->store->active()->id, 'the previous snapshot must stay active');
        $this->assertSame(3, $this->fx->store->active()->rows());
        $this->assertFileDoesNotExist($this->fx->store->dir('incoming') . '/customers_list.csv');
    }

    public function testValidDeliveryIsActivatedWithComputedMeta(): void
    {
        $content = SnapshotFixture::csv($this->rows(5));
        $this->fx->deliver($content);

        $r = $this->installer()->install();

        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
        $active = $this->fx->store->active();
        $this->assertSame($r['version'], $active->id);
        $this->assertSame(5, $active->rows());
        $this->assertSame(6, $active->meta['lines']);
        $this->assertSame(27, $active->meta['columns']);
        $this->assertSame(strlen($content), $active->meta['size']);
        $this->assertSame(hash('sha256', $content), $active->meta['sha256']);
        $this->assertSame('#', $active->meta['delimiter']);
        $this->assertSame('Y-m-d', $active->meta['date_ab_format']);
        $this->assertSame('2026-09-25T06:12:03+01:00', $active->meta['generated_at'], 'generated_at comes from the source manifest');
        $this->assertSame('valid', $active->meta['status']);
        $this->assertSame([], glob($this->fx->store->dir('incoming') . '/*'), 'incoming/ is emptied');
    }

    public function testNoSnapshotMeansUnavailableNotOracle(): void
    {
        $this->expectException(SnapshotUnavailableException::class);
        $this->fx->store->active();
    }

    public function testNothingDeliveredIsReportedAndChangesNothing(): void
    {
        $previous = $this->activateBaseline();

        $r = $this->installer()->install();

        $this->assertSame(SnapshotInstaller::RESULT_INCOMPLETE, $r['result']);
        $this->assertSame('nothing_to_install', $r['reason']);
        $this->assertSame($previous, $this->fx->store->active()->id);
    }

    public function testInterruptedTransferLeavingOnlyPartFilesIsIgnored(): void
    {
        $previous = $this->activateBaseline();
        $incoming = $this->fx->store->dir('incoming') . DIRECTORY_SEPARATOR;
        $full     = SnapshotFixture::csv($this->rows(50));
        file_put_contents($incoming . 'customers_list.csv.part', substr($full, 0, 700));

        $r = $this->installer()->install();

        $this->assertSame(SnapshotInstaller::RESULT_INCOMPLETE, $r['result']);
        $this->assertSame('transfer_incomplete', $r['reason']);
        $this->assertSame($previous, $this->fx->store->active()->id);
        $this->assertFileExists($incoming . 'customers_list.csv.part', 'a .part is never consumed');
    }

    public function testTruncatedFileIsRejectedOnSize(): void
    {
        $previous = $this->activateBaseline();
        $full     = SnapshotFixture::csv($this->rows(50));
        $this->fx->deliver(substr($full, 0, (int) (strlen($full) / 2)), [], $full);

        $this->assertRejectedKeeping('size_mismatch', $previous);
    }

    public function testEmptyFileIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $this->fx->deliver('', [], SnapshotFixture::csv($this->rows(3)));

        $this->assertRejectedKeeping('file_empty', $previous);
    }

    public function testCorruptedBytesAreRejectedOnSha256(): void
    {
        $previous = $this->activateBaseline();
        $content  = SnapshotFixture::csv($this->rows(5));
        $this->fx->deliver(str_replace('CLI3#', 'CLX3#', $content), [], $content);

        $this->assertRejectedKeeping('sha256_mismatch', $previous);
    }

    public function testHeaderDifferentFromSourceHeaderIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $content  = SnapshotFixture::csv($this->rows(3));
        $this->fx->deliver($content, ['header' => implode('#', array_reverse(SnapshotFixture::HEADER))]);

        $this->assertRejectedKeeping('header_mismatch', $previous);
    }

    public function testColumnCountDifferentFromSourceIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $this->fx->deliver(SnapshotFixture::csv($this->rows(3)), ['columns' => '26']);

        $this->assertRejectedKeeping('column_count_mismatch', $previous);
    }

    public function testRowWithWrongFieldCountIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $rows     = $this->rows(4);
        $rows[2]['CUST_NAME'] = 'Nom#avec#dieses';
        $this->fx->deliver(SnapshotFixture::csv($rows));

        $this->assertRejectedKeeping('row_column_mismatch', $previous);
    }

    public function testMissingRequiredColumnIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $header   = array_values(array_diff(SnapshotFixture::HEADER, ['NUI_QC']));
        $this->fx->deliver(SnapshotFixture::csv($this->rows(3), $header));

        $this->assertRejectedKeeping('columns_missing', $previous);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $previous = $this->activateBaseline();
        $rows     = $this->rows(3);
        $rows[1]['CUST_NAME'] = "Nom \xE9 latin1";
        $this->fx->deliver(SnapshotFixture::csv($rows));

        $this->assertRejectedKeeping('encoding_invalid', $previous);
    }

    public function testLineCountDifferentFromSourceIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $this->fx->deliver(SnapshotFixture::csv($this->rows(3)), ['lines' => '99']);

        $this->assertRejectedKeeping('line_count_mismatch', $previous);
    }

    public function testHeaderOnlyFileIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $this->fx->deliver(SnapshotFixture::csv([]), ['lines' => '2']);

        $this->assertRejectedKeeping('no_data', $previous);
    }

    public function testManifestMissingAKeyIsRejected(): void
    {
        $previous = $this->activateBaseline();
        $incoming = $this->fx->store->dir('incoming') . DIRECTORY_SEPARATOR;
        file_put_contents($incoming . 'customers_list.csv', SnapshotFixture::csv($this->rows(3)));
        file_put_contents($incoming . 'customers_list.manifest', "size=10\nlines=4\n");

        $this->assertRejectedKeeping('manifest_invalid', $previous);
    }

    public function testRejectionKeepsReasonButNotTheRefusedData(): void
    {
        $this->activateBaseline();
        $this->fx->deliver(SnapshotFixture::csv($this->rows(3)), ['lines' => '99']);
        $r = $this->installer()->install();

        $dir = $this->fx->store->dir('rejected') . DIRECTORY_SEPARATOR . $r['version'];
        $this->assertFileExists($dir . '/reason.json');
        $this->assertFileExists($dir . '/customers_list.manifest');
        $this->assertFileDoesNotExist($dir . '/customers_list.csv');
    }

    public function testNewValidSnapshotReplacesTheOldOneAndOldestVersionsArePruned(): void
    {
        $first  = $this->activateBaseline();
        sleep(1); // version ids are timestamp-ordered
        $second = $this->fx->install($this->rows(4))['version'];
        sleep(1);
        $third  = $this->fx->install($this->rows(7))['version'];

        $this->assertSame($third, $this->fx->store->active()->id);
        $this->assertSame(7, $this->fx->store->active()->rows());
        $this->assertSame([$third, $second], $this->fx->store->versions(), 'keepVersions=2: active + previous');
        $this->assertDirectoryDoesNotExist($this->fx->store->versionDir($first));
    }

    public function testRollbackReactivatesThePreviousValidatedVersion(): void
    {
        $first = $this->activateBaseline();
        sleep(1);
        $this->fx->install($this->rows(4));

        $this->fx->store->activate($first);

        $this->assertSame($first, $this->fx->store->active()->id);
        $this->assertSame(3, $this->fx->store->active()->rows());
    }

    public function testConcurrentInstallIsRefusedWhileOneHoldsTheLock(): void
    {
        $this->fx->store->ensureLayout();
        $lock = fopen($this->fx->store->dir('install.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->fx->deliver(SnapshotFixture::csv($this->rows(3)));
            $r = $this->installer()->install();
            $this->assertSame(SnapshotInstaller::RESULT_BUSY, $r['result']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testFilterOptionsAreComputedFromTheSnapshot(): void
    {
        $rows = [
            SnapshotFixture::row(1, ['REGION' => 'DRC', 'STATUS' => 'ACTIVE', 'DATE_AB' => '1985-03-01']),
            SnapshotFixture::row(2, ['REGION' => 'DRC', 'STATUS' => 'SUSPENDED']),
            SnapshotFixture::row(3, ['REGION' => 'DRE', 'STATUS' => ' ', 'DIVISION' => ' ', 'DATE_AB' => '2024-12-31']),
        ];
        $this->fx->install($rows);

        $options = $this->fx->store->active()->filterOptions();

        $this->assertSame([['value' => 'DRC', 'count' => 2], ['value' => 'DRE', 'count' => 1]], $options['regions']);
        $this->assertSame(['ACTIVE', 'SUSPENDED'], array_column($options['statuses'], 'value'), 'blank values are not offered');
        $this->assertArrayHasKey('Non renseigné', $options['geoTree']['DRE'], 'blank geo level labelled like the dashboard');
        $this->assertSame(['min' => '1990-01-01', 'max' => '2024-12-31'], $options['dateBounds']);
        foreach (['regions', 'divisions', 'agences', 'statuses', 'segmentations', 'segmentsTresor', 'meters', 'voltages', 'niuQualities', 'geoTree', 'dateBounds'] as $key) {
            $this->assertArrayHasKey($key, $options);
        }
    }
}
