<?php

namespace App\Controllers;

use App\Models\UserModel;

class AuthController extends BaseController
{
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
            'username' => 'required|min_length[3]|max_length[60]',
            'password' => 'required|min_length[3]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to(site_url('login'))
                ->withInput()
                ->with('error', 'Veuillez renseigner un identifiant et un mot de passe.');
        }

        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        $userModel = new UserModel();
        $user      = $userModel->findActiveWithRole($username);

        if (! $user || ! password_verify($password, $user['password'])) {
            log_message('notice', 'Echec de connexion pour l\'identifiant "{username}" depuis {ip}', [
                'username' => $username,
                'ip'       => $this->request->getIPAddress(),
            ]);

            return redirect()->to(site_url('login'))
                ->withInput()
                ->with('error', 'Identifiant ou mot de passe incorrect.');
        }

        $userModel->touchLastLogin((int) $user['id']);

        session()->regenerate();
        session()->set([
            'isLoggedIn' => true,
            'userId'     => $user['id'],
            'username'   => $user['username'],
            'fullName'   => $user['full_name'],
            'roleCode'   => $user['role_code'],
            'roleName'   => $user['role_name'],
        ]);

        log_message('info', 'Connexion reussie: "{username}" depuis {ip}', [
            'username' => $username,
            'ip'       => $this->request->getIPAddress(),
        ]);

        return redirect()->to(site_url('dashboard'));
    }

    public function logout()
    {
        log_message('info', 'Deconnexion: "{username}"', [
            'username' => session()->get('username'),
        ]);

        session()->destroy();

        return redirect()->to(site_url('login'))->with('success', 'Vous avez été déconnecté.');
    }
}
