<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['code' => 'ADMIN', 'name' => 'Administrateur', 'description' => 'Accès complet à l\'application et à l\'administration.'],
            ['code' => 'DATA_ANALYST', 'name' => 'Analyste de données', 'description' => 'Peut lancer des extractions, exporter et consulter l\'historique.'],
            ['code' => 'USER', 'name' => 'Utilisateur', 'description' => 'Accès uniquement aux outils qui lui sont autorisés.'],
            ['code' => 'VIEWER', 'name' => 'Lecteur', 'description' => 'Consultation uniquement, sans export.'],
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($roles as $role) {
            $exists = $this->db->table('roles')->where('code', $role['code'])->get()->getRow();

            if (! $exists) {
                $this->db->table('roles')->insert($role + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
