<?php

namespace App\Services\Export;

use Closure;

/**
 * Progress listener (see ReportsProgress) that forwards to $write at most
 * once every $minIntervalSeconds, so an export job's progress costs a few
 * dozen UPDATEs per export instead of one per batch — let alone per row.
 *
 * Always forwarded regardless of the interval: the first call (so the total
 * is known right away) and the completion call (processed >= total, so the
 * final counters are exact).
 */
final class ThrottledProgress
{
    private Closure $write;
    private Closure $clock;
    private ?float $lastWriteAt = null;
    private int $writes = 0;

    /**
     * @param callable(int $processed, int $total, int $exported): void $write
     * @param (callable(): float)|null $clock seconds, monotonic — tests only
     */
    public function __construct(callable $write, private readonly float $minIntervalSeconds = 1.0, ?callable $clock = null)
    {
        $this->write = Closure::fromCallable($write);
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn (): float => hrtime(true) / 1e9;
    }

    public function __invoke(int $processed, int $total, int $exported): void
    {
        $now   = ($this->clock)();
        $final = $total > 0 && $processed >= $total;

        if (! $final && $this->lastWriteAt !== null && $now - $this->lastWriteAt < $this->minIntervalSeconds) {
            return;
        }

        ($this->write)($processed, $total, $exported);
        $this->lastWriteAt = $now;
        $this->writes++;
    }

    /** Number of calls actually forwarded to $write. */
    public function writes(): int
    {
        return $this->writes;
    }
}
