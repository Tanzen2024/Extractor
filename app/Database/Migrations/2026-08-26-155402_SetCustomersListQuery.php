<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Loads the CMS "Customers List" extraction query from app/Models/Extractor.sql
 * into tools.query_definition, verbatim from the point the actual SQL starts.
 *
 * The file's first line carries a non-SQL human label
 * ("first draft of the customerlist segmentation:") glued directly in front
 * of the query with no line break — that label is not valid SQL and is
 * excluded here; not one token of the query itself (joins, CTEs, conditions,
 * column names, hints, comments) is altered. The file is read at migration
 * time and copied as-is — never retyped — so there is no transcription risk.
 */
class SetCustomersListQuery extends Migration
{
    private const MARKER = 'WITH lister AS (';

    public function up()
    {
        $path = APPPATH . 'Models/Extractor.sql';

        if (! is_file($path)) {
            throw new RuntimeException("Extractor.sql not found at {$path}.");
        }

        $content = file_get_contents($path);
        $position = strpos($content, self::MARKER);

        if ($position === false) {
            throw new RuntimeException('Could not locate the start of the SQL query in Extractor.sql.');
        }

        $sql = substr($content, $position);

        $this->db->table('tools')
            ->where('code', 'CUSTOMERS_LIST')
            ->update(['query_definition' => $sql]);
    }

    public function down()
    {
        $this->db->table('tools')
            ->where('code', 'CUSTOMERS_LIST')
            ->update(['query_definition' => null]);
    }
}
