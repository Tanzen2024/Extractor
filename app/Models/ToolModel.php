<?php

namespace App\Models;

use CodeIgniter\Model;

class ToolModel extends Model
{
    protected $table          = 'tools';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $allowedFields  = [
        'uuid', 'module_id', 'code', 'name', 'description', 'icon',
        'route', 'query_definition', 'display_order', 'is_active',
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

    public function findWithModule(int $id): ?array
    {
        return $this->select('tools.*, modules.code as module_code, modules.name as module_name')
            ->join('modules', 'modules.id = tools.module_id')
            ->where('tools.id', $id)
            ->first();
    }
}
