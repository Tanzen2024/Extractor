<?php

use App\Services\CustomerListExportService;
use App\Services\Export\CsvRowWriter;
use App\Services\Export\XlsxRowWriter;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

/**
 * Feeds the exact same fixture rows through both writers (never touching
 * Oracle) and asserts the CSV and XLSX outputs agree on row count, column
 * count, column names/order, and every value — the parity check required
 * before trusting either export as a faithful copy of CUSTOMERS_LIST.
 *
 * @internal
 */
final class CustomerListExportIntegrityTest extends TestCase
{
    private const COLUMNS = CustomerListExportService::COLUMNS;

    /** @return list<array<string, mixed>> */
    private function fixtureRows(): array
    {
        return [
            [
                'REGION' => 'NORD', 'DIVISION' => 'DIVISION A', 'AGENCE' => 'AGENCE 001',
                'COD_UNICOM' => '10001', 'COD_CLI' => 'CLI-0001', 'CONTRACT' => '900001',
                'STATUS' => 'ACTIVE', 'METER_NO' => 'M0001', 'CUST_NAME' => 'Éléonore Ouédraogo',
                'PHONE_NUMBERS' => '0123456789', 'E_MAIL' => 'e@example.com', 'REF_GEO' => 'G001',
                'DATE_AB' => '15-JAN-20', 'DATE_RESILIATION' => null, 'VOLTAGE' => '220',
                'SEGMENT_TRESOR' => 'T1', 'METER' => 'PREPAID', 'NIU_RIGHT' => 'P123456789012A',
                'NUI_QC' => 0, 'LAST_VC_DATE' => '01-AUG-26', 'SEGMENT_RFM_2' => 'RFM 1',
                'POSTPAID_PROFILE_DATE' => null, 'SEGMENTATION' => 'RFM 1',
            ],
            [
                'REGION' => 'SUD', 'DIVISION' => 'DIVISION B', 'AGENCE' => 'AGENCE 002',
                'COD_UNICOM' => '10002', 'COD_CLI' => 'CLI-0002', 'CONTRACT' => '900002',
                'STATUS' => 'INACTIVE', 'METER_NO' => 'M0002', 'CUST_NAME' => 'Dupont; "Le Grand"',
                'PHONE_NUMBERS' => null, 'E_MAIL' => null, 'REF_GEO' => 'G002',
                'DATE_AB' => '02-FEB-19', 'DATE_RESILIATION' => '10-JUL-26', 'VOLTAGE' => '380',
                'SEGMENT_TRESOR' => 'T2', 'METER' => 'POSTPAID', 'NIU_RIGHT' => null,
                'NUI_QC' => 1, 'LAST_VC_DATE' => null, 'SEGMENT_RFM_2' => null,
                'POSTPAID_PROFILE_DATE' => '01-JUN-26', 'SEGMENTATION' => 'Standard',
            ],
            [
                'REGION' => 'EST', 'DIVISION' => 'DIVISION C', 'AGENCE' => 'AGENCE 003',
                'COD_UNICOM' => '10003', 'COD_CLI' => 'CLI-0003', 'CONTRACT' => '900003',
                'STATUS' => 'SUSPENDED', 'METER_NO' => 'M0003', 'CUST_NAME' => "Ligne\nsur deux",
                'PHONE_NUMBERS' => '0987654321', 'E_MAIL' => 'x@y.z', 'REF_GEO' => 'G003',
                'DATE_AB' => '20-MAR-21', 'DATE_RESILIATION' => null, 'VOLTAGE' => '220',
                'SEGMENT_TRESOR' => 'T3', 'METER' => 'Compteurs Communicants', 'NIU_RIGHT' => 'M987654321098Z',
                'NUI_QC' => 0, 'LAST_VC_DATE' => null, 'SEGMENT_RFM_2' => null,
                'POSTPAID_PROFILE_DATE' => null, 'SEGMENTATION' => '8 Autre',
            ],
        ];
    }

    /** @return list<array<string, string>> Rows keyed by column name, as read back from the CSV file. */
    private function writeAndReadCsv(array $rows): array
    {
        $path   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_integrity_' . bin2hex(random_bytes(4)) . '.csv';
        $handle = fopen($path, 'w+b');
        $writer = new CsvRowWriter($handle, self::COLUMNS);

        foreach ($rows as $row) {
            $writer->writeRow($row);
        }
        fclose($handle);

        $content = ltrim(file_get_contents($path), "\xEF\xBB\xBF");
        @unlink($path);

        $lines  = [];
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $content);
        rewind($handle);
        while (($line = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $lines[] = $line;
        }
        fclose($handle);

        $header = array_shift($lines);
        $this->assertSame(self::COLUMNS, $header);

        return array_map(static fn (array $line) => array_combine($header, $line), $lines);
    }

    /** @return list<array<string, string>> Rows keyed by column name, as read back from the written .xlsx file. */
    private function writeAndReadXlsx(array $rows): array
    {
        $path   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_integrity_' . bin2hex(random_bytes(4)) . '.xlsx';
        $writer = new XlsxRowWriter($path, self::COLUMNS, 1000);

        foreach ($rows as $row) {
            $writer->writeRow($row);
        }
        $writer->close();

        $reader = new Reader();
        $reader->open($path);

        $lines = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values  = array_map(static fn ($v) => $v === null ? '' : (string) $v, $row->toArray());
                $lines[] = array_pad($values, count(self::COLUMNS), '');
            }
        }
        $reader->close();
        @unlink($path);

        $header = array_shift($lines);
        $this->assertSame(self::COLUMNS, $header);

        return array_map(static fn (array $line) => array_combine(self::COLUMNS, $line), $lines);
    }

    public function testCsvAndXlsxProduceIdenticalRowAndColumnCounts(): void
    {
        $rows = $this->fixtureRows();

        $csv  = $this->writeAndReadCsv($rows);
        $xlsx = $this->writeAndReadXlsx($rows);

        $this->assertCount(count($rows), $csv);
        $this->assertCount(count($rows), $xlsx);
        $this->assertCount(23, self::COLUMNS);

        foreach ($csv as $line) {
            $this->assertCount(23, $line);
        }
        foreach ($xlsx as $line) {
            $this->assertCount(23, $line);
        }
    }

    public function testCsvAndXlsxContainTheExactSameValuesInTheSameOrder(): void
    {
        $rows = $this->fixtureRows();

        $csv  = $this->writeAndReadCsv($rows);
        $xlsx = $this->writeAndReadXlsx($rows);

        foreach ($rows as $index => $sourceRow) {
            foreach (self::COLUMNS as $column) {
                $expected = $sourceRow[$column] === null ? '' : (string) $sourceRow[$column];

                $this->assertSame(
                    $expected,
                    $csv[$index][$column],
                    "CSV mismatch on row {$index}, column {$column}"
                );
                $this->assertSame(
                    $expected,
                    $xlsx[$index][$column],
                    "XLSX mismatch on row {$index}, column {$column}"
                );
            }
        }
    }

    public function testSegmentationValuesAreIdenticalBetweenBothFormats(): void
    {
        $rows = $this->fixtureRows();

        $csv  = array_column($this->writeAndReadCsv($rows), 'SEGMENTATION');
        $xlsx = array_column($this->writeAndReadXlsx($rows), 'SEGMENTATION');

        $this->assertSame($csv, $xlsx);
        $this->assertSame(['RFM 1', 'Standard', '8 Autre'], $csv);
    }
}
