<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateToolsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'             => ['type' => 'CHAR', 'constraint' => 36],
            'module_id'        => ['type' => 'INT', 'unsigned' => true],
            'code'             => ['type' => 'VARCHAR', 'constraint' => 60],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 150],
            'description'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'icon'             => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'fa-regular fa-file-lines'],
            'route'            => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'query_definition' => ['type' => 'TEXT', 'null' => true],
            'display_order'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_active'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['module_id', 'code']);
        $this->forge->addKey('module_id');
        $this->forge->addForeignKey('module_id', 'modules', 'id', '', 'CASCADE');
        $this->forge->createTable('tools');
    }

    public function down()
    {
        $this->forge->dropTable('tools');
    }
}
