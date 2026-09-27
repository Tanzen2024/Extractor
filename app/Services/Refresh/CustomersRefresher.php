<?php

namespace App\Services\Refresh;

use App\Services\Snapshot\SnapshotException;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotStore;
use Throwable;

/**
 * The daily Oracle -> customers_list.csv refresh (`php spark customers:refresh`).
 *
 *   lock (one refresh at a time; the OS releases it if the process dies)
 *     -> OracleSnapshotExtractor: TB_CUSTOMERS_LIST -> customers_list.csv.tmp
 *     -> volume guard vs the active version (late/failed upstream reload)
 *     -> manifest -> SnapshotInstaller::installFile(): validation, new
 *        immutable version, atomic switch of current.json, link update
 *
 * Until that last switch, the active version is untouched and keeps serving
 * exports; on any failure the temporary files are deleted and the active
 * version stays as it was.
 */
final class CustomersRefresher
{
    public const RESULT_ACTIVATED = 'activated';
    public const RESULT_FAILED    = 'failed';
    public const RESULT_BUSY      = 'busy';

    private SnapshotStore $store;

    /** @var callable(string, string): void */
    private $log;

    /**
     * @param callable(string, string): void|null $log        Receives (level, message).
     * @param callable(): bool|null               $shouldStop See OracleSnapshotExtractor.
     */
    public function __construct(
        ?SnapshotStore $store = null,
        private readonly ?OracleSnapshotExtractor $extractor = null,
        ?callable $log = null,
        private readonly mixed $shouldStop = null,
    ) {
        $this->store = $store ?? new SnapshotStore();
        $this->log   = $log ?? static function (): void {};
    }

    /**
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, facts: array<string, mixed>, meta: array<string, mixed>}
     */
    public function refresh(bool $force = false): array
    {
        $config   = $this->store->config();
        $tmp      = $config->refreshTmpFile;
        $manifest = $tmp . '.manifest';
        $previous = $this->store->activeId();

        $lockDir = dirname($config->refreshLockFile);
        if (! is_dir($lockDir)) {
            @mkdir($lockDir, 0750, true);
        }
        $lock = @fopen($config->refreshLockFile, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return $this->result(self::RESULT_BUSY, 'busy', 'Un refresh customers est déjà en cours (verrou ' . $config->refreshLockFile . ').', null, $previous);
        }

        try {
            // Leftovers of a run killed before its own cleanup (kill -9,
            // power loss). Safe: we hold the lock, nobody else writes them.
            @unlink($tmp);
            @unlink($manifest);

            $extractor = $this->extractor ?? new OracleSnapshotExtractor(
                log: $this->log,
                shouldStop: is_callable($this->shouldStop) ? $this->shouldStop : null,
                progressEvery: $config->refreshProgressEvery,
            );

            $facts = $extractor->extract($tmp);

            ($this->log)('info', sprintf('Total rows: %d — fichier temporaire %s (%.1f Mo)', $facts['rows'], $tmp, $facts['size'] / 1048576));

            $this->guardVolume($facts['rows'], $force);

            OracleSnapshotExtractor::writeManifest($manifest, $facts);

            ($this->log)('info', 'Validation et activation de la nouvelle version...');
            $install = (new SnapshotInstaller($this->store))->installFile($tmp, $manifest);

            if ($install['result'] !== SnapshotInstaller::RESULT_ACTIVATED) {
                return $this->result(self::RESULT_FAILED, $install['reason'], 'Installation refusée : ' . $install['message'], $install['version'], $previous, $facts);
            }

            return $this->result(self::RESULT_ACTIVATED, null, 'CSV replaced successfully', $install['version'], $previous, $facts, $install['meta']);
        } catch (Throwable $e) {
            $reason = $e instanceof SnapshotException ? $e->reason : 'oracle_or_internal_error';

            return $this->result(self::RESULT_FAILED, $reason, $e->getMessage(), null, $previous);
        } finally {
            // installFile() moved both files away on every path it reached;
            // these only exist when we failed before it.
            @unlink($tmp);
            @unlink($manifest);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function guardVolume(int $rows, bool $force): void
    {
        $ratio = $this->store->config()->refreshMinRowRatio;

        try {
            $activeRows = $this->store->active()->rows();
        } catch (SnapshotException) {
            return; // first install: nothing to compare with
        }

        $min = (int) floor($activeRows * $ratio);
        if ($rows >= $min) {
            return;
        }

        $message = sprintf('%d lignes extraites < %d%% de la version active (%d lignes)', $rows, (int) round($ratio * 100), $activeRows);
        if ($force) {
            ($this->log)('warning', $message . ' — accepté (--force)');

            return;
        }

        throw new SnapshotException('volume_drop', $message . ' : rechargement Oracle amont incomplet ? Relancer avec --force si la baisse est légitime.');
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $meta
     *
     * @return array{result: string, reason: ?string, message: string, version: ?string, previous: ?string, facts: array<string, mixed>, meta: array<string, mixed>}
     */
    private function result(string $result, ?string $reason, string $message, ?string $version, ?string $previous, array $facts = [], array $meta = []): array
    {
        return compact('result', 'reason', 'message', 'version', 'previous', 'facts', 'meta');
    }
}
