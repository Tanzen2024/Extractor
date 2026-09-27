<?php

namespace App\Services\Snapshot;

use RuntimeException;

/**
 * Base of every snapshot failure. `reason` is a stable machine code (logged,
 * written to rejected/<id>/reason.json, returned by snapshot:install) — the
 * message is the human-readable detail.
 */
class SnapshotException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
