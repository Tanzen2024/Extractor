<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'          => ['type' => 'CHAR', 'constraint' => 36],
            'role_id'       => ['type' => 'INT', 'unsigned' => true],
            'username'      => ['type' => 'VARCHAR', 'constraint' => 60],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'password'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'full_name'     => ['type' => 'VARCHAR', 'constraint' => 150],
            'is_active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_login_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey('username');
        $this->forge->addKey('role_id');
        $this->forge->addForeignKey('role_id', 'roles', 'id', '', 'RESTRICT');
        $this->forge->createTable('users');
    }

    public function down()
    {
        $this->forge->dropTable('users');
    }
}
