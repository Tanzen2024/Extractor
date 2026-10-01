<?php

namespace App\Database\Migrations;

use App\Services\Export\ExportJobSchema;
use CodeIgniter\Database\Migration;

/**
 * Reconciles export_jobs with what the code expects (ExportJobSchema),
 * whatever the server's history: table created by hand, a migration
 * recorded without having really run, a column dropped manually...
 *
 *   - table missing        -> created with the full schema and its keys;
 *   - column(s) missing    -> added (never altered, never dropped);
 *   - everything present   -> nothing at all (safe to re-run).
 *
 * down() is intentionally a no-op: this migration only ever brings the
 * table up to the schema earlier migrations already define, so rolling it
 * back must not remove columns those migrations own.
 */
class EnsureExportJobsSchema extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();

        if (! ExportJobSchema::tableExists($this->db)) {
            $this->forge->addField(ExportJobSchema::COLUMNS);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('uuid');
            $this->forge->addKey(['status', 'id']);
            $this->forge->addKey('requested_by');
            $this->forge->createTable(ExportJobSchema::TABLE, true);

            return;
        }

        $previous = null;
        foreach (ExportJobSchema::COLUMNS as $column => $definition) {
            if ($column !== 'id' && ! $this->db->fieldExists($column, ExportJobSchema::TABLE)) {
                $this->forge->addColumn(ExportJobSchema::TABLE, [
                    $column => $definition + ($previous !== null ? ['after' => $previous] : []),
                ]);
                $this->db->resetDataCache();
            }
            $previous = $column;
        }
    }

    public function down()
    {
        // See class comment: nothing to undo.
    }
}
