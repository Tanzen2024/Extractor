<?php

namespace App\Models;

use CodeIgniter\Model;

class AppUserRoleModel extends Model
{
    protected $table        = 'app_user_roles';
    protected $primaryKey   = 'id';
    protected $returnType   = 'array';
    protected $allowedFields = ['user_id', 'role_id'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    /**
     * Replaces the whole role set of a user with $roleIds.
     *
     * @param list<int> $roleIds
     */
    public function syncUserRoles(int $userId, array $roleIds): void
    {
        $this->where('user_id', $userId)->delete();

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = array_map(
            static fn (int $roleId) => ['user_id' => $userId, 'role_id' => $roleId, 'created_at' => $now],
            $roleIds
        );

        $this->insertBatch($rows);
    }

    /**
     * @return list<int>
     */
    public function roleIdsForUser(int $userId): array
    {
        return array_map(
            static fn ($row) => (int) $row['role_id'],
            $this->where('user_id', $userId)->findAll()
        );
    }
}
