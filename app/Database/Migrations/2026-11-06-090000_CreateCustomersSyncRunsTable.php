<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * customers_sync_runs — one row per real attempt of `php spark
 * customers:refresh` (a run that finds another one in progress, or a catch-up
 * that finds today's data already active, records nothing). Additive and
 * idempotent; the customer data itself stays in the snapshot versions.
 */
class CreateCustomersSyncRunsTable extends Migration
{
    private const TABLE = 'customers_sync_runs';

    public function up()
    {
        if ($this->db->tableExists(self::TABLE)) {
            return;
        }

        $count = ['type' => 'INT', 'unsigned' => true, 'null' => true];

        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'trigger_type'      => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'manual'], // manual|scheduled
            'status'            => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'running'], // running|success|failed|skipped
            'started_at'        => ['type' => 'DATETIME'],
            'finished_at'       => ['type' => 'DATETIME', 'null' => true],
            'duration_seconds'  => $count,
            'rows_read'         => $count,
            'rows_added'        => $count,
            'rows_modified'     => $count,
            'rows_ignored'      => $count, // present in both versions, unchanged
            'rows_removed'      => $count,
            'rows_inactivated'  => $count, // left the 4 active statuses
            'rows_active'       => $count,
            'rows_duplicate'    => $count,
            'error_count'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'error_reason'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error_message'     => ['type' => 'TEXT', 'null' => true],
            'snapshot_version'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'previous_version'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'source_updated_at' => ['type' => 'VARCHAR', 'constraint' => 19, 'null' => true],
            'host'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'pid'               => $count,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status');
        $this->forge->createTable(self::TABLE, true);
    }

    public function down()
    {
        $this->forge->dropTable(self::TABLE, true);
    }
}
