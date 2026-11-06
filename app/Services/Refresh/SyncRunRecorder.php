<?php

namespace App\Services\Refresh;

use App\Models\CustomersSyncRunModel;
use Throwable;

/**
 * Writes customers_sync_runs for one customers:refresh run.
 *
 * The history must never be the reason a refresh fails: the data path does
 * not use MariaDB, which may be down at 05:00. A recording failure is logged
 * and the run goes on; the freshness shown to users does not depend on it
 * (SyncStatusReader reads the date from the active snapshot itself).
 */
final class SyncRunRecorder
{
    private ?int $runId = null;
    private ?string $startedAt = null;
    private float $t0 = 0.0;

    /** @var callable(string, string): void */
    private $log;

    /**
     * @param (callable(): CustomersSyncRunModel)|null $model tests only
     * @param callable(string, string): void|null      $log   Receives (level, message).
     */
    public function __construct(
        private readonly mixed $model = null,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (): void {};
    }

    /** Call under the refresh lock: any 'running' row left is a dead process. */
    public function start(string $trigger, ?string $previousVersion): void
    {
        $this->t0        = microtime(true);
        $this->startedAt = date('Y-m-d H:i:s');

        $this->guarded(function (CustomersSyncRunModel $model) use ($trigger, $previousVersion): void {
            $closed = $model->closeInterrupted($this->startedAt);
            if ($closed > 0) {
                ($this->log)('warning', "{$closed} exécution(s) précédente(s) interrompue(s) marquée(s) en échec");
            }

            $id = $model->insert([
                'trigger_type'     => $trigger,
                'status'           => CustomersSyncRunModel::STATUS_RUNNING,
                'started_at'       => $this->startedAt,
                'previous_version' => $previousVersion,
                'host'             => substr((string) (gethostname() ?: ''), 0, 100),
                'pid'              => getmypid() ?: null,
            ]);
            $this->runId = is_numeric($id) ? (int) $id : null;
        });
    }

    /**
     * @param array<string, mixed> $fields Columns of customers_sync_runs.
     */
    public function finish(string $status, array $fields = []): void
    {
        if ($this->runId === null) {
            return;
        }

        $this->guarded(function (CustomersSyncRunModel $model) use ($status, $fields): void {
            if (isset($fields['error_message'])) {
                $fields['error_message'] = mb_substr((string) $fields['error_message'], 0, 2000);
            }

            $model->update($this->runId, [
                'status'           => $status,
                'finished_at'      => date('Y-m-d H:i:s'),
                'duration_seconds' => (int) round(microtime(true) - $this->t0),
            ] + $fields);
        });
    }

    /** @param callable(CustomersSyncRunModel): void $write */
    private function guarded(callable $write): void
    {
        try {
            $write(is_callable($this->model) ? ($this->model)() : new CustomersSyncRunModel());
        } catch (Throwable $e) {
            ($this->log)('warning', 'Historique de synchronisation non écrit : ' . $e->getMessage());
        }
    }
}
