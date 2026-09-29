<?php

namespace App\Commands;

use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use App\Services\Export\ThrottledProgress;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

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
        $watch = CLI::getOption('watch') !== null;
        $sleep = max(1, (int) (CLI::getOption('sleep') ?? 5));
        $jobs  = new ExportJobModel();

        do {
            $processed = 0;

            while (($job = $jobs->claimNext()) !== null) {
                $this->process($jobs, $job);
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

    private function process(ExportJobModel $jobs, array $job): void
    {
        $id = (int) $job['id'];
        CLI::write("Job #{$id} ({$job['format']}) — démarrage...", 'yellow');

        try {
            $criteria = FilterCriteria::fromArray(json_decode($job['filters'] ?? '[]', true) ?: []);
            $service  = new CustomerListExportService();

            // CSV only: progress is persisted in batches, at most once a
            // second (ThrottledProgress), never per row.
            $progress = new ThrottledProgress(
                static function (int $processed, int $total, int $exported) use ($jobs, $id): void {
                    $jobs->updateProgress($id, $processed, $total, $exported);
                },
                self::PROGRESS_MIN_INTERVAL_SECONDS,
            );

            $meta = $job['format'] === 'xlsx'
                ? $service->exportXlsx($criteria)
                : $service->exportCsv($criteria, $progress);

            $jobs->markDone($id, $meta['path'], $meta['filename'], (int) $meta['fileSize'], (int) $meta['rows']);

            CLI::write("Job #{$id} — terminé : {$meta['rows']} lignes, " . round($meta['fileSize'] / 1048576, 1) . ' Mo'
                . " ({$progress->writes()} mises à jour de progression).", 'green');
        } catch (Throwable $e) {
            $reference = bscd_error_reference('EXPJOB');

            log_message('error', 'Echec job export #{id} [{ref}] : {message}', [
                'id'      => $id,
                'ref'     => $reference,
                'message' => $e->getMessage(),
            ]);

            $jobs->markError($id, $reference);

            CLI::write("Job #{$id} — échec ({$reference}). Voir les logs.", 'red');
        }
    }
}
