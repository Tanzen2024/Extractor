<?php

namespace App\Controllers;

use App\Models\ExportJobModel;

/**
 * Status + download for asynchronous CUSTOMERS_LIST export jobs.
 *
 *   GET /exports/{id}            JSON status (polled by the dashboard)
 *   GET /exports/{id}/download   the generated file, once status = done
 *   POST /exports/{id}/cancel    cancel a pending / running job
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

        // Real scan progress (0..100, see ExportJobModel::progress()). Not
        // sent for 'error', whose payload stays as it was. For 'cancelled' it
        // is where the scan stopped (history only — never a download).
        if (in_array($job['status'], ['pending', 'running', 'done', 'cancelled'], true)) {
            $payload['progress'] = ExportJobModel::progress($job);
        }

        // Processing duration (started_at → finished_at, server clock).
        $payload['timing'] = ExportJobModel::timing($job);

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

    /**
     * POST /exports/{id}/cancel — real cancellation (see ExportJobModel::cancel()):
     *   pending -> cancelled at once, the worker will never claim it;
     *   running -> cancelled; the worker stops at its next progress write
     *              (within about a second) and deletes its partial file.
     * A terminal job (done / error / cancelled) is never modified: 409.
     */
    public function cancel($id)
    {
        $job = $this->ownedJob((int) $id);

        if ($job === null) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'error' => 'not_found']);
        }

        $model = new ExportJobModel();
        $from  = $model->cancel((int) $job['id']);

        if ($from === null) {
            $status = $model->statusOf((int) $job['id']) ?? $job['status'];

            return $this->response->setStatusCode(409)->setJSON([
                'success' => false,
                'error'   => 'already_finished',
                'status'  => $status,
                'message' => $status === 'cancelled' ? 'Cet export est déjà annulé.' : 'Cet export est déjà terminé.',
            ]);
        }

        log_message('info', '[EXPORT JOB] cancel requested job={id} by={user} from={from}', [
            'id' => $job['id'], 'user' => $job['requested_by'], 'from' => $from,
        ]);

        return $this->response->setJSON([
            'success'        => true,
            'status'         => 'cancelled',
            'previousStatus' => $from,
            // Honest about the file: a running job's partial file is removed
            // by the worker when it stops, not by this request.
            'message'        => $from === 'running'
                ? "Export annulé. Le traitement s'arrête au prochain lot ; son fichier partiel sera supprimé."
                : 'Export annulé avant son démarrage. Aucun fichier généré.',
            'timing'         => ExportJobModel::timing($model->find((int) $job['id']) ?? $job),
        ]);
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
        $username = (string) (session('username') ?? '');
        // Read-only from here: release the session lock so a poll is never
        // queued behind a slow request of the same user (e.g. /dashboard/stats
        // on Oracle), and a large download does not block the whole session.
        session()->close();

        $job = (new ExportJobModel())->find($id);

        if ($job === null) {
            return null;
        }

        return ((string) $job['requested_by'] === $username) ? $job : null;
    }
}
