<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AppRoleModel;
use App\Models\AppUserModel;
use App\Models\AppUserRoleModel;
use App\Services\AuditService;
use App\Services\AuthorizationService;

/**
 * Administration > Utilisateurs.
 *
 * Creating a user here does NOT create an AD account — the person must already
 * exist in Active Directory. This only authorises an existing AD identity to
 * use the application and pins its role(s).
 */
class UsersController extends BaseController
{
    private AppUserModel $users;
    private AppRoleModel $roles;
    private AppUserRoleModel $userRoles;
    private AuditService $audit;

    public function __construct()
    {
        $this->users     = new AppUserModel();
        $this->roles     = new AppRoleModel();
        $this->userRoles = new AppUserRoleModel();
        $this->audit     = new AuditService();
    }

    public function index()
    {
        return view('admin/users/index', [
            'title' => 'Utilisateurs',
            'users' => $this->users->listWithRoles(),
        ]);
    }

    public function create()
    {
        return view('admin/users/form', [
            'title'     => 'Nouvel utilisateur',
            'mode'      => 'create',
            'user'      => ['id' => null, 'username' => '', 'display_name' => '', 'is_active' => 1],
            'allRoles'  => $this->roles->orderBy('code', 'ASC')->findAll(),
            'userRoles' => [],
        ]);
    }

    public function store()
    {
        $rules = [
            'username'     => 'required|max_length[100]|is_unique[app_users.username]',
            'display_name' => 'permit_empty|max_length[190]',
            'roles'        => 'permit_empty',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $username = trim((string) $this->request->getPost('username'));

        $id = $this->users->insert([
            'username'     => $username,
            'display_name' => trim((string) $this->request->getPost('display_name')) ?: null,
            'is_active'    => $this->request->getPost('is_active') ? 1 : 0,
        ], true);

        $this->userRoles->syncUserRoles((int) $id, (array) $this->request->getPost('roles'));

        $this->audit->log('USER_CREATED', [
            'module'      => 'users',
            'target_type' => 'app_user',
            'target_id'   => $id,
            'description' => 'Utilisateur ' . $username . ' cree',
        ]);

        return redirect()->to(site_url('admin/users'))->with('success', 'Utilisateur créé.');
    }

    public function show(int $id)
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return redirect()->to(site_url('admin/users'))->with('error', 'Utilisateur introuvable.');
        }

        $authz = AuthorizationService::forUser($id);

        return view('admin/users/show', [
            'title'       => $user['display_name'] ?: $user['username'],
            'user'        => $user,
            'roles'       => $authz->roles(),
            'permissions' => $authz->permissions(),
        ]);
    }

    public function edit(int $id)
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return redirect()->to(site_url('admin/users'))->with('error', 'Utilisateur introuvable.');
        }

        return view('admin/users/form', [
            'title'     => 'Modifier ' . ($user['display_name'] ?: $user['username']),
            'mode'      => 'edit',
            'user'      => $user,
            'allRoles'  => $this->roles->orderBy('code', 'ASC')->findAll(),
            'userRoles' => $this->userRoles->roleIdsForUser($id),
        ]);
    }

    public function update(int $id)
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return redirect()->to(site_url('admin/users'))->with('error', 'Utilisateur introuvable.');
        }

        $rules = [
            'username'     => "required|max_length[100]|is_unique[app_users.username,id,{$id}]",
            'display_name' => 'permit_empty|max_length[190]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->users->update($id, [
            'username'     => trim((string) $this->request->getPost('username')),
            'display_name' => trim((string) $this->request->getPost('display_name')) ?: null,
            'is_active'    => $this->request->getPost('is_active') ? 1 : 0,
        ]);

        $this->userRoles->syncUserRoles($id, (array) $this->request->getPost('roles'));

        $this->audit->log('USER_UPDATED', [
            'module'      => 'users',
            'target_type' => 'app_user',
            'target_id'   => $id,
            'description' => 'Roles: ' . (implode(',', AuthorizationService::forUser($id)->roles()) ?: 'aucun'),
        ]);

        return redirect()->to(site_url('admin/users'))->with('success', 'Utilisateur mis à jour.');
    }

    public function disable(int $id)
    {
        return $this->toggle($id, false);
    }

    public function enable(int $id)
    {
        return $this->toggle($id, true);
    }

    private function toggle(int $id, bool $active)
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return redirect()->to(site_url('admin/users'))->with('error', 'Utilisateur introuvable.');
        }

        $this->users->update($id, ['is_active' => $active ? 1 : 0]);

        $this->audit->log($active ? 'USER_ENABLED' : 'USER_DISABLED', [
            'module'      => 'users',
            'target_type' => 'app_user',
            'target_id'   => $id,
            'description' => $user['username'],
        ]);

        return redirect()->to(site_url('admin/users'))
            ->with('success', $active ? 'Utilisateur réactivé.' : 'Utilisateur désactivé.');
    }
}
