<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * role <-> permission association (many-to-many).
 */
class CreateAppRolePermissionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'role_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'permission_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['role_id', 'permission_id']);
        $this->forge->addForeignKey('role_id', 'app_roles', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('permission_id', 'app_permissions', 'id', '', 'CASCADE');
        $this->forge->createTable('app_role_permissions', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_role_permissions', true);
    }
}
