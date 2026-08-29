<?php

namespace App\Models;

use CodeIgniter\Model;

class CustomersListSnapshotModel extends Model
{
    protected $table         = 'customers_list_snapshots';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'uuid', 'status', 'started_at', 'finished_at', 'duration_seconds',
        'row_count', 'distinct_client_count', 'source', 'error_reference',
        'totals', 'dimensions', 'cube',
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

    public function latestSuccessful(): ?array
    {
        return $this->where('status', 'success')
            ->orderBy('finished_at', 'DESC')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit = 15): array
    {
        return $this->orderBy('created_at', 'DESC')
            ->findAll($limit);
    }
}
