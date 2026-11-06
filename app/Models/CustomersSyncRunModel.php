<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * History of `php spark customers:refresh` (see SyncRunRecorder).
 */
class CustomersSyncRunModel extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $table         = 'customers_sync_runs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'trigger_type', 'status', 'started_at', 'finished_at', 'duration_seconds',
        'rows_read', 'rows_added', 'rows_modified', 'rows_ignored', 'rows_removed',
        'rows_inactivated', 'rows_active', 'rows_duplicate',
        'error_count', 'error_reason', 'error_message',
        'snapshot_version', 'previous_version', 'source_updated_at', 'host', 'pid',
    ];

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        return $this->orderBy('id', 'DESC')->first();
    }

    /** @return array<string, mixed>|null The run that produced $version. */
    public function successOf(string $version): ?array
    {
        return $this->where('status', self::STATUS_SUCCESS)->where('snapshot_version', $version)->orderBy('id', 'DESC')->first();
    }

    /** @return list<array<string, mixed>> Newest first. */
    public function history(int $limit = 30): array
    {
        return $this->orderBy('id', 'DESC')->findAll(max(1, $limit));
    }

    /**
     * Rows still 'running' when the caller holds the refresh lock belong to a
     * process that died (kill, power loss, fatal error): they never finished,
     * so they must not stay "in progress" nor ever read as a success.
     */
    public function closeInterrupted(string $now): int
    {
        $this->builder()
            ->where('status', self::STATUS_RUNNING)
            ->update([
                'status'        => self::STATUS_FAILED,
                'finished_at'   => $now,
                'error_count'   => 1,
                'error_reason'  => 'interrupted',
                'error_message' => 'Exécution interrompue avant sa fin (processus arrêté).',
            ]);

        return $this->db->affectedRows();
    }
}
