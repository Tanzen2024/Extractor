<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Application users (identity + activation only, no password).
 */
class AppUserModel extends Model
{
    protected $table         = 'app_users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields  = ['username', 'display_name', 'is_active'];
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $validationRules = [
        'username' => 'required|max_length[100]|is_unique[app_users.username,id,{id}]',
    ];

    public function findByUsername(string $username): ?array
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Users with their role codes/names collapsed into comma lists, for the
     * admin listing screen.
     *
     * @return list<array<string, mixed>>
     */
    public function listWithRoles(): array
    {
        return $this->select(
            'app_users.*, '
            . "GROUP_CONCAT(app_roles.code ORDER BY app_roles.code SEPARATOR ',') AS role_codes, "
            . "GROUP_CONCAT(app_roles.name ORDER BY app_roles.code SEPARATOR ', ') AS role_names",
            false
        )
            ->join('app_user_roles', 'app_user_roles.user_id = app_users.id', 'left')
            ->join('app_roles', 'app_roles.id = app_user_roles.role_id', 'left')
            ->groupBy('app_users.id')
            ->orderBy('app_users.username', 'ASC')
            ->findAll();
    }
}
