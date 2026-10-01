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

        // The file has since been replaced by a flat SELECT without that
        // marker (and without the glued label). Throwing here made a
        // from-scratch `php spark migrate` impossible; the whole trimmed file
        // is the right value — SyncCustomersListQuery (next) stores exactly
        // that anyway, so the end state is unchanged.
        $sql = $position === false ? trim($content) : substr($content, $position);

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
