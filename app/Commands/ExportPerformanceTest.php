<?php

namespace App\Commands;

use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\OracleExtractionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Full-table performance test of the CUSTOMERS_LIST export, CSV then XLSX.
 *
 *   php spark export:benchmark
 *
 * Runs the real CustomerListExportService::exportCsv() / exportXlsx() with
 * FilterCriteria::none() against the real Oracle source — no row cap, no
 * test table. The two formats run one after the other, never concurrently;
 * each generated file is validated (row count, zip integrity, sheet count)
 * outside the timed window, then deleted.
 *
 * Rows are counted as they are handed to the writer (a thin stream()
 * wrapper increments after each successful writeRow), never from COUNT(*).
 * A COUNT(*) is only shown afterwards, outside the timed window, as a
 * consistency reference.
 *
 * Read-only on Oracle. Dev tooling only — never wired into a route or a
 * scheduled task. See ExportBenchmark (export:bench) for capped partial runs.
 */
class ExportPerformanceTest extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'export:benchmark';
    protected $description = 'Full CUSTOMERS_LIST export performance test (CSV then XLSX, no filter, real Oracle).';
    protected $usage       = 'export:benchmark [--only csv|xlsx] [--limit N]';
    protected $options     = [
        '--only'  => 'Run a single format (csv or xlsx) instead of CSV then XLSX.',
        '--limit' => 'Smoke test only: cap each export at N rows (result flagged PARTIEL, not a benchmark).',
    ];

    private const PROGRESS_EVERY = 100_000;

    public function run(array $params): int
    {
        $source = CustomerListExportService::SQL_TABLE;
        $limit  = max(0, (int) (CLI::getOption('limit') ?? 0));
        $only   = strtolower((string) (CLI::getOption('only') ?? ''));
        $formats = match ($only) {
            ''      => ['csv', 'xlsx'],
            'csv'   => ['csv'],
            'xlsx'  => ['xlsx'],
            default => null,
        };
        if ($formats === null) {
            CLI::error("--only doit valoir 'csv' ou 'xlsx'");

            return EXIT_ERROR;
        }

        CLI::write('========================================');
        CLI::write('        EXPORT PERFORMANCE TEST');
        CLI::write('========================================');
        CLI::write('');
        CLI::write('Source :');
        CLI::write($source);
        CLI::write('');
        CLI::write('Filtres :');
        CLI::write('Aucun (FilterCriteria::none())');
        if ($limit > 0) {
            CLI::write('');
            CLI::write("SMOKE TEST PARTIEL (--limit {$limit}) — résultats NON valides comme benchmark.", 'red');
        }
        CLI::write('');
        CLI::write('Début : ' . date('Y-m-d H:i:s'));
        CLI::write('');

        $oracle = new ExportPerformanceCountingOracle(self::PROGRESS_EVERY, $limit);

        // --- Warm-up (not timed, not counted) ---------------------------------
        CLI::write('----------------------------------------');
        CLI::write('WARM-UP (non comptabilisé)');
        CLI::write('----------------------------------------');

        try {
            $this->warmUp($oracle, $source);
        } catch (Throwable $e) {
            CLI::error('WARM-UP : ECHEC');
            CLI::error('Erreur : ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $results = [];

        foreach ($formats as $format) {
            $label = strtoupper($format);

            CLI::write('');
            CLI::write('----------------------------------------');
            CLI::write($label);
            CLI::write('----------------------------------------');

            try {
                $results[$format] = $this->runOne($oracle, $format, $source);
            } catch (Throwable $e) {
                CLI::error("{$label} : ECHEC");
                CLI::error('Erreur : ' . $e->getMessage());
                CLI::error('(' . $e::class . ' @ ' . $e->getFile() . ':' . $e->getLine() . ')');

                if ($format === 'csv') {
                    CLI::error('Benchmark arrêté.');
                }

                return EXIT_ERROR;
            }
        }

        if (isset($results['csv'], $results['xlsx'])) {
            $this->printComparison($results['csv'], $results['xlsx']);
        }

        return EXIT_SUCCESS;
    }

    private function warmUp(ExportPerformanceCountingOracle $oracle, string $source): void
    {
        $sample = 0;
        $oracle->stream("SELECT 1 AS PROBE FROM {$source} WHERE ROWNUM <= 1", static function () use (&$sample): void {
            $sample++;
        }, counting: false);

        if ($sample !== 1) {
            throw new RuntimeException("La table {$source} est accessible mais ne renvoie aucune ligne.");
        }
        CLI::write("Connexion Oracle + lecture {$source} : OK");

        foreach ([WRITEPATH . 'uploads/exports', WRITEPATH . 'tmp/openspout'] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Répertoire introuvable/non créable : {$dir}");
            }
            $probe = $dir . DIRECTORY_SEPARATOR . 'bench_probe_' . bin2hex(random_bytes(4));
            if (@file_put_contents($probe, 'x') !== 1) {
                throw new RuntimeException("Écriture impossible dans {$dir}");
            }
            @unlink($probe);
            CLI::write("Écriture dans {$dir} : OK");
        }

        $free = @disk_free_space(WRITEPATH);
        if ($free !== false) {
            CLI::write(sprintf('Espace disque libre : %s', $this->formatSize((int) $free)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runOne(ExportPerformanceCountingOracle $oracle, string $format, string $source): array
    {
        $scratchBefore = $this->openSpoutScratchDirs();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $oracle->reset();

        // Timed window: service init (orphan sweep, export dir) -> Oracle
        // extraction -> row writing -> fclose() / OpenSpout close() + zip
        // finalisation + scratch cleanup — everything exportCsv()/exportXlsx()
        // does before returning.
        $start   = microtime(true);
        $service = new CustomerListExportService($oracle);
        $meta    = $format === 'xlsx'
            ? $service->exportXlsx(FilterCriteria::none())
            : $service->exportCsv(FilterCriteria::none());
        $elapsed = microtime(true) - $start;

        $peakMb    = memory_get_peak_usage(true) / 1048576;
        $currentMb = memory_get_usage(true) / 1048576;
        $counted   = $oracle->rowsWritten();
        $path      = $meta['path'];

        clearstatcache(true, $path);
        if (! is_file($path)) {
            throw new RuntimeException("Fichier généré introuvable : {$path}");
        }
        $size = (int) filesize($path);

        CLI::write('');
        CLI::write('Export terminé (' . date('H:i:s') . '). Validation du fichier (hors chronométrage)...');

        try {
            $check = $format === 'xlsx' ? $this->validateXlsx($path) : $this->validateCsv($path);
        } finally {
            @unlink($path);
            clearstatcache(true, $path);
        }
        $deleted = ! is_file($path);

        $leftover = array_diff($this->openSpoutScratchDirs(), $scratchBefore);

        $totalRows = null;
        $oracle->stream("SELECT COUNT(*) AS N FROM {$source}", static function (array $row) use (&$totalRows): void {
            $totalRows = (int) $row['N'];
        }, counting: false);

        $rate = $counted / max($elapsed, 0.000001);

        CLI::write('');
        CLI::write('Fichier          : ' . $meta['filename']);
        CLI::write('Lignes extraites : ' . $this->formatInt($counted));
        CLI::write('Temps total      : ' . sprintf('%.2f s', $elapsed));
        CLI::write('Vitesse          : ' . $this->formatInt((int) round($rate)) . ' lignes/s');
        CLI::write('Taille fichier   : ' . $this->formatSize($size) . ' (' . $this->formatInt($size) . ' octets)');
        CLI::write('');
        CLI::write('Détail (mesures internes du service) :');
        CLI::write(sprintf('  SQL -> 1re ligne : %.2f s', $meta['sqlDurationMs'] / 1000));
        CLI::write(sprintf('  Fetch Oracle     : %.2f s', $meta['fetchDurationMs'] / 1000));
        CLI::write(sprintf('  Écriture%s : %.2f s', $format === 'xlsx' ? ' + close' : '        ', $meta['writeDurationMs'] / 1000));
        CLI::write(sprintf('  Mémoire PHP pic  : %.1f MB', $peakMb));
        CLI::write(sprintf('  Mémoire PHP fin  : %.1f MB', $currentMb));
        if ($format === 'xlsx') {
            CLI::write('  Feuilles XLSX    : ' . $meta['sheets']);
        }
        CLI::write('');
        CLI::write('Validation :');
        CLI::write('  Fichier créé              : oui');
        foreach ($check['lines'] as $line) {
            CLI::write('  ' . $line);
        }
        CLI::write('  Lignes (compteur export)  : ' . $this->formatInt($counted));
        CLI::write('  Lignes (retour service)   : ' . $this->formatInt($meta['rows']));
        CLI::write('  COUNT(*) après export     : ' . $this->formatInt((int) $totalRows) . ' (référence, hors chrono)');
        CLI::write('  Fichier supprimé          : ' . ($deleted ? 'oui' : 'NON'));
        if ($format === 'xlsx') {
            CLI::write('  Dossier temp OpenSpout    : ' . ($leftover === [] ? 'supprimé' : 'RESTANT ' . implode(', ', $leftover)));
        }

        $problems = [];
        if ($counted !== $meta['rows']) {
            $problems[] = "compteur ({$counted}) != retour service ({$meta['rows']})";
        }
        if ($check['dataRows'] !== $counted) {
            $problems[] = "lignes relues dans le fichier ({$check['dataRows']}) != lignes exportées ({$counted})";
        }
        if ($format === 'xlsx' && $check['sheets'] !== $meta['sheets']) {
            $problems[] = "feuilles dans le zip ({$check['sheets']}) != feuilles déclarées ({$meta['sheets']})";
        }
        foreach ($check['problems'] as $p) {
            $problems[] = $p;
        }
        if ($problems !== []) {
            throw new RuntimeException('export incohérent — ' . implode(' ; ', $problems));
        }
        if ($oracle->limit() === 0 && $totalRows !== $counted) {
            CLI::write('  NOTE : COUNT(*) actuel différent du nombre exporté (la table a pu évoluer pendant le test).', 'yellow');
        }

        return [
            'rows'   => $counted,
            'time'   => $elapsed,
            'rate'   => $rate,
            'size'   => $size,
            'sheets' => $meta['sheets'],
        ];
    }

    /**
     * Re-reads the CSV with the same dialect CsvRowWriter uses (';', '"',
     * '\\'), so quoted embedded newlines count as one record.
     *
     * @return array{dataRows:int, sheets:int, lines:list<string>, problems:list<string>}
     */
    private function validateCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Relecture CSV impossible.');
        }

        $problems = [];
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header  = fgetcsv($handle, null, ';', '"', '\\');
        $records = 0;
        while (fgetcsv($handle, null, ';', '"', '\\') !== false) {
            $records++;
        }
        fclose($handle);

        if ($header !== CustomerListExportService::COLUMNS) {
            $problems[] = 'en-tête CSV inattendu';
        }

        return [
            'dataRows' => $records,
            'sheets'   => 1,
            'lines'    => [
                'Ligne d\'en-tête          : ' . ($header !== false ? '1' : '0'),
                'Lignes de données relues  : ' . $this->formatInt($records),
                'Total lignes fichier      : ' . $this->formatInt($records + ($header !== false ? 1 : 0)),
            ],
            'problems' => $problems,
        ];
    }

    /**
     * Opens the workbook as a zip with a consistency check, then streams each
     * worksheet XML and counts its <row> elements (one header row per sheet —
     * see XlsxRowWriter::startSheet()).
     *
     * @return array{dataRows:int, sheets:int, lines:list<string>, problems:list<string>}
     */
    private function validateXlsx(string $path): array
    {
        $zip    = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException("Fichier XLSX non valide (ZipArchive code {$opened}).");
        }

        $problems = [];
        foreach (['[Content_Types].xml', 'xl/workbook.xml'] as $required) {
            if ($zip->locateName($required) === false) {
                $problems[] = "entrée zip manquante : {$required}";
            }
        }

        $sheetFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
                $sheetFiles[(int) $m[1]] = $name;
            }
        }
        ksort($sheetFiles);

        $lines     = ['Fichier ZIP valide        : oui (CHECKCONS)', 'Feuilles                  : ' . count($sheetFiles)];
        $totalXml  = 0;
        $dataRows  = 0;
        foreach ($sheetFiles as $n => $name) {
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException("Lecture impossible de {$name}.");
            }

            $rowTags = 0;
            $tail    = '';
            while (! feof($stream)) {
                $chunk = fread($stream, 1 << 20);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer   = $tail . $chunk;
                $rowTags += preg_match_all('/<row[\s>]/', $buffer);
                // Keep a short tail so a tag split across two chunks is seen
                // once; strip anything already fully matched from it.
                $tail = substr($buffer, -5);
                if (preg_match('/<row[\s>]/', $tail)) {
                    $tail = '';
                }
            }
            fclose($stream);

            $totalXml += $rowTags;
            $dataRows += max(0, $rowTags - 1);
            $lines[]   = sprintf('  Feuille %d : %s lignes (dont 1 en-tête)', $n, $this->formatInt($rowTags));
        }
        $zip->close();

        $lines[] = 'Lignes d\'en-tête          : ' . count($sheetFiles) . ' (1 par feuille)';
        $lines[] = 'Lignes de données relues  : ' . $this->formatInt($dataRows);
        $lines[] = 'Total lignes fichier      : ' . $this->formatInt($totalXml);

        return ['dataRows' => $dataRows, 'sheets' => count($sheetFiles), 'lines' => $lines, 'problems' => $problems];
    }

    /**
     * @param array<string, mixed> $csv
     * @param array<string, mixed> $xlsx
     */
    private function printComparison(array $csv, array $xlsx): void
    {
        CLI::write('');
        CLI::write('========================================');
        CLI::write('RÉSULTATS');
        CLI::write('========================================');
        CLI::table([
            ['CSV', $this->formatInt($csv['rows']), sprintf('%.2f s', $csv['time']), $this->formatInt((int) round($csv['rate'])) . ' lignes/s', $this->formatSize($csv['size'])],
            ['XLSX', $this->formatInt($xlsx['rows']), sprintf('%.2f s', $xlsx['time']), $this->formatInt((int) round($xlsx['rate'])) . ' lignes/s', $this->formatSize($xlsx['size'])],
        ], ['FORMAT', 'NOMBRE DE LIGNES', 'TEMPS', 'LIGNES/SEC', 'TAILLE']);

        CLI::write('');
        CLI::write('========================================');
        CLI::write('COMPARAISON');
        CLI::write('========================================');
        CLI::write('');
        CLI::write('CSV :');
        CLI::write(sprintf('%.2f s', $csv['time']));
        CLI::write('');
        CLI::write('XLSX :');
        CLI::write(sprintf('%.2f s', $xlsx['time']));
        CLI::write('');
        CLI::write('Différence :');
        CLI::write(sprintf('%.2f s', $xlsx['time'] - $csv['time']));
        CLI::write('');
        CLI::write('Rapport XLSX/CSV :');
        CLI::write(sprintf('%.2fx', $xlsx['time'] / max($csv['time'], 0.000001)));
        CLI::write('');
        CLI::write('Fin : ' . date('Y-m-d H:i:s'));
        CLI::write('========================================');
    }

    /** @return list<string> */
    private function openSpoutScratchDirs(): array
    {
        return glob(WRITEPATH . 'tmp/openspout/export_*', GLOB_ONLYDIR) ?: [];
    }

    private function formatInt(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private function formatSize(int $bytes): string
    {
        return sprintf('%.1f MB', $bytes / 1048576);
    }
}

