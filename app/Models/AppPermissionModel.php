<?php

namespace App\Models;

use CodeIgniter\Model;

class AppPermissionModel extends Model
{
    protected $table         = 'app_permissions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields  = ['code', 'name', 'description', 'is_active'];
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    public function findByCode(string $code): ?array
    {
        return $this->where('code', $code)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        return $this->orderBy('code', 'ASC')->findAll();
    }
}
