<?php

namespace App\Commands;

use App\Services\Export\ExportDoctor as Doctor;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Environment check for asynchronous exports (DB, export_jobs schema,
 * migrations, PHP, writable directories, snapshot, worker).
 *
 *   php spark export:doctor
 *   sudo -u www-data php spark export:doctor           (as the worker's user)
 *   sudo -u www-data php spark export:doctor --fix     (+ create missing directories)
 *   php spark export:doctor --preflight                (systemd ExecStartPre: directories only)
 *
 * --fix / --preflight only ever create missing directories, as the current
 * user and never as root; they change no permission and run no shell
 * command. A directory only root can repair is reported with the system
 * command to run (docs/deploy/prepare-writable.sh).
 *
 * Exit code: 0 = OK, INFO or warnings only, 1 = at least one ERROR — usable
 * as a post-deployment gate. No secret is ever printed.
 */
class ExportDoctor extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'export:doctor';
    protected $description = 'Checks everything asynchronous exports need (DB schema, migrations, PHP, permissions, worker).';
    protected $usage       = 'export:doctor [--fix] [--preflight]';
    protected $options     = [
        '--fix'       => 'Also create the missing writable directories (as the current user, never as root).',
        '--preflight' => 'Directories only, missing ones created; exit 1 if the worker could not write (systemd ExecStartPre).',
    ];

    private const COLORS = [Doctor::OK => 'green', Doctor::INFO => 'cyan', Doctor::WARNING => 'yellow', Doctor::ERROR => 'red'];

    private const CATEGORIES = [
        'configuration' => 'Configuration',
        'filesystem'    => 'Filesystem',
        'data'          => 'Données (snapshot)',
        'worker'        => 'Worker',
        'history'       => 'Historique des jobs',
    ];

    public function run(array $params): int
    {
        $preflight = CLI::getOption('preflight') !== null;
        $doctor    = new Doctor(fix: CLI::getOption('fix') !== null);
        $results   = $preflight ? $doctor->preflight() : $doctor->run();
        $width     = max(array_map(static fn (array $r): int => strlen($r['check']), $results));

        foreach ($results as $result) {
            $lines = explode("\n", $result['detail']);
            $text  = CLI::color(str_pad($result['level'], 7), self::COLORS[$result['level']]) . ' '
                . str_pad($result['check'], $width) . '  ' . array_shift($lines);
            foreach ($lines as $line) {
                $text .= "\n" . str_repeat(' ', 8 + $width + 2) . $line;
            }

            // ERROR on stderr: in the journal with priority "err".
            $result['level'] === Doctor::ERROR ? CLI::error($text) : CLI::write($text);
        }

        $worst = Doctor::worstLevel($results);
        CLI::newLine();

        if (! $preflight) {
            foreach ($this->byCategory($results) as $category => $level) {
                $label = self::CATEGORIES[$category] . ' :';
                CLI::write($label . str_repeat(' ', max(1, 22 - mb_strlen($label))) . CLI::color($level, self::COLORS[$level]));
            }
        }

        CLI::write(($preflight ? 'Préflight worker : ' : 'Résultat : ') . $worst, self::COLORS[$worst]);

        return $worst === Doctor::ERROR ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /** @return array<string, string> category => worst level (INFO counts as OK) */
    private function byCategory(array $results): array
    {
        $grouped = [];
        foreach ($results as $result) {
            $grouped[Doctor::category($result['check'])][] = $result;
        }

        $summary = [];
        foreach (array_keys(self::CATEGORIES) as $category) {
            if (isset($grouped[$category])) {
                $worst              = Doctor::worstLevel($grouped[$category]);
                $summary[$category] = $category === 'history' && $worst === Doctor::OK ? Doctor::INFO : $worst;
            }
        }

        return $summary;
    }
}
