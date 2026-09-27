<?php

namespace App\Services\Snapshot;

/**
 * No valid snapshot is active (never installed, pointer or files missing).
 * Exports must surface this as a controlled "reference data unavailable"
 * error — never fall back to an Oracle extraction.
 */
final class SnapshotUnavailableException extends SnapshotException
{
    public function __construct(string $message)
    {
        parent::__construct('snapshot_unavailable', $message);
    }
}
