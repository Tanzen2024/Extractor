<?php

namespace App\Services\Export;

use RuntimeException;

/**
 * Thrown from inside an export (by the worker's progress listener) when its
 * job is no longer 'running' — the user cancelled it. It unwinds the scan
 * like any failure, so CustomerListExportService closes and deletes the
 * job's own partial file; the worker then records nothing more (the job is
 * already 'cancelled').
 */
final class ExportCancelledException extends RuntimeException
{
}
