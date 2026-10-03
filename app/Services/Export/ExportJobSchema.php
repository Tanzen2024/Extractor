<?php

namespace App\Services\Export;

use CodeIgniter\Database\BaseConnection;

/**
 * The export_jobs columns the code relies on — single source of truth for
 * the migrations (EnsureExportJobsSchema), the worker's preflight check
 * (ProcessExportJobs) and `php spark export:doctor`.
 *
 * Why it exists: a server once ran code that wrote rows_total before the
 * column had been migrated ("Unknown column 'rows_total' in 'field list'"),
 * which failed every job at its first progress write. The worker now
 * refuses to claim jobs while a required column is missing — they stay
 * 'pending' (and the error is logged) instead of all ending in 'error'.
 */
final class ExportJobSchema
{
    public const TABLE = 'export_jobs';

    /**
     * Forge definitions, in table order. The first migration
     * (CreateExportJobsTable) and AddProgressToExportJobs create the same
     * columns; EnsureExportJobsSchema adds back any that is missing.
     *
     * @var array<string, array<string, mixed>>
     */
    public const COLUMNS = [
        'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
        'uuid'            => ['type' => 'CHAR', 'constraint' => 36],
        'requested_by'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
        'format'          => ['type' => 'VARCHAR', 'constraint' => 8],
        'filters'         => ['type' => 'LONGTEXT', 'null' => true],
        'filters_label'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        // Snapshot version counted and launched on — the worker reads exactly this one.
        'snapshot_version' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
        'status'          => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'pending'], // pending|running|done|error|cancelled
        'row_count'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        'rows_total'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        'rows_processed'  => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
        'rows_exported'   => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
        'file_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        'file_name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
        'file_size'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
        'error_reference' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
        'started_at'      => ['type' => 'DATETIME', 'null' => true],
        'finished_at'     => ['type' => 'DATETIME', 'null' => true],
        'created_at'      => ['type' => 'DATETIME', 'null' => true],
        'updated_at'      => ['type' => 'DATETIME', 'null' => true],
    ];

    /** @return list<string> */
    public static function requiredColumns(): array
    {
        return array_keys(self::COLUMNS);
    }

    /**
     * Required columns absent from the live table, read fresh each call
     * (a zero-row SELECT, not BaseConnection::getFieldNames(), which caches
     * per connection — a long-lived worker must see a migration run after
     * it started).
     *
     * @return list<string>|null null = the table itself does not exist
     */
    public static function missingColumns(BaseConnection $db): ?array
    {
        if (! self::tableExists($db)) {
            return null;
        }

        $result  = $db->table(self::TABLE)->where('1 = 0', null, false)->get();
        $present = $result === false ? [] : array_map('strtolower', $result->getFieldNames());

        return array_values(array_filter(
            self::requiredColumns(),
            static fn (string $column): bool => ! in_array($column, $present, true),
        ));
    }

    /** Uncached (a migration may have run since this connection listed tables). */
    public static function tableExists(BaseConnection $db): bool
    {
        return $db->tableExists($db->prefixTable(self::TABLE), false); // the uncached path does not add DBPrefix
    }
}
