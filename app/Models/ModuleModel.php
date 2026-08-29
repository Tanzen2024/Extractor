<?php

namespace App\Models;

use CodeIgniter\Model;

class ModuleModel extends Model
{
    protected $table          = 'modules';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $allowedFields  = [
        'uuid', 'code', 'name', 'description', 'icon', 'color', 'display_order', 'is_active',
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
     * Active modules with their active tools, ordered for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveModulesWithTools(): array
    {
        $modules = $this->where('is_active', 1)
            ->orderBy('display_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();

        if ($modules === []) {
            return [];
        }

        $tools = (new ToolModel())
            ->whereIn('module_id', array_column($modules, 'id'))
            ->where('is_active', 1)
            ->orderBy('display_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();

        foreach ($modules as &$module) {
            $module['tools'] = array_values(array_filter(
                $tools,
                static fn ($tool) => (int) $tool['module_id'] === (int) $module['id']
            ));
        }

        return $modules;
    }

    public function countToolsTotal(): int
    {
        return (new ToolModel())->where('is_active', 1)->countAllResults();
    }
}
