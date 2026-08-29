<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * MARKETING (MI) becomes a CMS-only extraction platform. MRA was the last
 * remaining non-CMS module (SMARTCASH, ICN_CASHING, FACTURATION and POWERNET
 * were already removed by PruneRemovedModules). Its tool cascade-deletes with
 * it (see CreateToolsTable's foreign key). CMS is untouched.
 */
class PruneMraModule extends Migration
{
    public function up()
    {
        $this->db->table('modules')->where('code', 'MRA')->delete();
    }

    public function down()
    {
        // Not reversible: re-add the MRA module/tool definition to
        // ModuleToolSeeder and re-run it if this module comes back.
    }
}
