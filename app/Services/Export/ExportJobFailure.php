<?php

namespace App\Services\Export;

use Throwable;

/**
 * Full, secret-free description of a failed export job — what the worker
 * writes to the application log AND to its own stderr (systemd journal),
 * so a failure is never reduced to its EXPJOB-... reference.
 *
 *   [EXPORT JOB ERROR]
 *   job_id=45
 *   uuid=...
 *   reference=EXPJOB-20261001-24093
 *   format=csv
 *   exception=RuntimeException
 *   message=...
 *   file=/var/www/extractor/app/Services/CustomerListExportService.php
 *   line=232
 *   trace=
 *   #0 ...
 *   caused_by=...          (one block per previous exception)
 *
 * No secrets: the trace is rebuilt from getTrace() WITHOUT the call
 * arguments (getTraceAsString() can print them — e.g. a DB config array
 * with its password), and anything looking like "password=..." in a
 * message is masked.
 */
final class ExportJobFailure
{
    private const MAX_PREVIOUS = 3;

    /** @param array<string, mixed> $job */
    public static function report(array $job, string $reference, Throwable $e): string
    {
        $lines = [
            '[EXPORT JOB ERROR]',
            'job_id=' . (int) ($job['id'] ?? 0),
            'uuid=' . ($job['uuid'] ?? ''),
            'reference=' . $reference,
            'format=' . ($job['format'] ?? ''),
            'requested_by=' . ($job['requested_by'] ?? ''),
        ];

        return implode("\n", array_merge($lines, self::exceptionLines($e)));
    }

    /** Failure of the worker itself, outside any job (DB lost while polling, ...). */
    public static function workerReport(string $stage, Throwable $e): string
    {
        $lines = ['[EXPORT WORKER ERROR]', 'stage=' . $stage, 'pid=' . getmypid()];

        return implode("\n", array_merge($lines, self::exceptionLines($e)));
    }

    /** @return list<string> */
    private static function exceptionLines(Throwable $e): array
    {
        $lines = [];
        $depth = 0;
        for ($current = $e; $current !== null && $depth <= self::MAX_PREVIOUS; $current = $current->getPrevious(), $depth++) {
            if ($depth > 0) {
                $lines[] = 'caused_by:';
            }
            $lines[] = 'exception=' . $current::class;
            $lines[] = 'code=' . $current->getCode();
            $lines[] = 'message=' . self::sanitize($current->getMessage());
            $lines[] = 'file=' . $current->getFile();
            $lines[] = 'line=' . $current->getLine();
            $lines[] = 'trace=';
            $lines[] = self::trace($current);
        }

        return $lines;
    }

    /** One-line form for the console summary and the job's last details. */
    public static function summary(Throwable $e): string
    {
        return $e::class . ': ' . self::sanitize($e->getMessage()) . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
    }

    /** Stack trace without arguments. */
    public static function trace(Throwable $e): string
    {
        $out = [];
        foreach ($e->getTrace() as $i => $frame) {
            $where = isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? '?') . ')' : '[internal function]';
            $call  = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
            $out[] = "#{$i} {$where}: {$call}";
        }
        $out[] = '#' . count($out) . ' {main}';

        return implode("\n", $out);
    }

    /**
     * Masks credentials and neutralises CodeIgniter logger placeholders: the
     * logger substitutes {file}, {line} and {env:NAME} anywhere in a message,
     * so an exception message containing "{env:database.default.password}"
     * would otherwise print a secret.
     */
    public static function sanitize(string $message): string
    {
        $message = preg_replace('/\b(password|passwd|pwd|secret|token)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2***', $message) ?? $message;

        return str_replace(['{env:', '{file}', '{line}'], ['{ env:', '{ file}', '{ line}'], $message);
    }
}
