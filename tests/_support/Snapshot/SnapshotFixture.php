<?php

namespace Tests\Support\Snapshot;

use App\Services\Snapshot\SnapshotStore;
use Config\Snapshot as SnapshotConfig;

/**
 * Builds snapshot deliveries (customers_list.csv + customers_list.manifest)
 * in a throw-away snapshot area, the way the source server's push script
 * does: '#'-separated, header first, manifest computed from the real file
 * (stat size, sha256, newline count, header field count).
 */
final class SnapshotFixture
{
    /** A 27-column header, same names/order as app/Models/Extractor.sql. */
    public const HEADER = [
        'REGION', 'DIVISION', 'AGENCE', 'COD_UNICOM', 'COD_CLI', 'CONTRACT', 'STATUS',
        'METER_NO', 'CUST_NAME', 'PHONE_NUMBERS', 'E_MAIL', 'REF_GEO', 'DATE_AB',
        'DATE_RESILIATION', 'VOLTAGE', 'SEGMENT_TRESOR', 'METER', 'NIU_RIGHT', 'XCOORD',
        'YCOORD', 'NIU_TO_RECLASS', 'NUI_QC', 'LAST_VC_DATE', 'SEGMENT_RFM_2',
        'POSTPAID_PROFILE_DATE', 'SEGMENTATION', 'UPDATED_AT',
    ];

    public readonly string $baseDir;
    public readonly SnapshotConfig $config;
    public readonly SnapshotStore $store;

    public function __construct(int $keepVersions = 2)
    {
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_snap_' . bin2hex(random_bytes(5));

        $config               = new SnapshotConfig();
        $config->baseDir      = $this->baseDir;
        $config->exportSource = 'snapshot';
        $config->keepVersions = $keepVersions;
        // Never touch the real writable/data/customers_list.csv from a test.
        $config->publishedLink   = $this->baseDir . DIRECTORY_SEPARATOR . 'customers_list.csv';
        $config->refreshTmpFile  = $this->baseDir . DIRECTORY_SEPARATOR . 'customers_list.csv.tmp';
        $config->refreshLockFile = $this->baseDir . DIRECTORY_SEPARATOR . 'customers_refresh.lock';
        $config->refreshLogFile  = $this->baseDir . DIRECTORY_SEPARATOR . 'customers_refresh.log';
        $this->config         = $config;
        $this->store          = new SnapshotStore($config);
        $this->store->ensureLayout();
    }

    public function cleanup(): void
    {
        SnapshotStore::deleteTree($this->baseDir);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    public static function row(int $n, array $overrides = []): array
    {
        $row = [
            'REGION' => 'DRC', 'DIVISION' => 'DIV A', 'AGENCE' => 'AG 1', 'COD_UNICOM' => (string) (10000 + $n),
            'COD_CLI' => 'CLI' . $n, 'CONTRACT' => (string) (900000 + $n), 'STATUS' => 'ACTIVE',
            'METER_NO' => 'M' . $n, 'CUST_NAME' => 'Client Élève ' . $n, 'PHONE_NUMBERS' => '690000000',
            'E_MAIL' => ' ', 'REF_GEO' => 'G' . $n, 'DATE_AB' => '2020-01-15', 'DATE_RESILIATION' => '',
            'VOLTAGE' => 'LV', 'SEGMENT_TRESOR' => 'PRIVATE', 'METER' => 'PREPAID', 'NIU_RIGHT' => 'P0' . $n,
            'XCOORD' => '9.7', 'YCOORD' => '4.05', 'NIU_TO_RECLASS' => 'N', 'NUI_QC' => 'NUI correct',
            'LAST_VC_DATE' => '2026-08-01', 'SEGMENT_RFM_2' => 'Stable', 'POSTPAID_PROFILE_DATE' => '',
            'SEGMENTATION' => '1 PERFECT', 'UPDATED_AT' => '2026-09-25 06:00:00',
        ];

        return array_merge($row, $overrides);
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string>|null           $header
     */
    public static function csv(array $rows, ?array $header = null): string
    {
        $header ??= self::HEADER;
        $out      = implode('#', $header) . "\n";
        foreach ($rows as $row) {
            $out .= implode('#', array_map(static fn (string $col): string => $row[$col] ?? '', $header)) . "\n";
        }

        return $out;
    }

    /**
     * Manifest exactly as the source script computes it from $content.
     *
     * @param array<string, string> $overrides
     */
    public static function manifest(string $content, array $overrides = []): string
    {
        $firstLine = strtok($content, "\n");
        $values    = array_merge([
            'file'         => 'customers_list.csv',
            'size'         => (string) strlen($content),
            'sha256'       => hash('sha256', $content),
            'lines'        => (string) substr_count($content, "\n"),
            'columns'      => (string) count(explode('#', rtrim((string) $firstLine, "\r"))),
            'delimiter'    => '#',
            'encoding'     => 'UTF-8',
            'header'       => rtrim((string) $firstLine, "\r"),
            'generated_at' => '2026-09-25T06:12:03+01:00',
            'source_host'  => 'source-test',
        ], $overrides);

        $out = '';
        foreach ($values as $k => $v) {
            $out .= "{$k}={$v}\n";
        }

        return $out;
    }

    /**
     * Drops a finished delivery in incoming/. $manifestFor lets a test
     * describe a different file than the one delivered (e.g. a truncated
     * transfer: manifest of the full file, CSV cut short).
     *
     * @param array<string, string> $manifestOverrides
     */
    public function deliver(string $csvContent, array $manifestOverrides = [], ?string $manifestFor = null): void
    {
        $incoming = $this->store->dir('incoming') . DIRECTORY_SEPARATOR;
        file_put_contents($incoming . 'customers_list.csv', $csvContent);
        file_put_contents($incoming . 'customers_list.manifest', self::manifest($manifestFor ?? $csvContent, $manifestOverrides));
    }

    /**
     * Delivers and installs; returns the installer result.
     *
     * @param list<array<string, string>> $rows
     *
     * @return array<string, mixed>
     */
    public function install(array $rows): array
    {
        $this->deliver(self::csv($rows));

        return (new \App\Services\Snapshot\SnapshotInstaller($this->store))->install();
    }
}
