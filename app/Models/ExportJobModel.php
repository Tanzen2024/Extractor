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
        'row_count', 'file_path', 'file_name', 'file_size', 'error_reference',
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
