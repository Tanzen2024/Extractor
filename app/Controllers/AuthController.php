<?php

namespace App\Controllers;

use App\Models\AppUserModel;
use App\Services\ActiveDirectoryService;
use App\Services\AuditService;
use App\Services\AuthorizationService;

/**
 * Authentication.
 *
 *   password  -> Active Directory (ldap_bind), the ONLY auth authority
 *   identity  -> app_users (must exist and be is_active = 1)
 *   access    -> roles + permissions from MariaDB (app_roles / app_permissions)
 *
 * The application stores and verifies no password. AD group membership is
 * never used to grant application access.
 */
class AuthController extends BaseController
{
    private const GENERIC_ERROR = 'Identifiant ou mot de passe incorrect.';

    public function showLogin()
    {
        if (session()->get('isLoggedIn')) {
            return redirect()->to(site_url('dashboard'));
        }

        return view('auth/login');
    }

    public function attemptLogin()
    {
        $rules = [
            'username' => 'required|min_length[3]|max_length[100]',
            'password' => 'required|min_length[3]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to(site_url('login'))
                ->withInput()
                ->with('error', 'Veuillez renseigner un identifiant et un mot de passe.');
        }

        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        $audit = new AuditService();

        // 1. Active Directory --------------------------------------------------
        $adUser = (new ActiveDirectoryService())->authenticate($username, $password);

        if ($adUser === null) {
            $audit->log('LOGIN_FAILURE', [
                'username'    => $username,
                'module'      => 'auth',
                'description' => 'Echec bind Active Directory',
            ]);

            return $this->reject();
        }

        // 2. Application account (MariaDB) -----------------------------------
        $account = (new AppUserModel())->findByUsername($adUser['username']);

        if ($account === null) {
            $audit->log('LOGIN_FAILURE', [
                'username'    => $adUser['username'],
                'module'      => 'auth',
                'description' => 'Compte AD valide mais absent de app_users',
            ]);

            return $this->reject();
        }

        if ((int) $account['is_active'] !== 1) {
            $audit->log('LOGIN_FAILURE', [
                'username'    => $account['username'],
                'user_id'     => (int) $account['id'],
                'module'      => 'auth',
                'description' => 'Compte applicatif desactive (is_active = 0)',
            ]);

            return $this->reject();
        }

        // 3. Authorization (MariaDB) ---------------------------------------
        $authz = AuthorizationService::forUser((int) $account['id']);

        // 4. Session -----------------------------------------------------------
        session()->regenerate(true);
        session()->set([
            'isLoggedIn'    => true,
            'user_id'       => (int) $account['id'],
            'username'      => $account['username'],
            'display_name'  => $account['display_name'] ?: ($adUser['displayName'] ?? $account['username']),
            'roles'         => $authz->roles(),
            'permissions'   => $authz->permissions(),
            'last_activity' => time(),
        ]);

        // Keep the AD display name fresh without blocking login on a write error.
        if (($adUser['displayName'] ?? null) && $adUser['displayName'] !== $account['display_name']) {
            try {
                (new AppUserModel())->update((int) $account['id'], ['display_name' => $adUser['displayName']]);
            } catch (\Throwable $e) {
                log_message('warning', 'Maj display_name impossible pour {u}: {m}', ['u' => $account['username'], 'm' => $e->getMessage()]);
            }
        }

        $audit->log('LOGIN_SUCCESS', [
            'username'    => $account['username'],
            'user_id'     => (int) $account['id'],
            'module'      => 'auth',
            'description' => 'Roles: ' . (implode(',', $authz->roles()) ?: 'aucun'),
        ]);

        return redirect()->to(site_url('dashboard'));
    }

    public function logout()
    {
        (new AuditService())->log('LOGOUT', ['module' => 'auth']);

        session()->destroy();

        return redirect()->to(site_url('login'))->with('success', 'Vous avez été déconnecté.');
    }

    private function reject(): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(site_url('login'))
            ->withInput()
            ->with('error', self::GENERIC_ERROR);
    }
}
