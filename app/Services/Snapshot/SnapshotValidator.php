<?php

namespace App\Services\Snapshot;

use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;

/**
 * Decides whether a received customers_list.csv may become the active
 * snapshot. Every expectation comes from the delivery's own manifest (size,
 * SHA-256, line count, column count, header) — nothing about the file's
 * size or shape is hard-coded here — plus the one thing Extractor itself
 * needs: every column it exports or filters on must exist in the header.
 *
 * Order (cheapest first, each failure is final):
 *   1. file present, non-empty, size == manifest size
 *   2. SHA-256 == manifest SHA-256 (hash_file streams; the transfer is intact)
 *   3. one streaming pass:
 *        header == manifest header, field count == manifest columns,
 *        unique names, required columns present;
 *        every record valid UTF-8 with exactly `columns` fields;
 *        at least one data record; newline count == manifest lines
 *      and, in the same pass, the filter options + DATE_AB format for meta.
 */
final class SnapshotValidator
{
    /**
     * @param list<string> $dateFormats Candidates for DATE_AB (Config\Snapshot::$dateFormats).
     */
    public function __construct(private readonly array $dateFormats)
    {
    }

    /**
     * @return array<string, mixed> Computed facts for the version meta.
     *
     * @throws SnapshotException with a stable `reason` on the first failure.
     */
    public function validate(string $csvPath, SnapshotManifest $manifest): array
    {
        $t0 = microtime(true);

        clearstatcache(true, $csvPath);
        if (! is_file($csvPath)) {
            throw new SnapshotException('file_missing', 'Fichier CSV absent.');
        }
        $size = (int) filesize($csvPath);
        if ($size === 0) {
            throw new SnapshotException('file_empty', 'Fichier CSV vide.');
        }
        if ($size !== $manifest->size) {
            throw new SnapshotException('size_mismatch', "Taille reçue {$size} octets ≠ taille source {$manifest->size} octets (transfert incomplet ?).");
        }

        $tHash = microtime(true);
        $sha   = hash_file('sha256', $csvPath);
        if ($sha === false) {
            throw new SnapshotException('file_unreadable', 'Lecture impossible pour le calcul du SHA-256.');
        }
        if (! hash_equals($manifest->sha256, $sha)) {
            throw new SnapshotException('sha256_mismatch', 'SHA-256 reçu différent du SHA-256 source.');
        }
        $hashSeconds = microtime(true) - $tHash;

        $tScan = microtime(true);
        $scan  = $this->scan($csvPath, $manifest);

        return $scan + [
            'size'             => $size,
            'sha256'           => $sha,
            'hash_seconds'     => round($hashSeconds, 2),
            'scan_seconds'     => round(microtime(true) - $tScan, 2),
            'validate_seconds' => round(microtime(true) - $t0, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(string $csvPath, SnapshotManifest $manifest): array
    {
        $handle = @fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new SnapshotException('file_unreadable', 'Fichier CSV illisible.');
        }
        stream_set_read_buffer($handle, 1 << 20);

        try {
            $first = fgets($handle);
            if ($first === false) {
                throw new SnapshotException('header_missing', 'En-tête absent.');
            }
            $newlines   = str_ends_with($first, "\n") ? 1 : 0;
            $headerLine = SnapshotManifest::stripBom(rtrim($first, "\r\n"));

            if (preg_match('//u', $headerLine) !== 1) {
                throw new SnapshotException('encoding_invalid', 'En-tête non UTF-8.');
            }
            if ($headerLine !== $manifest->header) {
                throw new SnapshotException('header_mismatch', 'En-tête reçu différent de l\'en-tête source.');
            }

            $header = SnapshotReader::split($headerLine, $manifest->delimiter);
            $cols   = count($header);
            if ($cols !== $manifest->columns) {
                throw new SnapshotException('column_count_mismatch', "En-tête de {$cols} colonnes ≠ {$manifest->columns} colonnes déclarées par la source.");
            }

            $index = SnapshotReader::indexOf($header);
            if (count($index) !== $cols) {
                throw new SnapshotException('header_duplicate', 'Noms de colonnes en double dans l\'en-tête.');
            }

            $missing = array_values(array_diff(self::requiredColumns(), array_keys($index)));
            if ($missing !== []) {
                throw new SnapshotException('columns_missing', 'Colonnes requises absentes : ' . implode(', ', $missing) . '.');
            }

            $options    = new SnapshotFilterOptions($index);
            $dateIdx    = $index[FilterCriteria::DATE_COLUMN];
            $dateFormat = null;
            $date       = null;
            $unparsed   = 0;
            $rows       = 0;
            $lineNo     = 1;

            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                if (str_ends_with($line, "\n")) {
                    $newlines++;
                }
                $line = rtrim($line, "\r\n");

                if (preg_match('//u', $line) !== 1) {
                    throw new SnapshotException('encoding_invalid', "Ligne {$lineNo} : UTF-8 invalide.");
                }

                $fields = explode($manifest->delimiter, $line);
                if (count($fields) !== $cols) {
                    throw new SnapshotException('row_column_mismatch', sprintf('Ligne %d : %d champs au lieu de %d.', $lineNo, count($fields), $cols));
                }

                // Detect on the first non-blank DATE_AB; give up after 1000
                // unreadable values rather than retrying every candidate on
                // millions of rows (the date filter is then refused).
                if ($date === null && $unparsed < 1000) {
                    $dateFormat = SnapshotDate::detect($fields[$dateIdx], $this->dateFormats);
                    if ($dateFormat !== null) {
                        $date = new SnapshotDate($dateFormat);
                    }
                }
                $ymd = $date?->toYmd($fields[$dateIdx]) ?? false;
                if ($ymd === false && trim($fields[$dateIdx]) !== '') {
                    $unparsed++;
                }

                $options->add($fields, $ymd);
                $rows++;
            }

            if (! feof($handle)) {
                throw new SnapshotException('file_unreadable', "Erreur de lecture après la ligne {$lineNo}.");
            }
        } finally {
            fclose($handle);
        }

        if ($rows === 0) {
            throw new SnapshotException('no_data', 'Aucune ligne de données après l\'en-tête.');
        }
        if ($newlines !== $manifest->lines) {
            throw new SnapshotException('line_count_mismatch', "{$newlines} lignes reçues ≠ {$manifest->lines} lignes déclarées par la source.");
        }

        return [
            'rows'              => $rows,
            'lines'             => $newlines,
            'columns'           => $cols,
            'header'            => $headerLine,
            'delimiter'         => $manifest->delimiter,
            'date_ab_format'    => $dateFormat,
            'date_ab_unparsed'  => $unparsed,
            'filter_options'    => $options->result(),
        ];
    }

    /**
     * Columns Extractor reads: everything it exports + everything it filters on.
     *
     * @return list<string>
     */
    public static function requiredColumns(): array
    {
        return array_values(array_unique(array_merge(
            CustomerListExportService::COLUMNS,
            array_keys(RowMatcher::LIST_FILTERS),
            [FilterCriteria::DATE_COLUMN],
        )));
    }
}
