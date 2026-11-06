<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Same sync as SyncCustomersListQuery, for the edit of app/Models/Extractor.sql
 * that came after it without a migration: the stored text still selected the
 * misspelt NIU_QC and lacked XCOORD, YCOORD, NIU_TO_RECLASS and UPDATED_AT.
 * CustomersListQuerySyncTest reported the drift. Nothing runs this text today
 * (ExtractionController refuses the customers list), so no result changes.
 */
class ResyncCustomersListQuery extends Migration
{
    public function up()
    {
        $path = APPPATH . 'Models/Extractor.sql';

        if (! is_file($path)) {
            throw new RuntimeException("Extractor.sql not found at {$path}.");
        }

        $this->db->table('tools')
            ->where('code', 'CUSTOMERS_LIST')
            ->update(['query_definition' => trim(file_get_contents($path))]);
    }

    public function down()
    {
        // Not reversible: the previous query text is not retained here.
        // Restore from version control history if ever needed.
    }
}
