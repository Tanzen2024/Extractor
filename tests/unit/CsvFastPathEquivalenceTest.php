<?php

use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\CsvRowWriter;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use CodeIgniter\Test\Mock\MockCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * The 2026-09-29 CSV fast paths must never change a byte:
 *   - CsvRowWriter reuses a row whose keys are already the columns in order,
 *     instead of rebuilding it — same output as the rebuilding loop;
 *   - SnapshotRowSource builds rows with array_combine() when the snapshot
 *     holds exactly the export columns in order — same rows as picking by
 *     name (which a 27-column snapshot still uses).
 *
 * @internal
 */
final class CsvFastPathEquivalenceTest extends TestCase
{
    private const COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

    /** @param list<array<string, mixed>> $rows */
    private static function write(array $rows, int $bufferBytes): string
    {
        $h = fopen('php://memory', 'w+b');
        $w = new CsvRowWriter($h, self::COLUMNS, true, $bufferBytes);
        foreach ($rows as $row) {
            $w->writeRow($row);
        }
        $w->flush();
        rewind($h);

        return stream_get_contents($h);
    }

    public function testFastPathWritesTheSameBytesAsTheRebuildingLoop(): void
    {
        $values = [
            'A' => null, 'B' => 42, 'C' => 0.1, 'D' => true, 'E' => false,
            'F' => 'Dupont; "Le Grand"', 'G' => "multi\nline", 'H' => 'back\\slash "q"', 'I' => '',
        ];
        $inOrder  = $values;                                   // fast path
        $shuffled = array_reverse($values, true);              // same data, other key order -> loop
        $missing  = $values;
        unset($missing['A']);                                  // missing key -> loop, '' for A

        foreach ([0, 1 << 20] as $buffer) {
            $fast = self::write([$inOrder, $inOrder], $buffer);

            $this->assertSame(self::write([$shuffled, $shuffled], $buffer), $fast, "buffer={$buffer}");
            $this->assertSame(self::write([$missing, $missing], $buffer), $fast, "buffer={$buffer}"); // null == missing
        }
    }

    public function testCombinedSnapshotRowsEqualByNameRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = SnapshotFixture::row($i, [
                'REGION'    => $i % 3 === 0 ? 'DCUD' : 'DCUY',
                'CUST_NAME' => "Nom; \"{$i}\" Élève",
            ]);
        }

        // 25-column snapshot in export order (customers:refresh layout) -> array_combine.
        $exact = $this->streamFrom($rows, CustomerListExportService::COLUMNS);
        // 27-column snapshot (XCOORD/YCOORD, SFTP layout) -> picking by name.
        $wide  = $this->streamFrom($rows, SnapshotFixture::HEADER);

        $this->assertCount(40, $exact['all']);
        $this->assertSame($wide['all'], $exact['all']);
        $this->assertSame(CustomerListExportService::COLUMNS, array_keys($exact['all'][0]));
        $this->assertSame($wide['filtered'], $exact['filtered']);
        $this->assertCount(13, $exact['filtered']);
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string>                $header
     *
     * @return array{all: list<array<string, string>>, filtered: list<array<string, string>>}
     */
    private function streamFrom(array $rows, array $header): array
    {
        $fx = new SnapshotFixture();

        try {
            $fx->deliver(SnapshotFixture::csv($rows, $header));
            (new SnapshotInstaller($fx->store))->install();

            $source = new SnapshotRowSource(store: $fx->store, cache: new MockCache());
            $out    = ['all' => [], 'filtered' => []];

            $source->stream(FilterCriteria::none(), static function (array $row) use (&$out): void { $out['all'][] = $row; });
            $source->stream(
                FilterCriteria::fromRequest(['region' => ['DCUD']], $source->allowedValues()),
                static function (array $row) use (&$out): void { $out['filtered'][] = $row; },
            );

            return $out;
        } finally {
            $fx->cleanup();
        }
    }
}
