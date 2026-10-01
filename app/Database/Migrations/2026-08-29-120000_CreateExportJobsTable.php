<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Queue for asynchronous CUSTOMERS_LIST exports.
 *
 * A filtered export that stays under Config\Oracle::$exportSyncMaxRows is
 * generated in-request; anything larger would outlast the front web server's
 * request timeout, so the request only records a row here and returns a job
 * id. `php spark export:process` (cron or manual) picks pending jobs up,
 * runs CustomerListExportService with the stored FilterCriteria, and writes
 * the resulting file path back.
 */
class CreateExportJobsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'            => ['type' => 'CHAR', 'constraint' => 36],
            'requested_by'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'format'          => ['type' => 'VARCHAR', 'constraint' => 8], // csv | xlsx
            'filters'         => ['type' => 'LONGTEXT', 'null' => true],   // FilterCriteria::toArray() as JSON
            'filters_label'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'pending'], // pending|running|done|error
            'row_count'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'file_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'file_name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'file_size'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'error_reference' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'started_at'      => ['type' => 'DATETIME', 'null' => true],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['status', 'id']);
        $this->forge->addKey('requested_by');
        // IF NOT EXISTS: a table created by hand (or by a restored dump)
        // without its migration row must not block `php spark migrate`.
        $this->forge->createTable('export_jobs', true);
    }

    public function down()
    {
        $this->forge->dropTable('export_jobs', true);
    }
}
