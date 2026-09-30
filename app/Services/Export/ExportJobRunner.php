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
 * XLSX and the Oracle source report no progress: for them the cancellation
 * only takes effect at the end (file deleted, never 'done').
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

            // CSV only: progress is persisted in batches, at most once per
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

            $meta = $job['format'] === 'xlsx'
                ? $service->exportXlsx($criteria)
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
            $reference = bscd_error_reference('EXPJOB');

            log_message('error', 'Echec job export #{id} [{ref}] : {message}', [
                'id'      => $id,
                'ref'     => $reference,
                'message' => $e->getMessage(),
            ]);

            // Conditional: a job cancelled meanwhile stays 'cancelled'.
            if (! $jobs->markError($id, $reference)) {
                $this->logCancelled($id, 'échec après annulation : ' . $e->getMessage());

                return self::CANCELLED;
            }

            $this->last['reference'] = $reference;

            return self::ERROR;
        }
    }

    private function logCancelled(int $id, string $detail): void
    {
        $this->last['detail'] = $detail;

        log_message('info', '[EXPORT JOB] cancelled job={id} detail={detail}', ['id' => $id, 'detail' => $detail]);
    }
}
