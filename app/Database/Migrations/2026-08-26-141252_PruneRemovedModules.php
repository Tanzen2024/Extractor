<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Removes SMARTCASH, ICN_CASHING, FACTURATION and POWERNET from the
 * functional scope. Tools cascade-delete with their parent module
 * (see CreateToolsTable's foreign key). CMS and MRA are untouched.
 */
class PruneRemovedModules extends Migration
{
    private array $removedModuleCodes = ['SMARTCASH', 'ICN_CASHING', 'FACTURATION', 'POWERNET'];

    public function up()
    {
        $this->db->table('modules')->whereIn('code', $this->removedModuleCodes)->delete();
    }

    public function down()
    {
        // Not reversible: re-add the module/tool definitions to
        // ModuleToolSeeder and re-run it if these modules come back.
    }
}
