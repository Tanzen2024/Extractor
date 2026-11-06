<?php

namespace App\Services\Snapshot;

use DateTimeImmutable;

/**
 * The freshness state shown to users:
 *
 *   failed  the latest attempt failed, and nothing newer was activated since
 *   late    no successful synchronisation within the expected delay
 *   fresh   the last successful synchronisation is within the expected delay
 *
 * Decided from the date the active version was really validated — never from
 * a customer date. A run that found the source unchanged is not a failure,
 * but it does not make the data any fresher either.
 */
final class SyncFreshness
{
    public const FRESH  = 'fresh';
    public const LATE   = 'late';
    public const FAILED = 'failed';

    /**
     * @param array<string, mixed>|null $lastRun Latest customers_sync_runs row.
     *
     * @return array{state: string, running: bool, interrupted: bool}
     */
    public static function evaluate(
        ?DateTimeImmutable $lastSuccessAt,
        ?array $lastRun,
        DateTimeImmutable $now,
        int $maxAgeHours,
        int $staleRunHours,
    ): array {
        $status      = $lastRun['status'] ?? null;
        $startedAt   = self::date($lastRun['started_at'] ?? null);
        $finishedAt  = self::date($lastRun['finished_at'] ?? null);
        $interrupted = $status === 'running'
            && ($startedAt === null || $now->getTimestamp() - $startedAt->getTimestamp() > $staleRunHours * 3600);
        $running     = $status === 'running' && ! $interrupted;

        $attemptAt = $finishedAt ?? $startedAt;
        $failed    = ($status === 'failed' || $interrupted)
            && ($lastSuccessAt === null || $attemptAt === null || $attemptAt > $lastSuccessAt);

        if ($failed) {
            $state = self::FAILED;
        } elseif ($lastSuccessAt !== null && $now->getTimestamp() - $lastSuccessAt->getTimestamp() <= $maxAgeHours * 3600) {
            $state = self::FRESH;
        } else {
            $state = self::LATE;
        }

        return ['state' => $state, 'running' => $running, 'interrupted' => $interrupted];
    }

    /** Next daily occurrence of 'HH:MM' strictly after $now, or null if unknown. */
    public static function nextRun(?string $dailyAt, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($dailyAt === null || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $dailyAt, $m) !== 1) {
            return null;
        }

        $next = $now->setTime((int) $m[1], (int) $m[2]);

        return $next > $now ? $next : $next->modify('+1 day');
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
