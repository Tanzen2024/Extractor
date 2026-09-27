<?php

namespace App\Services\Snapshot;

use DateTimeImmutable;

/**
 * Converts the snapshot's DATE_AB text into 'Y-m-d' for the date filter.
 *
 * The file's date format is not assumed: detect() picks, from a configured
 * candidate list, the first format that round-trips the first non-blank
 * value; install stores it in the version meta. toYmd() memoises per raw
 * string — a few thousand distinct dates over millions of rows — so the
 * per-row cost of the date filter is one array lookup.
 */
final class SnapshotDate
{
    /** @var array<string, string|false> */
    private array $memo = [];

    public function __construct(public readonly string $format)
    {
    }

    /**
     * @param list<string> $candidates
     */
    public static function detect(string $raw, array $candidates): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        foreach ($candidates as $format) {
            if (self::parse($raw, $format) !== false) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @return string|false 'Y-m-d', or false for a value this format can't read.
     */
    public function toYmd(string $raw): string|false
    {
        if (isset($this->memo[$raw])) {
            return $this->memo[$raw];
        }

        if (count($this->memo) > 200_000) {
            $this->memo = [];
        }

        return $this->memo[$raw] = self::parse(trim($raw), $this->format);
    }

    private static function parse(string $raw, string $format): string|false
    {
        if ($raw === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);

        // Round-trip check rejects overflow (31/02 -> 03/03) and partial
        // matches; case-insensitive for month abbreviations (JAN vs Jan).
        if ($date === false || strcasecmp($date->format($format), $raw) !== 0) {
            return false;
        }

        return $date->format('Y-m-d');
    }
}
