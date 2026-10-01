<?php

namespace App\Database\Migrations;

use App\Services\Export\ExportJobSchema;
use CodeIgniter\Database\Migration;

/**
 * Progress counters for asynchronous exports, polled by the dashboard.
 *
 *   rows_total      rows the export has to scan (snapshot: all its rows,
 *                   whatever the filters) — set when the worker starts;
 *   rows_processed  rows scanned so far;
 *   rows_exported   rows written to the file so far.
 *
 * The percentage is derived (rows_processed / rows_total), never stored.
 * The worker writes these in batches (at most once a second), never per row.
 * Additive only: existing rows keep NULL / 0.
 *
 * Idempotent: a column that already exists (added by hand on a server
 * before this migration was recorded) is skipped instead of failing
 * `php spark migrate` with "Duplicate column name".
 */
class AddProgressToExportJobs extends Migration
{
    private const AFTER = ['rows_total' => 'row_count', 'rows_processed' => 'rows_total', 'rows_exported' => 'rows_processed'];

    public function up()
    {
        $this->db->resetDataCache(); // fieldExists() reads a per-connection cache

        foreach (self::AFTER as $column => $after) {
            if (! $this->db->fieldExists($column, 'export_jobs')) {
                $this->forge->addColumn('export_jobs', [
                    $column => ExportJobSchema::COLUMNS[$column] + ['after' => $after],
                ]);
                $this->db->resetDataCache();
            }
        }
    }

    public function down()
    {
        $this->db->resetDataCache();

        foreach (array_keys(self::AFTER) as $column) {
            if ($this->db->fieldExists($column, 'export_jobs')) {
                $this->forge->dropColumn('export_jobs', $column);
                $this->db->resetDataCache();
            }
        }
    }
}
