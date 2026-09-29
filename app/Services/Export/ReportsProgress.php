<?php

namespace App\Services\Export;

/**
 * Optional companion of RowSource for sources that know how many rows they
 * have to scan (the local snapshot): reports how far stream() has got, so an
 * asynchronous export job can show a real percentage. Sources that delegate
 * filtering (Oracle) don't implement it.
 */
interface ReportsProgress
{
    /** Rows scanned between two progress calls. */
    public const PROGRESS_EVERY_ROWS = 25_000;

    /**
     * Listener for the next stream() calls, or null to stop reporting.
     *
     * It is called once before the first row (processed = 0), every
     * PROGRESS_EVERY_ROWS rows scanned, and once when the scan completes
     * (processed = total) — never per row. It must stay cheap: it runs inside
     * the scan loop.
     *
     * @param (callable(int $processed, int $total, int $exported): void)|null $listener
     *     processed = rows scanned so far (matching or not), total = rows to
     *     scan, exported = rows handed to onRow so far.
     */
    public function setProgressListener(?callable $listener): void;
}
