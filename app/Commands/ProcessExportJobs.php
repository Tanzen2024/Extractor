<?php

namespace App\Commands;

use App\Models\ExportJobModel;
use App\Services\Export\ExportJobFailure;
use App\Services\Export\ExportJobRunner;
use App\Services\Export\ExportJobSchema;
use App\Services\Export\ExportWorkerState;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Worker for asynchronous CUSTOMERS_LIST exports.
 *
 *   php spark export:process            run every pending job, then exit (cron-friendly)
 *   php spark export:process --watch    long-lived worker (systemd), polls every 5 s
 *
 * Long-lived mode (--watch) is built to run unattended:
 *   - one watcher at a time (lock in writable/export-worker/): a second
 *     --watch exits at once, so a cron re-launching it cannot pile up;
 *   - schema preflight before every claim: while export_jobs lacks a column
 *     the code writes (missing `php spark migrate`), no job is claimed —
 *     they stay 'pending' instead of all failing — and the error is logged;
 *   - the DB connection is pinged / re-opened before each poll (MySQL
 *     wait_timeout closes idle connections overnight);
 *   - an error outside a job (DB down...) is logged and retried, never fatal;
 *   - exits cleanly once the code on disk changed (deployment) or on
 *     SIGTERM/SIGINT after the current job — the supervisor restarts it;
 *   - at start, jobs left 'running' by a dead worker (no progress for
 *     --stale-after seconds) are marked 'error' with a logged reason.
 *
 * Every failure is written in full (class, message, file, line, trace) to
 * the application log AND to this process' stderr: when the worker's OS
 * user cannot append to writable/logs/log-*.log (CodeIgniter's file logger
 * then drops the line silently), the systemd journal still has it.
 *
 * Systemd unit and deployment steps: docs/deploy/export-worker.md.
 */
