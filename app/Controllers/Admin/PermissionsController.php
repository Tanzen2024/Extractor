<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AppPermissionModel;
use App\Services\AuditService;

/**
 * Administration > Permissions.
 *
 * The permission catalogue is seed-defined (it mirrors real features in code).
 * This screen lets an authorised admin consult it and enable/disable an entry;
 * attaching permissions to a role is done from the Rôles screen.
 */
class PermissionsController extends BaseController
{
    private AppPermissionModel $permissions;
    private AuditService $audit;

    public function __construct()
    {
        $this->permissions = new AppPermissionModel();
        $this->audit       = new AuditService();
    }

    public function index()
    {
        return view('admin/permissions/index', [
            'title'       => 'Permissions',
            'permissions' => $this->permissions->allOrdered(),
        ]);
    }

    public function toggle(int $id)
    {
        $permission = $this->permissions->find($id);
        if ($permission === null) {
            return redirect()->to(site_url('admin/users/permissions'))->with('error', 'Permission introuvable.');
        }

        $new = (int) $permission['is_active'] === 1 ? 0 : 1;
        $this->permissions->update($id, ['is_active' => $new]);

        $this->audit->log('PERMISSION_UPDATED', [
            'module'      => 'permissions',
            'target_type' => 'app_permission',
            'target_id'   => $id,
            'description' => $permission['code'] . ' -> ' . ($new ? 'active' : 'inactive'),
        ]);

        return redirect()->to(site_url('admin/users/permissions'))->with('success', 'Permission mise à jour.');
    }
}
