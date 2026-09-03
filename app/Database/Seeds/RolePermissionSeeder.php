<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Default role -> permission grants. ADMIN gets everything; the others get
 * DASHBOARD_VIEW. Only adds missing links, never removes admin customisation.
 */
class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        $map = [
            'ADMIN'        => '*',
            'DATA_ANALYST' => ['DASHBOARD_VIEW'],
            'USER'         => ['DASHBOARD_VIEW'],
            'VIEWER'       => ['DASHBOARD_VIEW'],
        ];

        $roles       = $this->indexByCode('app_roles');
        $permissions = $this->indexByCode('app_permissions');
        $allPermIds  = array_values($permissions);
        $now         = date('Y-m-d H:i:s');

        foreach ($map as $roleCode => $permCodes) {
            if (! isset($roles[$roleCode])) {
                continue;
            }
            $roleId  = $roles[$roleCode];
            $permIds = $permCodes === '*'
                ? $allPermIds
                : array_values(array_filter(array_map(static fn ($c) => $permissions[$c] ?? null, $permCodes)));

            foreach ($permIds as $permId) {
                $exists = $this->db->table('app_role_permissions')
                    ->where('role_id', $roleId)->where('permission_id', $permId)
                    ->countAllResults() > 0;

                if (! $exists) {
                    $this->db->table('app_role_permissions')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permId,
                        'created_at'    => $now,
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function indexByCode(string $table): array
    {
        $out = [];
        foreach ($this->db->table($table)->select('id, code')->get()->getResultArray() as $row) {
            $out[$row['code']] = (int) $row['id'];
        }

        return $out;
    }
}
