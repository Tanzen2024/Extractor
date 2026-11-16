<?php

namespace App\Services\CustomersList;

/**
 * "Ancienneté du dernier achat" (Prepaid section): days between the
 * reference date and the last purchase of a PREPAID customer, in 3 bands,
 * plus the 3 situations without a number of days. An ANALYSIS, never a
 * segment: SEGMENTATION, its thresholds and the Segmentation filter are not
 * affected, exports carry no column for it.
 *
 * Extractor computes no date: the source query
 * (docs/snapshot/source/customers_list.sql, columns 29 and 30) delivers
 *   PREPAID_RECENCY_DAYS    integer >= 0 when the status is KNOWN, else empty;
 *   PREPAID_RECENCY_STATUS  KNOWN | NO_PURCHASE | POST_REFERENCE | UNKNOWN,
 *                           empty for any non-PREPAID customer.
 * This class only sorts a (status, days) pair into one class. The rule is
 * written twice — classify() for the PHP reference engine and sqlCase() for
 * DuckDB — and a test runs both over the same pairs.
 *
 * A pair that breaks the source contract (KNOWN without a valid number,
 * another status WITH a number, an unknown or blank status on a PREPAID
 * row) is INCONSISTENT: shown as "Date inconnue" and counted apart, never
 * put in a band.
 */
final class PrepaidRecency
{
    public const DAYS_COLUMN   = 'PREPAID_RECENCY_DAYS';
    public const STATUS_COLUMN = 'PREPAID_RECENCY_STATUS';

    /** Source statuses (column 30). */
    public const KNOWN          = 'KNOWN';
    public const NO_PURCHASE    = 'NO_PURCHASE';
    public const POST_REFERENCE = 'POST_REFERENCE';
    public const UNKNOWN        = 'UNKNOWN';

    /** Internal class of a pair that breaks the source contract (folded into UNKNOWN on display). */
    public const INCONSISTENT = 'INCONSISTENT';

    /**
     * Day bands, inclusive bounds, no gap, no overlap; null = no upper bound
     * (360 days and more: never capped).
     *
     * @var array<string, array{0:int, 1:?int}>
     */
    public const BANDS = [
        'D0_29'     => [0, 29],
        'D30_59'    => [30, 59],
        'D60_PLUS'  => [60, null],
    ];

    /** Display order: the 3 bands, then the situations without days. @var list<string> */
    public const CLASSES = ['D0_29', 'D30_59', 'D60_PLUS', self::NO_PURCHASE, self::POST_REFERENCE, self::UNKNOWN];

    /** Part of the /dashboard/stats cache key: bump when the answer's shape changes. */
    public const CACHE_TAG = 'recency2';

    /** The METER value of the population (same as the Prepaid section). */
    public const METER = PrepaidSegmentations::METER;

    /** Both columns are needed: a snapshot holding only one is treated as holding none. */
    public static function availableIn(array $columns): bool
    {
        return in_array(self::DAYS_COLUMN, $columns, true) && in_array(self::STATUS_COLUMN, $columns, true);
    }

    /**
     * Class of one PREPAID customer.
     *
     * @return string one of CLASSES, or INCONSISTENT
     */
    public static function classify(?string $status, ?string $days): string
    {
        $status = strtoupper(trim((string) $status));
        $days   = trim((string) $days);

        if ($status === self::KNOWN) {
            if (preg_match('/^\d{1,9}$/', $days) !== 1) {
                return self::INCONSISTENT; // KNOWN needs a whole number of days >= 0
            }
            $n = (int) $days;
            foreach (self::BANDS as $band => [$min, $max]) {
                if ($n >= $min && ($max === null || $n <= $max)) {
                    return $band;
                }
            }
        }
        if (in_array($status, [self::NO_PURCHASE, self::POST_REFERENCE, self::UNKNOWN], true)) {
            return $days === '' ? $status : self::INCONSISTENT;
        }

        return self::INCONSISTENT;
    }

    /**
     * DuckDB twin of classify() for a row of the index (both columns are
     * VARCHAR there, blanks ''). '' for any non-PREPAID row: not counted.
     */
    public static function sqlCase(): string
    {
        $status = 'upper(trim(' . self::STATUS_COLUMN . '))';
        $days   = 'trim(' . self::DAYS_COLUMN . ')';
        $n      = "TRY_CAST({$days} AS BIGINT)"; // never raises: the regexp guard decides

        $bands = '';
        foreach (self::BANDS as $band => [$min, $max]) {
            $bands .= " WHEN {$n} >= {$min}" . ($max === null ? '' : " AND {$n} <= {$max}") . " THEN '{$band}'";
        }

        return "CASE WHEN trim(METER) <> '" . self::METER . "' THEN ''"
            . " WHEN {$status} = '" . self::KNOWN . "' THEN CASE WHEN regexp_full_match({$days}, '[0-9]{1,9}') THEN CASE{$bands} END ELSE '" . self::INCONSISTENT . "' END"
            . " WHEN {$status} IN ('" . self::NO_PURCHASE . "', '" . self::POST_REFERENCE . "', '" . self::UNKNOWN . "')"
            . " THEN CASE WHEN {$days} = '' THEN {$status} ELSE '" . self::INCONSISTENT . "' END"
            . " ELSE '" . self::INCONSISTENT . "' END";
    }

    /**
     * The chart's data for a population.
     *
     * @param array<string, int>|null $counts rows per class (classify()/sqlCase() values);
     *                                        null = the snapshot does not carry the columns
     * @param int                     $total  PREPAID rows of the same population, counted
     *                                        independently (METER distribution): the denominator
     *
     * @return array{available:bool, total:int, classes:list<array{value:string,count:int}>, sum:int, difference:int, inconsistent:int}
     *         difference = total - sum: 0 when every PREPAID customer is in exactly one class
     */
    public static function breakdown(?array $counts, int $total): array
    {
        if ($counts === null) {
            return ['available' => false, 'total' => $total, 'classes' => [], 'sum' => 0, 'difference' => 0, 'inconsistent' => 0];
        }

        $inconsistent = (int) ($counts[self::INCONSISTENT] ?? 0);
        $classes      = [];
        $sum          = 0;
        foreach (self::CLASSES as $class) {
            $count = (int) ($counts[$class] ?? 0) + ($class === self::UNKNOWN ? $inconsistent : 0);
            $classes[] = ['value' => $class, 'count' => $count];
            $sum += $count;
        }

        return [
            'available'    => true,
            'total'        => $total,
            'classes'      => $classes,
            'sum'          => $sum,
            'difference'   => $total - $sum,
            'inconsistent' => $inconsistent,
        ];
    }
}
