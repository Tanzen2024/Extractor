<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AuditLogModel;

/**
 * Administration > Audit.
 */
class AuditController extends BaseController
{
    public function index()
    {
        $model = new AuditLogModel();

        $filters = [
            'username'  => trim((string) $this->request->getGet('username')),
            'action'    => trim((string) $this->request->getGet('action')),
            'date_from' => trim((string) $this->request->getGet('date_from')),
            'date_to'   => trim((string) $this->request->getGet('date_to')),
        ];

        $feed = $model->feed($filters);

        return view('admin/audit/index', [
            'title'   => 'Audit',
            'rows'    => $feed['rows'],
            'pager'   => $feed['pager'],
            'filters' => $filters,
            'actions' => (new AuditLogModel())->knownActions(),
        ]);
    }
}
