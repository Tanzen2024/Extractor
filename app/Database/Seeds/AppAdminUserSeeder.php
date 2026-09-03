<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Bootstrap administrator.
 *
 * Creates ONE app_users row for an existing Active Directory account and gives
 * it the ADMIN role, so someone can log in and manage everyone else. No
 * password is created — the person authenticates against AD.
 *
 * The AD sAMAccountName is read from .env `auth.bootstrapAdmin` (default: admin).
 */
class AppAdminUserSeeder extends Seeder
{
    public function run()
    {
        $username = (string) (env('auth.bootstrapAdmin') ?: 'admin');

        $adminRole = $this->db->table('app_roles')->where('code', 'ADMIN')->get()->getRowArray();
        if ($adminRole === null) {
            CLI::error('AppRoleSeeder doit tourner avant AppAdminUserSeeder.');

            return;
        }

        $user = $this->db->table('app_users')->where('username', $username)->get()->getRowArray();
        $now  = date('Y-m-d H:i:s');

        if ($user === null) {
            $this->db->table('app_users')->insert([
                'username'     => $username,
                'display_name' => 'Administrateur',
                'is_active'    => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $userId = (int) $this->db->insertID();
            CLI::write("app_users: '{$username}' cree (compte AD requis pour se connecter).", 'green');
        } else {
            $userId = (int) $user['id'];
        }

        $linked = $this->db->table('app_user_roles')
            ->where('user_id', $userId)->where('role_id', $adminRole['id'])
            ->countAllResults() > 0;

        if (! $linked) {
            $this->db->table('app_user_roles')->insert([
                'user_id'    => $userId,
                'role_id'    => (int) $adminRole['id'],
                'created_at' => $now,
            ]);
        }
    }
}
