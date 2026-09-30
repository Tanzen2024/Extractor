<?php

namespace App\Commands;

use App\Models\ExportJobModel;
use App\Services\Export\ExportJobRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Worker for asynchronous CUSTOMERS_LIST exports.
 *
 *   php spark export:process            run every pending job, then exit (cron-friendly)
 *   php spark export:process --watch    keep polling every 5s (dev / long-lived worker)
 *
 * Deploy as a cron entry, e.g. every minute:
 *   * * * * * cd /path/to/app && php spark export:process >> writable/logs/export-worker.log 2>&1
 */
class ProcessExportJobs extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'export:process';
    protected $description  = 'Generates the files for queued CUSTOMERS_LIST export jobs.';
    protected $usage        = 'export:process [--watch] [--sleep=5]';
    protected $options      = [
        '--watch' => 'Keep running, polling for new jobs instead of exiting when the queue is empty.',
        '--sleep' => 'Seconds to wait between polls in --watch mode (default 5).',
    ];

    /** Minimum gap between two progress UPDATEs of the same job. */
    private const PROGRESS_MIN_INTERVAL_SECONDS = 1.0;

    public function run(array $params): int
    {
        $watch  = CLI::getOption('watch') !== null;
        $sleep  = max(1, (int) (CLI::getOption('sleep') ?? 5));
        $jobs   = new ExportJobModel();
        $runner = new ExportJobRunner($jobs, null, self::PROGRESS_MIN_INTERVAL_SECONDS);

        do {
            $processed = 0;

            while (($job = $jobs->claimNext()) !== null) {
                $this->process($runner, $job);
                $processed++;
            }

            if ($watch) {
                if ($processed === 0) {
                    sleep($sleep);
                }
            }
        } while ($watch);

        return EXIT_SUCCESS;
    }

    private function process(ExportJobRunner $runner, array $job): void
    {
        $id = (int) $job['id'];
        CLI::write("Job #{$id} ({$job['format']}) — démarrage...", 'yellow');

        // One job's cancellation (cooperative, see ExportJobRunner) only ever
        // ends that job: the loop goes on with the next pending one.
        match ($runner->run($job)) {
            ExportJobRunner::DONE => CLI::write("Job #{$id} — terminé : {$runner->last['meta']['rows']} lignes, "
                . round($runner->last['meta']['fileSize'] / 1048576, 1) . ' Mo'
                . " ({$runner->last['progressWrites']} mises à jour de progression).", 'green'),
            ExportJobRunner::CANCELLED => CLI::write("Job #{$id} — annulé par l'utilisateur ({$runner->last['detail']}).", 'light_gray'),
            default => CLI::write("Job #{$id} — échec ({$runner->last['reference']}). Voir les logs.", 'red'),
        };
    }
}
