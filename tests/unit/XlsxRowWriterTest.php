<?php

use App\Services\Export\XlsxRowWriter;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

/**
 * XlsxRowWriter never touches Oracle. It streams rows straight into a real
 * .xlsx file via OpenSpout; every test here writes a handful of fixture rows
 * to a temp file and reads them back with OpenSpout's reader. A small
 * maxRowsPerSheet exercises the multi-sheet split (Excel's real
 * 1,048,576-row-per-sheet ceiling, mirrored by CustomerListExportService
 * with the true limit) without writing over a million rows.
 *
 * @internal
 */
final class XlsxRowWriterTest extends TestCase
{
    private const COLUMNS = ['REGION', 'DIVISION', 'AGENCE', 'COD_UNICOM', 'COD_CLI', 'CONTRACT', 'STATUS',
        'METER_NO', 'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'REF_GEO', 'DATE_AB',
        'DATE_RESILIATION', 'VOLTAGE', 'SEGMENT_TRESOR', 'METER', 'NIU_RIGHT', 'NUI_QC',
        'LAST_VC_DATE', 'SEGMENT_RFM_2', 'POSTPAID_PROFILE_DATE', 'SEGMENTATION'];

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_xlsxrw_' . bin2hex(random_bytes(4)) . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function write(array $rows, int $maxRowsPerSheet): XlsxRowWriter
    {
        $writer = new XlsxRowWriter($this->path, self::COLUMNS, $maxRowsPerSheet);
        foreach ($rows as $row) {
            $writer->writeRow($row);
        }
        $writer->close();

        return $writer;
    }

    /**
     * @return array<string, list<list<string>>> Sheet name => list of rows (each row a list of 23 string values, header included).
     */
    private function readBack(): array
    {
        $reader = new Reader();
        $reader->open($this->path);

        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(static fn ($v) => $v === null ? '' : (string) $v, $row->toArray());
                $values = array_pad($values, count(self::COLUMNS), '');
                $rows[] = $values;
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }

    public function testAllDataFitsOnOneSheetWhenUnderTheLimit(): void
    {
        $writer = $this->write([
            ['COD_CLI' => 'C1'], ['COD_CLI' => 'C2'], ['COD_CLI' => 'C3'],
        ], 10);

        $this->assertSame(1, $writer->sheetCount());
        $this->assertSame(3, $writer->totalRowsWritten());

        $sheets = $this->readBack();
        $this->assertSame(['CustomerList_1'], array_keys($sheets));
        $this->assertCount(4, $sheets['CustomerList_1']); // header + 3 rows
    }

    public function testSplitsIntoMultipleSheetsOncePerSheetLimitIsReached(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['COD_CLI' => "C{$i}"];
        }
        $writer = $this->write($rows, 5);

        $this->assertSame(3, $writer->sheetCount());
        $this->assertSame(12, $writer->totalRowsWritten());

        $sheets = $this->readBack();
        $this->assertSame(['CustomerList_1', 'CustomerList_2', 'CustomerList_3'], array_keys($sheets));
        // 5 + 5 + 2 data rows, each sheet also carrying one header row.
        $this->assertCount(6, $sheets['CustomerList_1']);
        $this->assertCount(6, $sheets['CustomerList_2']);
        $this->assertCount(3, $sheets['CustomerList_3']);
    }

    public function testEveryColumnHeaderIsWrittenOnceOnEachSheet(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['COD_CLI' => "C{$i}"];
        }
        $this->write($rows, 2);

        foreach ($this->readBack() as $sheetRows) {
            $this->assertSame(self::COLUMNS, $sheetRows[0]);
        }
    }

    public function testValuesAreWrittenInColumnOrder(): void
    {
        $this->write([['REGION' => 'NORD', 'DIVISION' => 'DIV A', 'COD_CLI' => 'C1']], 10);

        $firstDataRow = $this->readBack()['CustomerList_1'][1];
        $this->assertSame('NORD', $firstDataRow[0]);  // REGION
        $this->assertSame('DIV A', $firstDataRow[1]); // DIVISION
        $this->assertSame('C1', $firstDataRow[4]);    // COD_CLI is the 5th column
    }

    public function testNullAndMissingValuesBecomeEmptyStrings(): void
    {
        $this->write([['REGION' => 'NORD', 'E_MAIL' => null]], 10); // PHONE_NUMBERS entirely absent

        $firstDataRow = $this->readBack()['CustomerList_1'][1];
        $this->assertSame('', $firstDataRow[9]);  // PHONE_NUMBERS
        $this->assertSame('', $firstDataRow[10]); // E_MAIL
    }

    public function testNumericLookingValuesAreStoredAsTextNotConvertedNumbers(): void
    {
        // A leading-zero phone number or contract id must survive verbatim —
        // exactly what Excel's automatic numeric conversion would break.
        $this->write([['PHONE_NUMBERS' => '0123456789', 'COD_CLI' => '000042', 'CONTRACT' => '900000000123']], 10);

        $firstDataRow = $this->readBack()['CustomerList_1'][1];
        $this->assertSame('000042', $firstDataRow[4]);       // COD_CLI
        $this->assertSame('900000000123', $firstDataRow[5]); // CONTRACT
        $this->assertSame('0123456789', $firstDataRow[9]);   // PHONE_NUMBERS
    }

    public function testValueStartingWithEqualsIsNotTreatedAsAFormula(): void
    {
        $this->write([['CUST_NAME' => '=SUM(A1:A2)']], 10);

        $firstDataRow = $this->readBack()['CustomerList_1'][1];
        $this->assertSame('=SUM(A1:A2)', $firstDataRow[8]); // CUST_NAME, verbatim
    }

    public function testAccentedUtf8SurvivesRoundTrip(): void
    {
        $this->write([['CUST_NAME' => 'Éléonore Ouédraogo', 'AGENCE' => 'AGENCE Bafoussam Rural']], 10);

        $firstDataRow = $this->readBack()['CustomerList_1'][1];
        $this->assertSame('Éléonore Ouédraogo', $firstDataRow[8]);
    }

    public function testCloseIsIdempotent(): void
    {
        $writer = new XlsxRowWriter($this->path, self::COLUMNS, 10);
        $writer->writeRow(['COD_CLI' => 'C1']);
        $writer->close();
        $writer->close(); // must not throw

        $this->assertFileExists($this->path);
    }
}
