<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AppPermissionModel;
use App\Models\AppRoleModel;
use App\Models\AppRolePermissionModel;
use App\Services\AuditService;

/**
 * Administration > Rôles.
 */
class RolesController extends BaseController
{
    private AppRoleModel $roles;
    private AppPermissionModel $permissions;
    private AppRolePermissionModel $rolePermissions;
    private AuditService $audit;

    public function __construct()
    {
        $this->roles           = new AppRoleModel();
        $this->permissions     = new AppPermissionModel();
        $this->rolePermissions = new AppRolePermissionModel();
        $this->audit           = new AuditService();
    }

    public function index()
    {
        return view('admin/roles/index', [
            'title' => lang('Admin.roles.title'),
            'roles' => $this->roles->orderBy('code', 'ASC')->findAll(),
        ]);
    }

    public function create()
    {
        return view('admin/roles/form', [
            'title' => lang('Admin.roles.new'),
            'mode'  => 'create',
            'role'  => ['id' => null, 'code' => '', 'name' => '', 'description' => '', 'is_active' => 1],
        ]);
    }

    public function store()
    {
        if (! $this->validate($this->withLabels($this->roles->getValidationRules(), ['code' => 'Admin.roles.code', 'name' => 'Admin.roles.name']))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $id = $this->roles->insert([
            'code'        => strtoupper(trim((string) $this->request->getPost('code'))),
            'name'        => trim((string) $this->request->getPost('name')),
            'description' => trim((string) $this->request->getPost('description')) ?: null,
            'is_active'   => $this->request->getPost('is_active') ? 1 : 0,
        ], true);

        $this->audit->log('ROLE_CREATED', [
            'module' => 'roles', 'target_type' => 'app_role', 'target_id' => $id,
            'description' => (string) $this->request->getPost('code'),
        ]);

        return redirect()->to(site_url('admin/users/roles'))->with('success', lang('Admin.roles.created'));
    }

    public function edit(int $id)
    {
        $role = $this->roles->find($id);
        if ($role === null) {
            return redirect()->to(site_url('admin/users/roles'))->with('error', lang('Admin.roles.notFound'));
        }

        return view('admin/roles/form', [
            'title' => lang('Admin.roles.editTitle', ['name' => $role['name']]),
            'mode'  => 'edit',
            'role'  => $role,
        ]);
    }

    public function update(int $id)
    {
        $role = $this->roles->find($id);
        if ($role === null) {
            return redirect()->to(site_url('admin/users/roles'))->with('error', lang('Admin.roles.notFound'));
        }

        $rules        = $this->roles->getValidationRules();
        $rules['code'] = "required|max_length[60]|regex_match[/^[A-Z0-9_]+$/]|is_unique[app_roles.code,id,{$id}]";

        if (! $this->validate($this->withLabels($rules, ['code' => 'Admin.roles.code', 'name' => 'Admin.roles.name']))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->roles->update($id, [
            'code'        => strtoupper(trim((string) $this->request->getPost('code'))),
            'name'        => trim((string) $this->request->getPost('name')),
            'description' => trim((string) $this->request->getPost('description')) ?: null,
            'is_active'   => $this->request->getPost('is_active') ? 1 : 0,
        ]);

        $this->audit->log('ROLE_UPDATED', [
            'module' => 'roles', 'target_type' => 'app_role', 'target_id' => $id,
            'description' => $role['code'],
        ]);

        return redirect()->to(site_url('admin/users/roles'))->with('success', lang('Admin.roles.updated'));
    }

    /**
     * GET: permission matrix for one role. POST: save it.
     */
    public function permissions(int $id)
    {
        $role = $this->roles->find($id);
        if ($role === null) {
            return redirect()->to(site_url('admin/users/roles'))->with('error', lang('Admin.roles.notFound'));
        }

        if ($this->request->is('post')) {
            $this->rolePermissions->syncRolePermissions($id, (array) $this->request->getPost('permissions'));

            $this->audit->log('PERMISSION_UPDATED', [
                'module' => 'roles', 'target_type' => 'app_role', 'target_id' => $id,
                'description' => 'Permissions du role ' . $role['code'] . ' mises a jour',
            ]);

            return redirect()->to(site_url('admin/users/roles'))->with('success', lang('Admin.roles.permissionsSaved'));
        }

        return view('admin/roles/permissions', [
            'title'       => lang('Admin.roles.permissionsTitle', ['name' => $role['name']]),
            'role'        => $role,
            'permissions' => $this->permissions->allOrdered(),
            'granted'     => $this->rolePermissions->permissionIdsForRole($id),
        ]);
    }
}
