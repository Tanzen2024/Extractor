<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Application users. Identity only — authentication is delegated to Active
 * Directory, so this table intentionally has NO password / password_hash
 * column. `username` mirrors the AD sAMAccountName and is the join key.
 */
class CreateAppUsersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'username'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'is_active'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('username');
        $this->forge->createTable('app_users', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_users', true);
    }
}
