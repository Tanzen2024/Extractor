<?php

namespace App\Services\CustomersList;

use RuntimeException;

/**
 * Thrown when a dashboard request carries a filter value the server did not
 * offer (an unknown REGION, a malformed date, an out-of-whitelist sort
 * column, ...). The controller turns this into a 422 without ever reaching
 * Oracle — the frontend only ever submits values it was handed by
 * /dashboard/filter-options, so this only fires on tampering or a stale page.
 */
class InvalidFilterException extends RuntimeException
{
}