/**
 * The production OracleExtractionService with one addition: it counts the
 * rows whose onRow callback (i.e. the writer's writeRow()) returned without
 * throwing, and prints a progress line every $progressEvery rows. The SQL,
 * binds and prefetch are passed through unchanged.
 */
class ExportPerformanceCountingOracle extends OracleExtractionService
{
    private int $rows = 0;
    private float $t0 = 0.0;

    public function __construct(private readonly int $progressEvery, private readonly int $limit = 0)
    {
        parent::__construct();
    }

    public function reset(): void
    {
        $this->rows = 0;
        $this->t0   = microtime(true);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function rowsWritten(): int
    {
        return $this->rows;
    }

    public function stream(string $sql, callable $onRow, array $binds = [], ?int $prefetch = null, bool $counting = true): int
    {
        if (! $counting) {
            return parent::stream($sql, $onRow, $binds, $prefetch);
        }

        if ($this->limit > 0) {
            $sql = "SELECT * FROM (\n{$sql}\n) WHERE ROWNUM <= {$this->limit}";
        }

        return parent::stream($sql, function (array $row) use ($onRow): void {
            $onRow($row);

            if (++$this->rows % $this->progressEvery === 0) {
                $elapsed = microtime(true) - $this->t0;
                CLI::write(sprintf(
                    '  %s lignes... (%.1f s, %s lignes/s, mém. %.0f MB)',
                    number_format($this->rows, 0, ',', ' '),
                    $elapsed,
                    number_format((int) ($this->rows / max($elapsed, 0.001)), 0, ',', ' '),
                    memory_get_usage(true) / 1048576,
                ));
            }
        }, $binds, $prefetch);
    }
}
