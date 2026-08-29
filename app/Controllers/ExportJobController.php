<?php

namespace App\Controllers;

use App\Models\ExportJobModel;

/**
 * Status + download for asynchronous CUSTOMERS_LIST export jobs.
 *
 *   GET /exports/{id}            JSON status (polled by the dashboard)
 *   GET /exports/{id}/download   the generated file, once status = done
 *
 * A job is only visible to the account that created it.
 */
class ExportJobController extends BaseController
{
    public function show($id)
    {
        $job = $this->ownedJob((int) $id);

        if ($job === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not_found']);
        }

        $payload = [
            'id'       => (int) $job['id'],
            'status'   => $job['status'],
            'format'   => $job['format'],
            'rowCount' => $job['row_count'] !== null ? (int) $job['row_count'] : null,
            'label'    => $job['filters_label'],
        ];

        if ($job['status'] === 'done') {
            $payload['downloadUrl'] = site_url("exports/{$job['id']}/download");
            $payload['fileSize']    = (int) $job['file_size'];
            $payload['fileName']    = $job['file_name'];
        }

        if ($job['status'] === 'error') {
            $payload['reference'] = $job['error_reference'];
        }

        return $this->response->setJSON($payload);
    }

    public function download($id)
    {
        $job = $this->ownedJob((int) $id);

        if ($job === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not_found']);
        }

        if ($job['status'] !== 'done') {
            return $this->response->setStatusCode(409)->setJSON(['error' => 'not_ready', 'status' => $job['status']]);
        }

        if (empty($job['file_path']) || ! is_file($job['file_path'])) {
            return $this->response->setStatusCode(410)->setJSON([
                'error'   => 'expired',
                'message' => "Le fichier de cet export n'est plus disponible. Relancez l'export.",
            ]);
        }

        return $this->response->download($job['file_path'], null)->setFileName($job['file_name']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ownedJob(int $id): ?array
    {
        $job = (new ExportJobModel())->find($id);

        if ($job === null) {
            return null;
        }

        return ((string) $job['requested_by'] === (string) (session('username') ?? '')) ? $job : null;
    }
}
