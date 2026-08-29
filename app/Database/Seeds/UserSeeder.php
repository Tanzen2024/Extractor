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

        $defaultPassword = 'Bscd@2026!';

        $userModel->insert([
            'role_id'   => $adminRole['id'],
            'username'  => 'admin',
            'email'     => null,
            'password'  => $defaultPassword,
            'full_name' => 'Administrateur BSCD',
            'is_active' => 1,
        ]);

        echo "Utilisateur admin cree. Identifiant: admin / Mot de passe: {$defaultPassword} (a changer immediatement)\n";
    }
}
