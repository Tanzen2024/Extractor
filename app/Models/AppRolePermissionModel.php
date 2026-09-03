<?php

namespace App\Models;

use CodeIgniter\Model;

class AppRolePermissionModel extends Model
{
    protected $table        = 'app_role_permissions';
    protected $primaryKey   = 'id';
    protected $returnType   = 'array';
    protected $allowedFields = ['role_id', 'permission_id'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    /**
     * Replaces the whole permission set of a role with $permissionIds.
     *
     * @param list<int> $permissionIds
     */
    public function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        $this->where('role_id', $roleId)->delete();

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        if ($permissionIds === []) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = array_map(
            static fn (int $permId) => ['role_id' => $roleId, 'permission_id' => $permId, 'created_at' => $now],
            $permissionIds
        );

        $this->insertBatch($rows);
    }

    /**
     * @return list<int>
     */
    public function permissionIdsForRole(int $roleId): array
    {
        return array_map(
            static fn ($row) => (int) $row['permission_id'],
            $this->where('role_id', $roleId)->findAll()
        );
    }
}
