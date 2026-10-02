<?php

namespace App\Services\Export;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Runtime state of the long-lived export worker (`export:process --watch`),
 * shared with `export:doctor`:
 *
 *   lock        one watcher at a time (kernel flock, released by the OS if
 *               the process dies) — a cron re-launching --watch every
 *               minute can no longer pile up dozens of workers;
 *   heartbeat   JSON rewritten at each poll: pid, OS user, PHP binary,
 *               code fingerprint, last poll, current job — what the doctor
 *               reads to say "a worker is alive, and runs the current code";
 *   fingerprint latest mtime + count of the PHP code, composer lock state
 *               and .env. A long-lived PHP process keeps the classes it has
 *               already loaded: after a `git pull` it would mix old and new
 *               code. The watcher compares it at each idle poll and exits
 *               (the supervisor restarts it on the new code).
 */
final class ExportWorkerState
{
    private string $dir;

    /** @var resource|null */
    private $lock = null;

    public function __construct(?string $dir = null, private readonly ?string $rootPath = null)
    {
        $this->dir = rtrim($dir ?? WRITEPATH . 'export-worker', '/\\') . DIRECTORY_SEPARATOR;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function lockPath(): string
    {
        return $this->dir . 'worker.lock';
    }

    public function heartbeatPath(): string
    {
        return $this->dir . 'heartbeat.json';
    }

    /** true = this process is now the only watcher (until release() / exit). */
    public function acquireLock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        $handle = @fopen($this->lockPath(), 'c');
        if ($handle === false) {
            return false;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->lock = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * Doctor: is a watcher holding the lock right now? null = unknown (no
     * lock file yet, or it cannot be opened by this user).
     */
    public function isWatcherRunning(): ?bool
    {
        if (! is_file($this->lockPath())) {
            return false;
        }

        $handle = @fopen($this->lockPath(), 'r');
        if ($handle === false) {
            return null;
        }

        // A shared lock can be taken only if nobody holds the exclusive one.
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    /** @param array<string, mixed> $data */
    public function writeHeartbeat(array $data): bool
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        $tmp = $this->heartbeatPath() . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return false;
        }

        if (! @rename($tmp, $this->heartbeatPath())) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    public function readHeartbeat(): ?array
    {
        $raw = @file_get_contents($this->heartbeatPath());
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * OS account this PHP process runs as (not the script owner that
     * get_current_user() returns) — the first thing to compare between the
     * web server and the worker when files or logs are not writable.
     */
    public static function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            return ($info['name'] ?? '?') . ' (uid ' . posix_geteuid() . ')';
        }

        return (string) (getenv('USERNAME') ?: getenv('USER') ?: get_current_user());
    }

    /**
     * Effective root (POSIX only, never on Windows). Whatever root creates
     * under writable/ belongs to root, and the www-data worker can then no
     * longer write there — the cause of "dir.openspout_tmp ... propriétaire
     * root" after a `sudo php spark ...` without `-u www-data`.
     */
    public static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /** Owner name of a path, '' when unknown (Windows, no posix). */
    public static function ownerOf(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid === false || ! function_exists('posix_getpwuid')) {
            return '';
        }
        $info = posix_getpwuid($uid);

        return ($info['name'] ?? (string) $uid) . ' (uid ' . $uid . ')';
    }

    /**
     * Cheap (stat only, ~200 files): changes whenever a deployment touches
     * the application code, the installed vendor set or the .env.
     */
    public function codeFingerprint(): string
    {
        $root   = rtrim($this->rootPath ?? ROOTPATH, '/\\') . DIRECTORY_SEPARATOR;
        $latest = 0;
        $count  = 0;

        clearstatcache();

        if (is_dir($root . 'app')) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'app', FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $latest = max($latest, $file->getMTime());
                    $count++;
                }
            }
        }

        foreach (['vendor/composer/installed.json', '.env', 'spark'] as $extra) {
            $mtime = @filemtime($root . $extra);
            if ($mtime !== false) {
                $latest = max($latest, $mtime);
                $count++;
            }
        }

        return $latest . '-' . $count;
    }
}
