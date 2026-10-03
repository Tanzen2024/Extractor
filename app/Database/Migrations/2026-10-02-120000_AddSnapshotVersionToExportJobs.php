<?php

namespace App\Database\Migrations;

use App\Services\Export\ExportJobSchema;
use CodeIgniter\Database\Migration;

/**
 * export_jobs.snapshot_version — the snapshot version an export was counted
 * and launched on (POST /dashboard/export). The worker reads exactly that
 * version, never "whatever is active when the job is claimed": a job queued
 * at 04:59 on V10 is still generated from V10 after the 05:00 refresh has
 * activated V11. SnapshotStore::prune() keeps every version a pending or
 * running job still references.
 *
 * NULL = a job queued before this column existed (the worker then uses the
 * active version, as before). Idempotent like AddProgressToExportJobs.
 */
class AddSnapshotVersionToExportJobs extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();

        if (! $this->db->fieldExists('snapshot_version', ExportJobSchema::TABLE)) {
            $this->forge->addColumn(ExportJobSchema::TABLE, [
                'snapshot_version' => ExportJobSchema::COLUMNS['snapshot_version'] + ['after' => 'filters_label'],
            ]);
            $this->db->resetDataCache();
        }
    }

    public function down()
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists('snapshot_version', ExportJobSchema::TABLE)) {
            $this->forge->dropColumn(ExportJobSchema::TABLE, 'snapshot_version');
            $this->db->resetDataCache();
        }
    }
}
