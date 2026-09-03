<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Authorization model (MariaDB) — authentication is Active Directory.
        $this->call(PermissionSeeder::class);
        $this->call(AppRoleSeeder::class);
        $this->call(RolePermissionSeeder::class);
        $this->call(AppAdminUserSeeder::class);
        $this->call(AdminUsersSeeder::class);

        // Application catalogue (modules / tools driving the dashboard + extractions).
        $this->call(ModuleToolSeeder::class);
    }
}
