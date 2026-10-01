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
 *   sudo -u www-data php spark export:doctor     (as the worker's user)
 *
 * Exit code: 0 = OK or warnings only, 1 = at least one ERROR — usable as a
 * post-deployment gate. No secret is ever printed.
 */
class ExportDoctor extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'export:doctor';
    protected $description = 'Checks everything asynchronous exports need (DB schema, migrations, PHP, permissions, worker).';
    protected $usage       = 'export:doctor';

    private const COLORS = [Doctor::OK => 'green', Doctor::WARNING => 'yellow', Doctor::ERROR => 'red'];

    public function run(array $params): int
    {
        $results = (new Doctor())->run();
        $width   = max(array_map(static fn (array $r): int => strlen($r['check']), $results));

        foreach ($results as $result) {
            CLI::write(
                CLI::color(str_pad($result['level'], 7), self::COLORS[$result['level']]) . ' '
                . str_pad($result['check'], $width) . '  ' . $result['detail'],
            );
        }

        $worst = Doctor::worstLevel($results);
        CLI::newLine();
        CLI::write('Résultat : ' . $worst, self::COLORS[$worst]);

        return $worst === Doctor::ERROR ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
