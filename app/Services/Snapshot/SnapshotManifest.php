<?php

namespace App\Services\Snapshot;

/**
 * The metadata the source server computes for one customers_list.csv and
 * pushes next to it (customers_list.manifest). Plain `key=value` lines —
 * trivial to produce from a shell script, and a header containing '#' or '='
 * survives because only the first '=' of a line is a separator.
 *
 *   file=customers_list.csv
 *   size=<bytes>                stat -c%s   (recomputed on every generation)
 *   sha256=<64 hex>             sha256sum
 *   lines=<n>                   wc -l (newline count, header included)
 *   columns=<n>                 fields in the header (27 for today's source)
 *   delimiter=#
 *   encoding=UTF-8
 *   header=REGION#DIVISION#...  head -1, CR stripped
 *   generated_at=2026-09-25T06:12:03+01:00   (optional)
 *   source_host=...                          (optional)
 *
 * Nothing is defaulted or guessed: a missing or malformed required key
 * rejects the delivery.
 */
final class SnapshotManifest
{
    private const REQUIRED = ['size', 'sha256', 'lines', 'columns', 'delimiter', 'header'];

    /**
     * @param array<string, string> $raw All keys as received (for the version meta).
     */
    private function __construct(
        public readonly int $size,
        public readonly string $sha256,
        public readonly int $lines,
        public readonly int $columns,
        public readonly string $delimiter,
        public readonly string $header,
        public readonly ?string $generatedAt,
        public readonly ?string $sourceHost,
        public readonly array $raw,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new SnapshotException('manifest_unreadable', 'Manifeste illisible.');
        }

        $raw = [];
        $n   = 0;
        while (($line = fgets($handle, 65536)) !== false) {
            if (++$n > 100) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false || $eq === 0) {
                fclose($handle);

                throw new SnapshotException('manifest_invalid', "Ligne de manifeste invalide (ligne {$n}).");
            }
            $raw[strtolower(trim(substr($line, 0, $eq)))] = substr($line, $eq + 1);
        }
        fclose($handle);

        return self::fromArray($raw);
    }

    /**
     * @param array<string, string> $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (self::REQUIRED as $key) {
            if (! isset($raw[$key]) || trim($raw[$key]) === '') {
                throw new SnapshotException('manifest_invalid', "Clé obligatoire absente du manifeste : {$key}.");
            }
        }

        $int = static function (string $key) use ($raw): int {
            $value = trim($raw[$key]);
            if (preg_match('/^\d{1,15}$/', $value) !== 1) {
                throw new SnapshotException('manifest_invalid', "Valeur non entière pour {$key} dans le manifeste.");
            }

            return (int) $value;
        };

        $sha = strtolower(trim($raw['sha256']));
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new SnapshotException('manifest_invalid', 'SHA-256 du manifeste invalide.');
        }

        // Taken verbatim (no trim): a single-character separator.
        $delimiter = $raw['delimiter'];
        if (strlen($delimiter) !== 1 || in_array($delimiter, ["\r", "\n", '"'], true)) {
            throw new SnapshotException('manifest_invalid', 'Séparateur du manifeste invalide.');
        }

        if (isset($raw['encoding']) && strtoupper(str_replace('-', '', trim($raw['encoding']))) !== 'UTF8') {
            throw new SnapshotException('manifest_invalid', 'Encodage déclaré non supporté (UTF-8 attendu).');
        }

        $size    = $int('size');
        $lines   = $int('lines');
        $columns = $int('columns');

        if ($size <= 0) {
            throw new SnapshotException('manifest_invalid', 'Taille déclarée nulle.');
        }
        if ($lines < 2) {
            throw new SnapshotException('manifest_invalid', "Le manifeste déclare moins de 2 lignes (en-tête + données).");
        }
        if ($columns < 1) {
            throw new SnapshotException('manifest_invalid', 'Nombre de colonnes déclaré invalide.');
        }

        $optional = static fn (string $key): ?string => isset($raw[$key]) && trim($raw[$key]) !== '' ? trim($raw[$key]) : null;

        return new self(
            size: $size,
            sha256: $sha,
            lines: $lines,
            columns: $columns,
            delimiter: $delimiter,
            header: self::stripBom(rtrim($raw['header'], "\r\n")),
            generatedAt: $optional('generated_at'),
            sourceHost: $optional('source_host'),
            raw: $raw,
        );
    }

    public static function stripBom(string $line): string
    {
        return str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line;
    }
}
