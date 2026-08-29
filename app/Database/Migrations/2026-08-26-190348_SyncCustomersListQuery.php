<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Re-syncs tools.query_definition for CUSTOMERS_LIST with the current
 * content of app/Models/Extractor.sql. The query in that file was replaced
 * (the old multi-CTE segmentation query with @powernet_db_link was swapped
 * for a flat SELECT against the pre-materialized CMS_RFC.TB_CUSTOMERS_LIST
 * table) but the database copy the app actually executes was never updated
 * to match — this migration only propagates that already-made edit, it does
 * not alter the query itself. Only whitespace is trimmed from the file ends.
 */
class SyncCustomersListQuery extends Migration
{
    public function up()
    {
        $path = APPPATH . 'Models/Extractor.sql';

        if (! is_file($path)) {
            throw new RuntimeException("Extractor.sql not found at {$path}.");
        }

        $sql = trim(file_get_contents($path));

        $this->db->table('tools')
            ->where('code', 'CUSTOMERS_LIST')
            ->update(['query_definition' => $sql]);
    }

    public function down()
    {
        // Not reversible: the previous query text is not retained here.
        // Restore from version control history if ever needed.
    }
}
