<?php

namespace App\Services\CustomersList;

/**
 * The 3 analytic components of the "6 Défaillant" profile, shown in the
 * Postpaid section only. They are NOT profiles: SEGMENTATION never holds
 * them, the Segmentation filter never offers them, exports carry no column
 * for them.
 *
 * The rule is the FAILURE_SUBCATEGORY column (28) of
 * docs/snapshot/source/customers_list.sql. The source table runs on the
 * 27-column model (decision of 2026-11-16), so it does not carry that
 * column: customers:refresh computes it in its extraction query from the
 * same data (oracleRule() over oracleLastRows(), joined on CONTRACT, for the
 * defaillantLabels() rows only) and reads the column itself again if the
 * table ever carries it. The conditions of that rule overlap, so their order decides:
 * ALWAYS_LATE, then NEVER_PAID, then SOMETIME_PAID, and NEVER_PAID again for
 * the rest of the profile (business decision of 2026-11-09: there is no
 * OTHER component any more). Every Défaillant customer therefore gets
 * exactly one.
 */
final class FailureSubcategories
{
    public const COLUMN = 'FAILURE_SUBCATEGORY';

    public const ALWAYS_LATE   = 'ALWAYS_LATE';
    public const NEVER_PAID    = 'NEVER_PAID';
    public const SOMETIME_PAID = 'SOMETIME_PAID';

    /** Display order. @var list<string> */
    public const VALUES = [self::ALWAYS_LATE, self::NEVER_PAID, self::SOMETIME_PAID];

    /**
     * A snapshot produced before the column still says it in SEGMENTATION
     * (folded into 6 Défaillant by PostpaidSegmentations::LEGACY on read).
     * The former "10 Other" goes to NEVER_PAID, like OTHER in the source rule.
     */
    public const LEGACY = [
        '6 Always Late'   => self::ALWAYS_LATE,
        '7 sometime Paid' => self::SOMETIME_PAID,
        '8 Never Paid'    => self::NEVER_PAID,
        '10 Other'        => self::NEVER_PAID,
        '8 Autre'         => self::NEVER_PAID, // former unclassified: last resort of the rule
    ];

    /** Monthly payment history the rule reads: one row per contract (SERVICE_NO) and month (DATE_). */
    public const SOURCE_TABLE = 'CMS_RFC.POSTPAID_PROFILE_P';

    /**
     * Every raw SEGMENTATION value read as 6 Défaillant: the profile itself
     * and every former / corrupted spelling PostpaidSegmentations::LEGACY
     * folds into it. Exactly the population of the chart's centre total.
     *
     * @return list<string>
     */
    public static function defaillantLabels(): array
    {
        $labels = [PostpaidSegmentations::DEFAILLANT];
        foreach (PostpaidSegmentations::LEGACY as $from => $to) {
            if ($to === PostpaidSegmentations::DEFAILLANT) {
                $labels[] = (string) $from;
            }
        }

        return $labels;
    }

    /**
     * The rule as an Oracle expression over the LAST row of the contract in
     * SOURCE_TABLE (alias $pp, NULL columns when the contract has none):
     * the inner CASE of column 28 of docs/snapshot/source/customers_list.sql,
     * word for word (FailureSubcategoriesTest keeps both in step). The ELSE
     * (no row, bill_number 0, RATE NULL or > 0, incoherent counters) is the
     * last resort of the rule: NEVER_PAID. Never NULL.
     */
    public static function oracleRule(string $pp = 'pp'): string
    {
        return "CASE\n"
            . "            WHEN {$pp}.RATE = 0 AND {$pp}.BAD_COUNT = {$pp}.BILL_NUMBER AND {$pp}.BILL_NUMBER > 0 THEN '" . self::ALWAYS_LATE . "'\n"
            . "            WHEN {$pp}.RATE = 0 AND {$pp}.NO_PAID_COUNT = {$pp}.BILL_NUMBER AND {$pp}.BILL_NUMBER > 0 THEN '" . self::NEVER_PAID . "'\n"
            . "            WHEN {$pp}.RATE = 0 AND {$pp}.NO_PAID_COUNT < {$pp}.BILL_NUMBER AND {$pp}.BILL_NUMBER > 0 THEN '" . self::SOMETIME_PAID . "'\n"
            . "            ELSE '" . self::NEVER_PAID . "'\n"
            . '        END';
    }

    /**
     * The last SOURCE_TABLE row of each contract (latest DATE_), as a derived
     * table: one row per SERVICE_NO, so a LEFT JOIN on it never multiplies a
     * customer. Same selection as the `calculated` CTE of customers_list.sql.
     */
    public static function oracleLastRows(): string
    {
        return "(\n"
            . "    SELECT SERVICE_NO, BILL_NUMBER, RATE, BAD_COUNT, NO_PAID_COUNT\n"
            . "    FROM (\n"
            . "        SELECT SERVICE_NO, BILL_NUMBER, RATE, BAD_COUNT, NO_PAID_COUNT,\n"
            . "               ROW_NUMBER() OVER (PARTITION BY SERVICE_NO ORDER BY DATE_ DESC NULLS LAST) AS RN\n"
            . '        FROM ' . self::SOURCE_TABLE . "\n"
            . "    )\n"
            . "    WHERE RN = 1\n"
            . ')';
    }

    public static function label(string $value): string
    {
        return match ($value) {
            self::ALWAYS_LATE   => lang('Dashboard.failure.alwaysLate'),
            self::NEVER_PAID    => lang('Dashboard.failure.neverPaid'),
            self::SOMETIME_PAID => lang('Dashboard.failure.sometimePaid'),
            default             => $value,
        };
    }

    /**
     * The breakdown of a population: its Défaillant total and the 3
     * components, in order, zeros included. Both come from the same query
     * and the same criteria, so the denominator is the chart's.
     *
     * The control: `consistent` is true only when the 3 components add up
     * exactly to the total. `unassigned` = Défaillant rows without a
     * component (a snapshot produced before the column was computed): never
     * put in a component, never shown as a 4th one; an inconsistent breakdown
     * is logged and the chart is not drawn (dashboard.js).
     *
     * @param array<string, int> $segmentation rows per SEGMENTATION value
     * @param array<string, int> $components   rows per component
     *
     * @return array{total:int, components:list<array{value:string,count:int}>, unassigned:int, consistent:bool}
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

        $consistent = $sum === $total;
        if (! $consistent && function_exists('log_message')) {
            log_message('error', 'Décomposition Défaillant incohérente : {sum} répartis pour un total de {total} (snapshot sans ' . self::COLUMN . ' ? relancer customers:refresh).', ['sum' => $sum, 'total' => $total]);
        }

        return ['total' => $total, 'components' => $rows, 'unassigned' => max(0, $total - $sum), 'consistent' => $consistent];
    }
}
