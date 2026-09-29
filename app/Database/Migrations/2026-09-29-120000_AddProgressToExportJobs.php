<?php

namespace App\Database\Migrations;

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
 */
class AddProgressToExportJobs extends Migration
{
    public function up()
    {
        $this->forge->addColumn('export_jobs', [
            'rows_total'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'row_count'],
            'rows_processed' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0, 'after' => 'rows_total'],
            'rows_exported'  => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0, 'after' => 'rows_processed'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('export_jobs', ['rows_total', 'rows_processed', 'rows_exported']);
    }
}
