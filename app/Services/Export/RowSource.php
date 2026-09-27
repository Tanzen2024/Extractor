<?php

namespace App\Services\Export;

use App\Services\CustomersList\FilterCriteria;

/**
 * Where CustomerListExportService gets its rows from. The writers only ever
 * see associative rows keyed by CustomerListExportService::COLUMNS, so the
 * source can change without touching CSV/XLSX generation.
 */
interface RowSource
{
    /**
     * Streams every row matching $criteria to $onRow (one associative array
     * per row, keyed by column name), in source order. Returns the number of
     * rows streamed. Never buffers the result set.
     *
     * @param callable(array<string, mixed>): void $onRow
     */
    public function stream(FilterCriteria $criteria, callable $onRow): int;

    /** Short label for logs and export meta, e.g. "oracle" or "snapshot:<version>". */
    public function label(): string;
}
