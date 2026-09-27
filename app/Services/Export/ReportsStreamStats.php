<?php

namespace App\Services\Export;

/**
 * Optional companion of RowSource for sources that filter rows themselves
 * (the local snapshot): what the last stream() read and kept, and where its
 * time went. Sources that delegate filtering (Oracle) don't implement it.
 */
interface ReportsStreamStats
{
    /**
     * Figures of the last completed or interrupted stream() call.
     *
     * @return array{rows_read: int, rows_matched: int, read_s: float, filter_s: float}
     *     read_s   = time reading and splitting records (stream time minus filter and onRow);
     *     filter_s = time spent evaluating the filters.
     */
    public function lastStreamStats(): array;
}
