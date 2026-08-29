<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateModulesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'          => ['type' => 'CHAR', 'constraint' => 36],
            'code'          => ['type' => 'VARCHAR', 'constraint' => 40],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'description'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'icon'          => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'fa-solid fa-layer-group'],
            'color'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'primary'],
            'display_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('modules');
    }

    public function down()
    {
        $this->forge->dropTable('modules');
    }
}
