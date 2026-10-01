<?php

namespace App\Services\Export;

use App\Models\ExportJobModel;
use App\Services\CustomerListExportService;
use App\Services\CustomersList\FilterCriteria;
use Closure;
use Throwable;

/**
 * Generates the file of one claimed ('running') export job — the body of
 * `php spark export:process`, kept out of the command so it can be tested.
 *
 * Cooperative cancellation: the CSV progress write (batched: every
 * ReportsProgress::PROGRESS_EVERY_ROWS rows scanned, at most once per
 * $progressIntervalSeconds — never per row) doubles as the cancellation
 * check. When the job is no longer 'running' the scan is stopped by an
 * ExportCancelledException, and CustomerListExportService closes and
 * deletes this job's own partial file. The final running -> done transition
 * is conditional, so a job cancelled while its file was being finalised is
 * never marked done: its (complete) file is deleted instead.
 *
 * CSV and XLSX from the snapshot report progress (hence cancellation) the
 * same way; the Oracle source reports none: there the cancellation only
 * takes effect at the end (file deleted, never 'done').
 *
 * A failure is logged in full (ExportJobFailure) under the short reference
 * stored in error_reference — see fail().
 */
final class ExportJobRunner
{
    public const DONE      = 'done';
    public const CANCELLED = 'cancelled';
    public const ERROR     = 'error';

    private Closure $serviceFactory;

    /** Details of the last run() for the command's output. */
    public array $last = [];

    /**
     * @param (callable(): CustomerListExportService)|null $serviceFactory tests only
     */
    public function __construct(
        private readonly ExportJobModel $jobs,
        ?callable $serviceFactory = null,
        private readonly float $progressIntervalSeconds = 1.0,
    ) {
        $this->serviceFactory = $serviceFactory !== null
            ? Closure::fromCallable($serviceFactory)
            : static fn (): CustomerListExportService => new CustomerListExportService();
    }

    /**
     * @param array<string, mixed> $job a row claimed by ExportJobModel::claimNext()
     *
     * @return string self::DONE | self::CANCELLED | self::ERROR
     */
    public function run(array $job): string
    {
        $id         = (int) $job['id'];
        $this->last = ['id' => $id];
        $jobs       = $this->jobs;

        try {
            $criteria = FilterCriteria::fromArray(json_decode($job['filters'] ?? '[]', true) ?: []);
            $service  = ($this->serviceFactory)();

            // Progress is persisted in batches, at most once per
            // interval (ThrottledProgress), never per row — and each write
            // tells whether the job is still running.
            $progress = new ThrottledProgress(
                static function (int $processed, int $total, int $exported) use ($jobs, $id): void {
                    if (! $jobs->updateProgress($id, $processed, $total, $exported)) {
                        throw new ExportCancelledException("Job #{$id} annulé à {$processed}/{$total} lignes parcourues.");
                    }
                },
                $this->progressIntervalSeconds,
            );

            log_message('info', '[EXPORT JOB] started job={id} uuid={uuid} format={format} expected_rows={rows}', [
                'id' => $id, 'uuid' => $job['uuid'] ?? '', 'format' => $job['format'] ?? '', 'rows' => $job['row_count'] ?? '',
            ]);

            $meta = $job['format'] === 'xlsx'
                ? $service->exportXlsx($criteria, $progress)
                : $service->exportCsv($criteria, $progress);

            $this->last += ['meta' => $meta, 'progressWrites' => $progress->writes()];

            if (! $jobs->markDone($id, $meta['path'], $meta['filename'], (int) $meta['fileSize'], (int) $meta['rows'])) {
                // Cancelled between the end of the scan and now: the file is
                // this job's own (unique name) and must never be offered.
                @unlink($meta['path']);
                $this->logCancelled($id, 'après génération, fichier supprimé');

                return self::CANCELLED;
            }

            return self::DONE;
        } catch (ExportCancelledException $e) {
            // The partial .tmp is already deleted by the export service.
            $this->logCancelled($id, $e->getMessage());

            return self::CANCELLED;
        } catch (Throwable $e) {
            return $this->fail($job, $e);
        }
    }

    /**
     * Any failure: a short reference for the user (error_reference), the
     * full exception — class, message, file, line, trace, causes — in the
     * log under that same reference. Never throws: the worker must survive
     * one job's failure, even when the database refuses the error write.
     *
     * @param array<string, mixed> $job
     */
    private function fail(array $job, Throwable $e): string
    {
        $id        = (int) $job['id'];
        $reference = bscd_error_reference('EXPJOB');
        $report    = ExportJobFailure::report($job, $reference, $e);

        $this->last += [
            'reference' => $reference,
            'exception' => $e::class,
            'summary'   => ExportJobFailure::summary($e),
            'report'    => $report,
        ];

        log_message('error', $report);

        try {
            // Conditional: a job cancelled meanwhile stays 'cancelled'.
            if (! $this->jobs->markError($id, $reference)) {
                $this->logCancelled($id, 'échec après annulation : ' . ExportJobFailure::summary($e));

                return self::CANCELLED;
            }
        } catch (Throwable $dbError) {
            // The row stays 'running'; the worker's stale-job recovery ends
            // it later. Logged so neither cause is lost.
            $this->last['markErrorFailed'] = ExportJobFailure::summary($dbError);
            log_message('critical', "[EXPORT JOB ERROR] job_id={$id} reference={$reference} : statut 'error' non enregistré\n"
                . ExportJobFailure::report($job, $reference, $dbError));
        }

        return self::ERROR;
    }

    private function logCancelled(int $id, string $detail): void
    {
        $this->last['detail'] = $detail;

        log_message('info', '[EXPORT JOB] cancelled job={id} detail={detail}', ['id' => $id, 'detail' => $detail]);
    }
}
