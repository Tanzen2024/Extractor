<?php

namespace App\Database\Seeds;

use App\Models\AppRoleModel;
use App\Models\AppUserModel;
use App\Models\AppUserRoleModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Named application administrators.
 *
 * Each entry is an existing Active Directory sAMAccountName that must be able
 * to administer Extractor. Authentication stays 100% AD — no password is
 * created or stored here. Fully idempotent: re-running only adds what is
 * missing and re-activates a disabled row, never a duplicate.
 *
 * "FULL ADMIN" = the ADMIN role and whatever permissions the ADMIN role
 * currently carries (managed by RolePermissionSeeder / the admin UI). This
 * seeder never touches permissions and never invents a FULL_ACCESS bypass.
 */
class AdminUsersSeeder extends Seeder
{
    /**
     * sAMAccountName => display_name
     *
     * @var array<string, string>
     */
    private const ADMINS = [
        'hugues.nwameh' => 'Hugues NWAMEH',
    ];

    public function run(): void
    {
        $users     = new AppUserModel();
        $userRoles = new AppUserRoleModel();

        $adminRole = (new AppRoleModel())->findByCode('ADMIN');
        if ($adminRole === null) {
            CLI::error('Role ADMIN introuvable — lancer AppRoleSeeder d\'abord.');

            return;
        }
        $adminRoleId = (int) $adminRole['id'];

        foreach (self::ADMINS as $username => $displayName) {
            $existing = $users->findByUsername($username);

            if ($existing === null) {
                $userId = (int) $users->insert([
                    'username'     => $username,
                    'display_name' => $displayName,
                    'is_active'    => 1,
                ], true);
                CLI::write("app_users: '{$username}' cree (is_active=1).", 'green');
            } else {
                $userId  = (int) $existing['id'];
                $patch   = [];
                if ((int) $existing['is_active'] !== 1) {
                    $patch['is_active'] = 1;
                }
                if (($existing['display_name'] ?? '') === '') {
                    $patch['display_name'] = $displayName;
                }
                if ($patch !== []) {
                    $users->update($userId, $patch);
                    CLI::write("app_users: '{$username}' mis a jour (" . implode(', ', array_keys($patch)) . ').', 'yellow');
                } else {
                    CLI::write("app_users: '{$username}' deja present et actif.", 'dark_gray');
                }
            }

            $alreadyAdmin = (new AppUserRoleModel())
                ->where('user_id', $userId)
                ->where('role_id', $adminRoleId)
                ->countAllResults() > 0;

            if (! $alreadyAdmin) {
                $userRoles->insert(['user_id' => $userId, 'role_id' => $adminRoleId]);
                CLI::write("app_user_roles: '{$username}' -> ADMIN.", 'green');
            } else {
                CLI::write("app_user_roles: '{$username}' deja ADMIN.", 'dark_gray');
            }
        }
    }
}
