<?php

namespace App\Commands;

use App\Services\Refresh\CustomersRefresher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Snapshot as SnapshotConfig;

/**
 * Daily refresh of the working file from Oracle:
 *   CMS_RFC.TB_CUSTOMERS_LIST -> customers_list.csv.tmp -> new active snapshot
 * (see CustomersRefresher). Scheduled by cron at 05:00, after the upstream
 * truncate + SQL*Loader reload of the table (~04:30):
 *
 *   0 5 * * * cd /var/www/MyMemo && /usr/bin/php spark customers:refresh >> /var/www/MyMemo/writable/logs/customers_refresh.log 2>&1
 *
 * Every step is appended, timestamped, to Config\Snapshot::$refreshLogFile
 * (writable/logs/customers_refresh.log) by the command itself — also for a
 * manual run — and echoed on the terminal when there is one. The cron
 * redirection above then only adds what PHP itself prints (fatal errors).
 *
 * Exit codes: 0 new version active · 1 failed (previous version kept) · 3 already running.
 */
class CustomersRefresh extends BaseCommand
{
    protected $group       = 'Snapshot';
    protected $name        = 'customers:refresh';
    protected $description = 'Extract CMS_RFC.TB_CUSTOMERS_LIST from Oracle into the working customers_list.csv (previous file kept on any failure).';
    protected $usage       = 'customers:refresh [--force]';
    protected $options     = [
        '--force' => 'Accept an extraction with markedly fewer rows than the active version.',
    ];

    /** @var resource|null */
    private $logHandle;

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
