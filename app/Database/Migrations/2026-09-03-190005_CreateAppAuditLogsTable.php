<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Security & administration audit trail. Append-only in practice.
 * user_id has no FK: an audit row must survive the deletion of its subject.
 * A password is NEVER written here.
 */
class CreateAppAuditLogsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'username'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'action'      => ['type' => 'VARCHAR', 'constraint' => 60],
            'module'      => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'target_type' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'target_id'   => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('action');
        $this->forge->addKey('username');
        $this->forge->addKey('created_at');
        $this->forge->createTable('app_audit_logs', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_audit_logs', true);
    }
}
