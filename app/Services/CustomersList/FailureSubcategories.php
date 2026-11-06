<?php

namespace App\Services\CustomersList;

/**
 * The 4 analytic components of the "6 Défaillant" profile, shown in the
 * Postpaid section only. They are NOT profiles: SEGMENTATION never holds
 * them, the Segmentation filter never offers them, exports carry no column
 * for them.
 *
 * Extractor does not classify anyone: the rule lives in the
 * FAILURE_SUBCATEGORY column of docs/snapshot/source/customers_list.sql.
 * The conditions of that rule overlap, so their order decides:
 * ALWAYS_LATE, then NEVER_PAID, then SOMETIME_PAID, OTHER for the rest of
 * the profile. Every Défaillant customer therefore gets exactly one.
 */
final class FailureSubcategories
{
    public const COLUMN = 'FAILURE_SUBCATEGORY';

    public const ALWAYS_LATE   = 'ALWAYS_LATE';
    public const SOMETIME_PAID = 'SOMETIME_PAID';
    public const NEVER_PAID    = 'NEVER_PAID';
    public const OTHER         = 'OTHER';

    /** Display order. @var list<string> */
    public const VALUES = [self::ALWAYS_LATE, self::SOMETIME_PAID, self::NEVER_PAID, self::OTHER];

    /**
     * A snapshot produced before the column still says it in SEGMENTATION
     * (folded into 6 Défaillant by PostpaidSegmentations::LEGACY on read).
     */
    public const LEGACY = [
        '6 Always Late'   => self::ALWAYS_LATE,
        '7 sometime Paid' => self::SOMETIME_PAID,
        '8 Never Paid'    => self::NEVER_PAID,
        '10 Other'        => self::OTHER,
    ];

    public static function label(string $value): string
    {
        return match ($value) {
            self::ALWAYS_LATE   => lang('Dashboard.failure.alwaysLate'),
            self::SOMETIME_PAID => lang('Dashboard.failure.sometimePaid'),
            self::NEVER_PAID    => lang('Dashboard.failure.neverPaid'),
            self::OTHER         => lang('Dashboard.failure.other'),
            default             => $value,
        };
    }

    /**
     * The breakdown of a population: its Défaillant total and the 4
     * components, in order, zeros included. `unassigned` = Défaillant rows
     * without a component (a snapshot that carries neither the column nor the
     * former labels): reported, never hidden in a component.
     *
     * @param array<string, int> $segmentation rows per SEGMENTATION value
     * @param array<string, int> $components   rows per component
     *
     * @return array{total:int, components:list<array{value:string,count:int}>, unassigned:int}
     */
    public static function breakdown(array $segmentation, array $components): array
    {
        $total = (int) ($segmentation[PostpaidSegmentations::DEFAILLANT] ?? 0);
        $rows  = [];
        $sum   = 0;
        foreach (self::VALUES as $value) {
            $count  = (int) ($components[$value] ?? 0);
            $rows[] = ['value' => $value, 'count' => $count];
            $sum += $count;
        }

        return ['total' => $total, 'components' => $rows, 'unassigned' => max(0, $total - $sum)];
    }
}
