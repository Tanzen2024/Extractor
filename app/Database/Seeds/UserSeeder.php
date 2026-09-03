<?php

namespace App\Database\Seeds;

use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();
        $roleModel = new RoleModel();

        if ($userModel->findByUsername('admin')) {
            return;
        }

        $adminRole = $roleModel->findByCode('ADMIN');

        if (! $adminRole) {
            CLI::error('RoleSeeder must run before UserSeeder.');

            return;
        }

        $defaultPassword = trim((string) env('auth.seedAdminPassword', ''));

        if ($defaultPassword === '') {
            CLI::error('auth.seedAdminPassword is not configured.');

            return;
        }

        $userModel->insert([
            'role_id'   => $adminRole['id'],
            'username'  => 'admin',
            'email'     => null,
            'password'  => $defaultPassword,
            'full_name' => 'Administrateur BSCD',
            'is_active' => 1,
        ]);

        CLI::write('Utilisateur admin cree. Identifiant: admin.', 'green');
    }
}
