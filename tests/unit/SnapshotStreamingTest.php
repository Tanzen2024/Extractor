<?php

use App\Services\CustomersList\FilterCriteria;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * Install + stream at volume with flat memory. The default run uses 200 000
 * rows; set SNAPSHOT_SLOW_TESTS=1 to also run the 3 301 629-row case (same
 * row count as the real snapshot; ~0.9 GB of temp disk, several minutes).
 *
 * @internal
 */
final class SnapshotStreamingTest extends TestCase
{
    public function testTwoHundredThousandRowsStreamWithFlatMemory(): void
    {
        $this->runVolume(200_000);
    }

    public function testRealisticVolumeOfMillionsOfRows(): void
    {
        if (getenv('SNAPSHOT_SLOW_TESTS') !== '1') {
            $this->markTestSkipped('Set SNAPSHOT_SLOW_TESTS=1 to run the 3.3M-row streaming test.');
        }

        $this->runVolume(3_301_629);
    }

    private function runVolume(int $rows): void
    {
        $fx = new SnapshotFixture();

        try {
            $this->writeDelivery($fx, $rows);

            $r = (new SnapshotInstaller($fx->store))->install();
            $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
            $this->assertSame($rows, $r['meta']['rows']);

            gc_collect_cycles();
            memory_reset_peak_usage();
            $baseline = memory_get_usage();

            $n      = 0;
            $source = new SnapshotRowSource(store: $fx->store);
            $count  = $source->stream(FilterCriteria::none(), static function (array $row) use (&$n): void {
                $n++;
            });

            $this->assertSame($rows, $count);
            $this->assertSame($rows, $n);
            $this->assertLessThan(8 * 1048576, memory_get_peak_usage() - $baseline, 'streaming must not grow with the row count');
        } finally {
            $fx->cleanup();
        }
    }

    /**
     * Writes the delivery straight to disk (never as one big string) and
     * builds its manifest incrementally, like the source script.
     */
    private function writeDelivery(SnapshotFixture $fx, int $rows): void
    {
        $incoming = $fx->store->dir('incoming') . DIRECTORY_SEPARATOR;
        $h        = fopen($incoming . 'customers_list.csv', 'wb');
        $hash     = hash_init('sha256');
        $size     = 0;
        $header   = implode('#', SnapshotFixture::HEADER);

        $write = static function (string $chunk) use ($h, $hash, &$size): void {
            fwrite($h, $chunk);
            hash_update($hash, $chunk);
            $size += strlen($chunk);
        };

        $write($header . "\n");
        $buffer = '';
        $regions = ['DRC', 'DRE', 'DRO', 'DRNEA', 'DRSANO', 'DRSOM'];
        for ($i = 1; $i <= $rows; $i++) {
            $row = SnapshotFixture::row($i, [
                'REGION'  => $regions[$i % 6],
                'DATE_AB' => sprintf('20%02d-%02d-%02d', $i % 25, 1 + $i % 12, 1 + $i % 28),
            ]);
            $buffer .= implode('#', array_map(static fn (string $c): string => $row[$c], SnapshotFixture::HEADER)) . "\n";
            if (strlen($buffer) > 1 << 20) {
                $write($buffer);
                $buffer = '';
            }
        }
        $write($buffer);
        fclose($h);

        file_put_contents($incoming . 'customers_list.manifest', implode("\n", [
            'file=customers_list.csv',
            "size={$size}",
            'sha256=' . hash_final($hash),
            'lines=' . ($rows + 1),
            'columns=' . count(SnapshotFixture::HEADER),
            'delimiter=#',
            'encoding=UTF-8',
            "header={$header}",
        ]) . "\n");
    }
}
