<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Permission catalogue. Each code maps to a real capability enforced by the
 * `permission` route filter / user_can() helper. Idempotent (upsert by code).
 */
class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            ['code' => 'DASHBOARD_VIEW',  'name' => 'Consulter le tableau de bord',      'description' => 'Accès au dashboard CUSTOMERS_LIST et aux extractions.'],
            ['code' => 'USER_VIEW',        'name' => 'Consulter les utilisateurs',        'description' => 'Voir la liste des utilisateurs applicatifs.'],
            ['code' => 'USER_CREATE',      'name' => 'Créer un utilisateur',              'description' => 'Autoriser un compte AD existant à accéder à l\'application.'],
            ['code' => 'USER_EDIT',        'name' => 'Modifier un utilisateur',           'description' => 'Modifier le nom affiché et les rôles.'],
            ['code' => 'USER_DISABLE',     'name' => 'Activer / désactiver un utilisateur', 'description' => 'Couper ou rétablir l\'accès applicatif.'],
            ['code' => 'ROLE_VIEW',        'name' => 'Consulter les rôles',               'description' => 'Voir la liste des rôles.'],
            ['code' => 'ROLE_CREATE',      'name' => 'Créer un rôle',                     'description' => 'Ajouter un rôle applicatif.'],
            ['code' => 'ROLE_EDIT',        'name' => 'Modifier un rôle',                  'description' => 'Modifier un rôle et ses permissions.'],
            ['code' => 'PERMISSION_VIEW',  'name' => 'Consulter les permissions',         'description' => 'Voir le catalogue des permissions.'],
            ['code' => 'PERMISSION_EDIT',  'name' => 'Modifier les permissions',          'description' => 'Activer / désactiver une permission.'],
            ['code' => 'AUDIT_VIEW',       'name' => 'Consulter l\'audit',                'description' => 'Accès au journal d\'audit de sécurité.'],
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($permissions as $permission) {
            $existing = $this->db->table('app_permissions')->where('code', $permission['code'])->get()->getRowArray();

            if ($existing === null) {
                $this->db->table('app_permissions')->insert($permission + [
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $this->db->table('app_permissions')->where('id', $existing['id'])->update([
                    'name'        => $permission['name'],
                    'description' => $permission['description'],
                    'updated_at'  => $now,
                ]);
            }
        }
    }
}
