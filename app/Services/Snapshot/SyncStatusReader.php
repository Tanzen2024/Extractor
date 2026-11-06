<?php

namespace App\Services\Snapshot;

use App\Models\CustomersSyncRunModel;
use Config\Snapshot as SnapshotConfig;
use DateTimeImmutable;
use Throwable;

/**
 * Everything the freshness indicator needs, from three small reads: the
 * active version's meta, the latest customers_sync_runs row and the schedule
 * file. No customer data, no DuckDB query.
 */
final class SyncStatusReader
{
    private SnapshotConfig $config;
    private SnapshotStore $store;

    /**
     * @param (callable(): CustomersSyncRunModel)|null $model tests only
     */
    public function __construct(
        ?SnapshotConfig $config = null,
        private readonly mixed $model = null,
    ) {
        $this->config = $config ?? new SnapshotConfig();
        $this->store  = new SnapshotStore($this->config);
    }

    /**
     * @return array{state: string, running: bool, lastSuccessAt: ?string, sourceUpdatedAt: ?string,
     *     rows: ?int, version: ?string, lastStatus: ?string, lastAttemptAt: ?string, lastReason: ?string,
     *     nextRunAt: ?string, scheduledAt: ?string, maxAgeHours: int}
     */
    public function read(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        try {
            $active = $this->store->active();
        } catch (SnapshotException) {
            $active = null;
        }

        $lastRun    = $this->lastRun();
        $succeeded  = $this->succeededAt($active);
        $freshness  = SyncFreshness::evaluate($succeeded, $lastRun, $now, $this->config->syncMaxAgeHours, $this->config->syncStaleRunHours);
        $dailyAt    = $this->scheduledAt();
        $nextRun    = SyncFreshness::nextRun($dailyAt, $now);
        $sourceDate = $active?->meta['manifest']['source_updated_at'] ?? null;

        return [
            'state'           => $freshness['state'],
            'running'         => $freshness['running'],
            'lastSuccessAt'   => $succeeded?->format(DATE_ATOM),
            'sourceUpdatedAt' => is_string($sourceDate) && trim($sourceDate) !== '' ? trim($sourceDate) : null,
            'rows'            => $active?->rows(),
            'version'         => $active?->id,
            'lastStatus'      => $freshness['interrupted'] ? CustomersSyncRunModel::STATUS_FAILED : ($lastRun['status'] ?? null),
            'lastAttemptAt'   => SyncFreshness::date($lastRun['finished_at'] ?? $lastRun['started_at'] ?? null)?->format(DATE_ATOM),
            'lastReason'      => $freshness['interrupted'] ? 'interrupted' : ($lastRun['error_reason'] ?? null),
            'nextRunAt'       => $nextRun?->format(DATE_ATOM),
            'scheduledAt'     => $dailyAt,
            'maxAgeHours'     => $this->config->syncMaxAgeHours,
        ];
    }

    /**
     * @return list<array<string, mixed>> Newest first; empty when MariaDB is unavailable.
     */
    public function history(int $limit): array
    {
        try {
            return $this->model()->history($limit);
        } catch (Throwable $e) {
            log_message('warning', '[SYNC] historique illisible : {message}', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * When the active data really went live: the end of the run that produced
     * it (the index is built after validation, which took 79 min on
     * 2026-11-06). A version with no such run — SFTP delivery, history
     * unavailable, older than the history — falls back on its validation
     * date. Tied to the active version, so a rollback shows the older date.
     */
    private function succeededAt(?ActiveSnapshot $active): ?DateTimeImmutable
    {
        if ($active === null) {
            return null;
        }

        try {
            $finished = $this->model()->successOf($active->id)['finished_at'] ?? null;
        } catch (Throwable) {
            $finished = null;
        }

        return SyncFreshness::date($finished) ?? SyncFreshness::date($active->meta['validated_at'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private function lastRun(): ?array
    {
        try {
            return $this->model()->latest();
        } catch (Throwable $e) {
            log_message('warning', '[SYNC] dernière exécution illisible : {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function model(): CustomersSyncRunModel
    {
        return is_callable($this->model) ? ($this->model)() : new CustomersSyncRunModel();
    }

    /**
     * The daily time declared by the scheduler installers. Absent means no
     * installer has run here: the page then says "not scheduled" rather than
     * announce a run nothing would start.
     */
    private function scheduledAt(): ?string
    {
        $schedule = $this->store->readJson($this->config->refreshScheduleFile);
        $at       = $schedule['daily_at'] ?? null;

        return is_string($at) && $at !== '' ? $at : null;
    }
}
