<?php

namespace App\Models;

use CodeIgniter\Model;

class AppRoleModel extends Model
{
    protected $table         = 'app_roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields  = ['code', 'name', 'description', 'is_active'];
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $validationRules = [
        'code' => 'required|max_length[60]|regex_match[/^[A-Z0-9_]+$/]|is_unique[app_roles.code,id,{id}]',
        'name' => 'required|max_length[120]',
    ];

    public function findByCode(string $code): ?array
    {
        return $this->where('code', $code)->first();
    }
}
