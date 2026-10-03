<?php

namespace App\Commands;

use App\Services\Refresh\CustomersRefresher;
use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Snapshot as SnapshotConfig;

/**
 * Daily refresh of the working file from Oracle:
 *   CMS_RFC.TB_CUSTOMERS_LIST -> customers_list.csv.tmp -> new active snapshot
 * (see CustomersRefresher). Scheduled daily at 05:00 by /etc/cron.d — the
 * business target — after the upstream reload of the table (measured
 * 2026-09-29: UPDATED_AT stamp 04:32, truncate 04:40:46, all rows inserted
 * by 04:41:44: 18 min of margin). A reload still running at 05:00 cannot
 * become the snapshot: rows extracted ≠ COUNT(*) taken at the start
 * (row_count_mismatch) or < refreshMinRowRatio of the active version
 * (volume_drop) both refuse the extraction and keep the previous version;
 * a catch-up run at 06:00 with --skip-if-fresh then retries, and does
 * nothing when the 05:00 run already activated today's version. Install on
 * the Linux server with docs/snapshot/extractor/install_customers_refresh_cron.sh,
 * which resolves and checks the PHP binary (oci8), user rights and timezone
 * before writing, e.g.:
 *
 *   0 5 * * * www-data cd /var/www/extractor && /usr/bin/php spark customers:refresh >> /var/www/extractor/writable/logs/customers_refresh.log 2>&1
 *   0 6 * * * www-data cd /var/www/extractor && /usr/bin/php spark customers:refresh --skip-if-fresh >> /var/www/extractor/writable/logs/customers_refresh.log 2>&1
 *
 * No outer flock(1) is needed: refresh() holds a kernel flock on
 * Config\Snapshot::$refreshLockFile for the whole run, for cron AND manual
 * runs alike, so a second instance exits at once with code 3.
 *
 * Every step is appended, timestamped, to Config\Snapshot::$refreshLogFile
 * (writable/logs/customers_refresh.log) by the command itself — also for a
 * manual run — and echoed on the terminal when there is one. The cron
 * redirection above then only adds what PHP itself prints (fatal errors).
 *
 * Exit codes: 0 new version active (or --skip-if-fresh: today's already is)
 *             · 1 failed (previous version kept) · 3 already running.
 */
class CustomersRefresh extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'customers:refresh';
    protected $description = 'Extract CMS_RFC.TB_CUSTOMERS_LIST from Oracle into the working customers_list.csv (previous file kept on any failure).';
    protected $usage       = 'customers:refresh [--force] [--skip-if-fresh]';
    protected $options     = [
        '--force'         => 'Accept an extraction with markedly fewer rows than the active version.',
        '--skip-if-fresh' => 'Do nothing when the active version was already extracted today (06:00 catch-up run).',
    ];

    /** @var resource|null */
    private $logHandle;

    /**
     * The active version's id when it was extracted today (its meta
     * generated_at, server time), else null — the catch-up run then refreshes.
     */
    public static function activeExtractedToday(SnapshotConfig $config, ?string $today = null): ?string
    {
        try {
            $active = (new SnapshotStore($config))->active();
        } catch (SnapshotException) {
            return null;
        }
        $at = strtotime((string) ($active->meta['generated_at'] ?? ''));

        return $at !== false && date('Y-m-d', $at) === ($today ?? date('Y-m-d')) ? $active->id : null;
    }

    private bool $tty = false;

    private bool $stopRequested = false;

    public function run(array $params): int
    {
        $config          = new SnapshotConfig();
        $this->tty       = function_exists('stream_isatty') && @stream_isatty(STDOUT);
        $this->logHandle = $this->openLog($config->refreshLogFile);
        $this->trapSignals();

        $t0 = microtime(true);
        $this->log('info', 'START customers refresh (pid ' . getmypid() . ')');

        if (CLI::getOption('skip-if-fresh') !== null && ($today = self::activeExtractedToday($config)) !== null) {
            $this->log('info', "SKIPPED version du jour déjà active : {$today}");
            if (is_resource($this->logHandle)) {
                fclose($this->logHandle);
            }

            return EXIT_SUCCESS;
        }

        $result = (new CustomersRefresher(
            log: fn (string $level, string $message) => $this->log($level, $message),
            shouldStop: fn (): bool => $this->stopRequested,
        ))->refresh(CLI::getOption('force') !== null);

        $duration = $this->duration(microtime(true) - $t0);

        switch ($result['result']) {
            case CustomersRefresher::RESULT_ACTIVATED:
                $meta = $result['meta'];
                $this->log('info', sprintf(
                    'CSV replaced successfully — version %s, %d lignes, %.1f Mo, validation %ss, précédente %s',
                    $result['version'],
                    $meta['rows'] ?? 0,
                    ($meta['size'] ?? 0) / 1048576,
                    $meta['validate_seconds'] ?? '?',
                    $result['previous'] ?? 'aucune',
                ));
                $this->log('info', "SUCCESS Duration: {$duration} (mémoire pic " . round(memory_get_peak_usage(true) / 1048576, 1) . ' Mo)');
                $code = EXIT_SUCCESS;
                break;

            case CustomersRefresher::RESULT_BUSY:
                $this->log('warning', 'SKIPPED ' . $result['message']);
                $code = 3;
                break;

            default:
                $this->log('error', sprintf('FAILED raison=%s : %s', $result['reason'] ?? '-', $result['message']));
                $this->log('error', 'Fichier de travail inchangé — version active conservée : ' . ($result['previous'] ?? 'aucune') . " (Duration: {$duration})");
                $code = EXIT_ERROR;
        }

        if (is_resource($this->logHandle)) {
            fclose($this->logHandle);
        }

        return $code;
    }

    public function log(string $level, string $message): void
    {
        $line = date('Y-m-d H:i:s') . ' - ' . ($level === 'info' ? '' : strtoupper($level) . ' ') . $message;

        if (is_resource($this->logHandle)) {
            fwrite($this->logHandle, $line . PHP_EOL);
        }
        if ($this->tty || ! is_resource($this->logHandle)) {
            // No log file (unwritable) => stdout, which cron redirects.
            fwrite($level === 'error' ? STDERR : STDOUT, $line . PHP_EOL);
        }

        log_message($level === 'info' ? 'info' : $level, '[CUSTOMERS_REFRESH] ' . $message);
    }

    /**
     * @return resource|null
     */
    private function openLog(string $path)
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $handle = @fopen($path, 'ab');

        return $handle === false ? null : $handle;
    }

    /**
     * SIGTERM/SIGINT/SIGHUP (kill, Ctrl+C, shutdown) stop the extraction at
     * the next check: the temporary file is removed and the active version
     * kept. Without pcntl (Windows), a killed run leaves a .tmp that the next
     * run deletes under the lock; the active version is never affected.
     */
    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, function (int $signo): void {
                $this->stopRequested = true;
                $this->log('warning', "Signal {$signo} reçu — arrêt demandé");
            });
        }
    }

    private function duration(float $seconds): string
    {
        $s = (int) round($seconds);

        return $s >= 60 ? intdiv($s, 60) . 'm' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . 's' : $s . 's';
    }
}
