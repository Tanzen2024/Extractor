<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Application roles (app_roles). Mirrors the functional roles the project
 * already recognised. Idempotent.
 */
class AppRoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['code' => 'ADMIN',        'name' => 'Administrateur',      'description' => 'Accès complet à l\'application et à l\'administration.'],
            ['code' => 'DATA_ANALYST', 'name' => 'Analyste de données', 'description' => 'Lance des extractions, exporte et consulte le tableau de bord.'],
            ['code' => 'USER',         'name' => 'Utilisateur',         'description' => 'Accès au tableau de bord.'],
            ['code' => 'VIEWER',       'name' => 'Lecteur',             'description' => 'Consultation du tableau de bord uniquement.'],
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($roles as $role) {
            $existing = $this->db->table('app_roles')->where('code', $role['code'])->get()->getRowArray();

            if ($existing === null) {
                $this->db->table('app_roles')->insert($role + [
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
