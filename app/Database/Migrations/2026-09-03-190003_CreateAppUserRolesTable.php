<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * user <-> role association. Structured for many-to-many even though the UI
 * currently assigns a single role per user.
 */
class CreateAppUserRolesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'role_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'role_id']);
        $this->forge->addForeignKey('user_id', 'app_users', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('role_id', 'app_roles', 'id', '', 'CASCADE');
        $this->forge->createTable('app_user_roles', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_user_roles', true);
    }
}
