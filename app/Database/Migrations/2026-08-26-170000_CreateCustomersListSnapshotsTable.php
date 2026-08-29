<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Local storage for CUSTOMERS_LIST extraction snapshots.
 *
 * The Oracle query (Extractor.sql) is expensive (parallel hints, a
 * cross-database link, several ranked sub-queries) and is never persisted
 * row-by-row locally — only a compact aggregate ("cube": one counter per
 * distinct combination of dimension values actually present in the result)
 * plus run metadata are stored here. This lets the dashboard render and be
 * filtered instantly without re-querying Oracle, while "Actualiser" remains
 * the only explicit trigger that re-runs the extraction.
 */
class CreateCustomersListSnapshotsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'                 => ['type' => 'CHAR', 'constraint' => 36],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'running'],
            'started_at'           => ['type' => 'DATETIME', 'null' => true],
            'finished_at'          => ['type' => 'DATETIME', 'null' => true],
            'duration_seconds'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'row_count'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'distinct_client_count' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'source'               => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'CMS_RFC'],
            'error_reference'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'totals'               => ['type' => 'LONGTEXT', 'null' => true],
            'dimensions'           => ['type' => 'LONGTEXT', 'null' => true],
            'cube'                 => ['type' => 'LONGTEXT', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['status', 'finished_at']);
        $this->forge->createTable('customers_list_snapshots');
    }

    public function down()
    {
        $this->forge->dropTable('customers_list_snapshots');
    }
}
