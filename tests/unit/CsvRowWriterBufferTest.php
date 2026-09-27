<?php

use App\Services\Export\CsvRowWriter;
use PHPUnit\Framework\TestCase;

/**
 * Buffered mode of CsvRowWriter must produce exactly the same bytes as the
 * default write-through mode, and hold nothing back once flush() ran.
 *
 * @internal
 */
final class CsvRowWriterBufferTest extends TestCase
{
    private const COLUMNS = ['REGION', 'CUST_NAME', 'E_MAIL'];

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['REGION' => 'DCUY', 'CUST_NAME' => "Dupont; \"Le Grand\" Élève\n{$i}", 'E_MAIL' => $i % 2 ? null : ' '];
        }

        return $rows;
    }

    private static function write(int $bufferBytes): string
    {
        $handle = fopen('php://temp', 'r+b');
        $writer = new CsvRowWriter($handle, self::COLUMNS, true, $bufferBytes);
        foreach (self::rows() as $row) {
            $writer->writeRow($row);
        }
        $writer->flush();
        rewind($handle);
        $out = (string) stream_get_contents($handle);
        fclose($handle);

        return $out;
    }

    public function testBufferedOutputIsByteIdenticalToWriteThrough(): void
    {
        $direct = self::write(0);

        $this->assertSame($direct, self::write(4096), 'small buffer: many intermediate flushes');
        $this->assertSame($direct, self::write(1 << 20), 'large buffer: single final flush');
    }

    public function testRowsStayInBufferUntilFlush(): void
    {
        $handle = fopen('php://temp', 'r+b');
        $writer = new CsvRowWriter($handle, self::COLUMNS, false, 1 << 20);
        $header = ftell($handle);

        $writer->writeRow(['REGION' => 'NORD']);
        $this->assertSame($header, ftell($handle), 'buffered, not yet on the handle');

        $writer->flush();
        $this->assertGreaterThan($header, ftell($handle));
        fclose($handle);
    }
}
