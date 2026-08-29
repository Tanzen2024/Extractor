<?php

use App\Services\Export\CsvRowWriter;
use PHPUnit\Framework\TestCase;

/**
 * CsvRowWriter never touches Oracle — it just writes to an open handle —
 * so it is exercised directly against php://temp with hand-built fixture
 * rows, including the exact edge cases CUSTOMERS_LIST data can contain
 * (embedded ';', embedded '"', embedded newlines, NULLs, accents).
 *
 * @internal
 */
final class CsvRowWriterTest extends TestCase
{
    private const COLUMNS = ['REGION', 'DIVISION', 'AGENCE', 'COD_UNICOM', 'COD_CLI', 'CONTRACT', 'STATUS',
        'METER_NO', 'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'REF_GEO', 'DATE_AB',
        'DATE_RESILIATION', 'VOLTAGE', 'SEGMENT_TRESOR', 'METER', 'NIU_RIGHT', 'NIU_QC',
        'LAST_VC_DATE', 'SEGMENT_RFM_2', 'POSTPAID_PROFILE_DATE', 'SEGMENTATION'];

    /** @return array{handle: resource, writer: CsvRowWriter} */
    private function newWriter(bool $bom = true): array
    {
        $handle = fopen('php://temp', 'r+b');
        $writer = new CsvRowWriter($handle, self::COLUMNS, $bom);

        return ['handle' => $handle, 'writer' => $writer];
    }

    private function readAll($handle): string
    {
        rewind($handle);

        return stream_get_contents($handle);
    }

    /** @return list<list<string>> */
    private function parseCsv(string $content): array
    {
        $lines = [];
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $content);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $lines[] = $row;
        }

        fclose($handle);

        return $lines;
    }

    public function testHeaderContainsAllTwentyThreeColumnsInOrder(): void
    {
        ['handle' => $handle] = $this->newWriter();

        $content = $this->readAll($handle);
        $rows    = $this->parseCsv(ltrim($content, "\xEF\xBB\xBF"));

        $this->assertCount(23, $rows[0]);
        $this->assertSame(self::COLUMNS, $rows[0]);
    }

    public function testWritesUtf8Bom(): void
    {
        ['handle' => $handle] = $this->newWriter(true);

        rewind($handle);
        $firstThreeBytes = fread($handle, 3);

        $this->assertSame("\xEF\xBB\xBF", $firstThreeBytes);
    }

    public function testBomCanBeOmitted(): void
    {
        ['handle' => $handle] = $this->newWriter(false);

        rewind($handle);
        $firstThreeBytes = fread($handle, 3);

        $this->assertNotSame("\xEF\xBB\xBF", $firstThreeBytes);
    }

    public function testUsesSemicolonDelimiter(): void
    {
        ['handle' => $handle] = $this->newWriter();

        $content = $this->readAll($handle);

        $this->assertStringContainsString('REGION;DIVISION;AGENCE', $content);
    }

    public function testNullValuesBecomeEmptyFields(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        $writer->writeRow(['REGION' => 'NORD', 'E_MAIL' => null, 'PHONE_NUMBERS' => null]);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $dataRow = array_combine(self::COLUMNS, $rows[1]);

        $this->assertSame('NORD', $dataRow['REGION']);
        $this->assertSame('', $dataRow['E_MAIL']);
        $this->assertSame('', $dataRow['PHONE_NUMBERS']);
    }

    public function testMissingColumnsInSourceRowBecomeEmptyFields(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        // Simulates a row missing a key entirely, not just null-valued.
        $writer->writeRow(['REGION' => 'SUD']);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $this->assertCount(23, $rows[1]);
    }

    public function testEmbeddedSemicolonIsEscapedAndRoundTrips(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        $writer->writeRow(['CUST_NAME' => 'Dupont; Jean']);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $dataRow = array_combine(self::COLUMNS, $rows[1]);

        $this->assertSame('Dupont; Jean', $dataRow['CUST_NAME']);
    }

    public function testEmbeddedDoubleQuoteIsEscapedAndRoundTrips(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        $writer->writeRow(['CUST_NAME' => 'Le "Grand" Dupont']);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $dataRow = array_combine(self::COLUMNS, $rows[1]);

        $this->assertSame('Le "Grand" Dupont', $dataRow['CUST_NAME']);
    }

    public function testEmbeddedNewlineIsEscapedAndRoundTrips(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        $writer->writeRow(['CUST_NAME' => "Dupont\nJean"]);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $dataRow = array_combine(self::COLUMNS, $rows[1]);

        $this->assertSame("Dupont\nJean", $dataRow['CUST_NAME']);
    }

    public function testAccentedCharactersRoundTripAsUtf8(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        $writer->writeRow(['CUST_NAME' => 'Éléonore Ndjamena Ouédraogo']);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $dataRow = array_combine(self::COLUMNS, $rows[1]);

        $this->assertSame('Éléonore Ndjamena Ouédraogo', $dataRow['CUST_NAME']);
    }

    public function testMultipleRowsPreserveOrder(): void
    {
        ['handle' => $handle, 'writer' => $writer] = $this->newWriter();

        $writer->writeRow(['COD_CLI' => 'C1']);
        $writer->writeRow(['COD_CLI' => 'C2']);
        $writer->writeRow(['COD_CLI' => 'C3']);

        $rows = $this->parseCsv(ltrim($this->readAll($handle), "\xEF\xBB\xBF"));
        $codCliIndex = array_search('COD_CLI', self::COLUMNS, true);

        $this->assertSame('C1', $rows[1][$codCliIndex]);
        $this->assertSame('C2', $rows[2][$codCliIndex]);
        $this->assertSame('C3', $rows[3][$codCliIndex]);
    }
}
