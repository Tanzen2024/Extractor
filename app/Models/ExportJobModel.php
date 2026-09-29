<?php

namespace App\Models;

use CodeIgniter\Model;

class ExportJobModel extends Model
{
    protected $table         = 'export_jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields  = [
        'uuid', 'requested_by', 'format', 'filters', 'filters_label', 'status',
        'row_count', 'rows_total', 'rows_processed', 'rows_exported',
        'file_path', 'file_name', 'file_size', 'error_reference',
        'started_at', 'finished_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $beforeInsert = ['assignUuid'];

    protected function assignUuid(array $data): array
    {
        if (empty($data['data']['uuid'])) {
            $data['data']['uuid'] = bscd_uuid();
        }

        return $data;
    }

    /**
     * Atomically claims the oldest pending job for this worker: the UPDATE
     * only touches a row still in 'pending', so two concurrent workers can
     * never grab the same job (the second one's affectedRows() is 0).
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $db = $this->db;

        $row = $this->where('status', 'pending')->orderBy('id', 'ASC')->first();
        if ($row === null) {
            return null;
        }

        $db->table($this->table)
            ->where('id', $row['id'])
            ->where('status', 'pending')
            ->update([
                'status'     => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($db->affectedRows() !== 1) {
            return null; // another worker got it first
        }

        return $this->find($row['id']);
    }

    /**
     * Batched progress write from the worker (see Export\ThrottledProgress —
     * never called per row). Only touches a job still 'running', so a late
     * write can never resurrect a finished or failed job.
     */
    public function updateProgress(int $id, int $processed, int $total, int $exported): void
    {
        $this->db->table($this->table)
            ->where('id', $id)
            ->where('status', 'running')
            ->update([
                'rows_total'     => $total,
                'rows_processed' => $processed,
                'rows_exported'  => $exported,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Progress as reported by GET /exports/{id}. The percentage is derived
     * here, never stored: rows_processed / rows_total, always within 0..100 —
     * pending = 0, running = 0..99 (the file is still being finalised after
     * the scan), done = 100, unknown or zero total = 0.
     *
     * @param array<string, mixed> $job
     *
     * @return array{percent: int, processed: int, total: int|null, exported: int}
     */
    public static function progress(array $job): array
    {
        $total     = isset($job['rows_total']) ? max(0, (int) $job['rows_total']) : null;
        $processed = max(0, (int) ($job['rows_processed'] ?? 0));
        $exported  = max(0, (int) ($job['rows_exported'] ?? 0));

        $percent = match ($job['status'] ?? null) {
            'done'    => 100,
            'running' => $total > 0 ? min(99, intdiv($processed * 100, $total)) : 0,
            default   => 0,
        };

        return [
            'percent'   => $percent,
            'processed' => $processed,
            'total'     => $total,
            'exported'  => $exported,
        ];
    }

    public function markDone(int $id, string $filePath, string $fileName, int $fileSize, int $rowCount): void
    {
        $this->update($id, [
            'status'      => 'done',
            'file_path'   => $filePath,
            'file_name'   => $fileName,
            'file_size'   => $fileSize,
            'row_count'   => $rowCount,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markError(int $id, string $reference): void
    {
        $this->update($id, [
            'status'          => 'error',
            'error_reference' => $reference,
            'finished_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->whereIn('status', ['pending', 'running'])->orderBy('id', 'ASC')->findAll();
    }
}