class ProcessExportJobs extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'export:process';
    protected $description  = 'Generates the files for queued CUSTOMERS_LIST export jobs.';
    protected $usage        = 'export:process [--watch] [--sleep=5] [--stale-after=3600]';
    protected $options      = [
        '--watch'       => 'Keep running, polling for new jobs instead of exiting when the queue is empty.',
        '--sleep'       => 'Seconds to wait between polls in --watch mode (default 5).',
        '--stale-after' => "--watch: at start, mark 'error' the jobs still 'running' without any progress for this many seconds (default 3600, 0 = never).",
    ];

    /** Minimum gap between two progress UPDATEs of the same job. */
    private const PROGRESS_MIN_INTERVAL_SECONDS = 1.0;

    /** A persistent problem (schema, DB down) is re-logged at most this often. */
    private const REPEAT_LOG_SECONDS = 600;

    private bool $stopRequested = false;

    /** @var array<string, int> message => last time it was logged */
    private array $lastLogged = [];

    public function run(array $params): int
    {
        $watch      = CLI::getOption('watch') !== null;
        $sleep      = max(1, (int) (CLI::getOption('sleep') ?? 5));
        $staleAfter = max(0, (int) (CLI::getOption('stale-after') ?? 3600));
        $state      = new ExportWorkerState();
        $startedAt  = date('Y-m-d H:i:s');

        $this->announce($watch);

        if ($watch && ! $state->acquireLock()) {
            CLI::write('[EXPORT WORKER] Un autre worker --watch tourne déjà (verrou ' . $state->lockPath() . '). Arrêt.', 'yellow');

            return EXIT_SUCCESS;
        }

        $this->listenForSignals();

        try {
            $jobs   = new ExportJobModel();
            $runner = new ExportJobRunner($jobs, null, self::PROGRESS_MIN_INTERVAL_SECONDS);
        } catch (Throwable $e) {
            $this->report(ExportJobFailure::workerReport('bootstrap', $e));

            return EXIT_ERROR;
        }

        $fingerprint = $state->codeFingerprint();
        $handled     = 0;

        if ($watch && $staleAfter > 0) {
            $this->recoverStaleJobs($jobs, $staleAfter);
        }

        do {
            $processed = 0;
            $current   = null;

            try {
                if ($watch) {
                    db_connect()->reconnect(); // ping, re-open if the server closed it (shared with the model)
                }

                if (! $this->schemaIsReady()) {
                    if (! $watch) {
                        return EXIT_ERROR;
                    }
                } else {
                    while (! $this->stopRequested && ! $this->codeChanged($watch, $state, $fingerprint)
                        && ($job = $jobs->claimNext()) !== null) {
                        $current = $job;
                        $state->writeHeartbeat($this->heartbeat($watch, $startedAt, $fingerprint, $handled, $job));
                        $this->process($runner, $job);
                        $current = null;
                        $processed++;
                        $handled++;
                    }
                }
            } catch (Throwable $e) {
                // Outside the runner (which never throws): claim, ping,
                // schema read... One job's id is reported when known.
                $stage = $current !== null ? 'job #' . $current['id'] : 'poll';
                $this->report(ExportJobFailure::workerReport($stage, $e), $stage . get_class($e) . $e->getMessage());

                if (! $watch) {
                    return EXIT_ERROR;
                }
            }

            $state->writeHeartbeat($this->heartbeat($watch, $startedAt, $fingerprint, $handled, null));

            if ($watch && ! $this->stopRequested) {
                if ($this->codeChanged($watch, $state, $fingerprint)) {
                    $this->notice('[EXPORT WORKER] Code modifié sur le disque (déploiement) : arrêt pour redémarrage sur le nouveau code.');

                    break;
                }
                if ($processed === 0) {
                    $this->pause($sleep);
                }
            }
        } while ($watch && ! $this->stopRequested);

        if ($this->stopRequested) {
            $this->notice('[EXPORT WORKER] Signal d\'arrêt reçu : arrêt propre.');
        }
        $state->release();

        return EXIT_SUCCESS;
    }

    private function process(ExportJobRunner $runner, array $job): void
    {
        $id = (int) $job['id'];
        CLI::write('[' . date('Y-m-d H:i:s') . "] Job #{$id} ({$job['format']}) — démarrage...", 'yellow');

        // One job's failure or cancellation only ever ends that job: the
        // loop goes on with the next pending one (the runner never throws).
        match ($runner->run($job)) {
            ExportJobRunner::DONE => CLI::write("Job #{$id} — terminé : {$runner->last['meta']['rows']} lignes, "
                . round($runner->last['meta']['fileSize'] / 1048576, 1) . ' Mo'
                . " ({$runner->last['progressWrites']} mises à jour de progression).", 'green'),
            ExportJobRunner::CANCELLED => CLI::write("Job #{$id} — annulé par l'utilisateur ({$runner->last['detail']}).", 'light_gray'),
            default => $this->reportJobFailure($runner),
        };
    }

    /** Full report on stderr too: the journal keeps it even when the log file is not writable. */
    private function reportJobFailure(ExportJobRunner $runner): void
    {
        CLI::error("Job #{$runner->last['id']} — échec ({$runner->last['reference']}) : {$runner->last['summary']}");
        CLI::error($runner->last['report']);

        if (isset($runner->last['markErrorFailed'])) {
            CLI::error("Statut 'error' non enregistré : {$runner->last['markErrorFailed']}");
        }
    }

    /**
     * true = export_jobs has every column the code writes. Otherwise logged
     * (rate-limited) and no job is claimed.
     */
    private function schemaIsReady(): bool
    {
        $missing = ExportJobSchema::missingColumns(db_connect());

        if ($missing === []) {
            return true;
        }

        $what = $missing === null
            ? 'table ' . ExportJobSchema::TABLE . ' absente'
            : 'colonnes manquantes dans ' . ExportJobSchema::TABLE . ' : ' . implode(', ', $missing);

        $this->report("[EXPORT WORKER ERROR]\nstage=schema\n{$what}\n"
            . 'action=lancer `php spark migrate` puis `php spark export:doctor`. Aucun job n\'est pris en charge tant que le schéma est incomplet (ils restent en attente).', $what);

        return false;
    }

    /** @param array<string, mixed>|null $job */
    private function heartbeat(bool $watch, string $startedAt, string $fingerprint, int $handled, ?array $job): array
    {
        return [
            'pid'          => getmypid(),
            'host'         => gethostname(),
            'user'         => ExportWorkerState::processUser(),
            'php'          => PHP_VERSION,
            'php_binary'   => PHP_BINARY,
            'environment'  => ENVIRONMENT,
            'watch'        => $watch,
            'started_at'   => $startedAt,
            'last_poll_at' => date('Y-m-d H:i:s'),
            'code'         => $fingerprint,
            'jobs_handled' => $handled,
            'current_job'  => $job !== null ? (int) $job['id'] : null,
        ];
    }

    private function codeChanged(bool $watch, ExportWorkerState $state, string $fingerprint): bool
    {
        return $watch && $state->codeFingerprint() !== $fingerprint;
    }

    private function recoverStaleJobs(ExportJobModel $jobs, int $staleAfter): void
    {
        try {
            foreach ($jobs->failStale($staleAfter, static fn (): string => bscd_error_reference('EXPJOB')) as $job) {
                $this->report("[EXPORT JOB ERROR]\njob_id={$job['id']}\nuuid={$job['uuid']}\nreference={$job['reference']}\n"
                    . "exception=none\nmessage=Worker interrompu : job resté 'running' sans progression depuis {$job['updated_at']} (> {$staleAfter} s). Relancer l'export.");
            }
        } catch (Throwable $e) {
            $this->report(ExportJobFailure::workerReport('stale-recovery', $e));
        }
    }

    /** Who / what / where — the facts to compare with the web server. */
    private function announce(bool $watch): void
    {
        $db   = config('Database')->{config('Database')->defaultGroup} ?? [];
        $line = sprintf(
            '[EXPORT WORKER] start pid=%d user=%s php=%s (%s) env=%s mode=%s writable=%s db=%s/%s',
            getmypid(),
            ExportWorkerState::processUser(),
            PHP_VERSION,
            PHP_BINARY,
            ENVIRONMENT,
            $watch ? 'watch' : 'once',
            WRITEPATH,
            $db['hostname'] ?? '?',
            $db['database'] ?? '?',
        );

        CLI::write($line, 'cyan');
        if ($watch) {
            // Not for one-shot runs: a per-minute scheduled task would add
            // a line to the log every minute.
            log_message('info', $line);
        }

        // CodeIgniter's file logger silently drops what it cannot append.
        $log = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';
        $fp  = @fopen($log, 'ab');
        if ($fp === false) {
            CLI::error("[EXPORT WORKER] ATTENTION : {$log} n'est pas accessible en écriture pour " . ExportWorkerState::processUser()
                . (($owner = ExportWorkerState::ownerOf($log)) !== '' ? " (propriétaire : {$owner})" : '')
                . ". Les erreurs ne seront visibles que sur cette sortie (journalctl). Lancer le worker avec l'utilisateur du serveur web.");
        } else {
            fclose($fp);
        }
    }

    /**
     * Logs + stderr. With $dedupeKey, the same persistent problem is only
     * repeated every REPEAT_LOG_SECONDS (a 5 s poll would flood the log).
     */
    private function report(string $message, ?string $dedupeKey = null): void
    {
        if ($dedupeKey !== null) {
            $now = time();
            if (isset($this->lastLogged[$dedupeKey]) && $now - $this->lastLogged[$dedupeKey] < self::REPEAT_LOG_SECONDS) {
                return;
            }
            $this->lastLogged[$dedupeKey] = $now;
        }

        log_message('error', $message);
        CLI::error('[' . date('Y-m-d H:i:s') . '] ' . $message);
    }

    private function notice(string $message): void
    {
        log_message('info', $message);
        CLI::write('[' . date('Y-m-d H:i:s') . '] ' . $message, 'yellow');
    }

    /** SIGTERM / SIGINT: finish the current job, then exit (needs ext-pcntl). */
    private function listenForSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    private function pause(int $seconds): void
    {
        for ($i = 0; $i < $seconds && ! $this->stopRequested; $i++) {
            sleep(1);
        }
    }
}
