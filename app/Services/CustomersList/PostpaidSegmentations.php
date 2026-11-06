<?php

namespace App\Services\CustomersList;

/**
 * The 6 profiles shared by POSTPAID and Compteurs Communicants — exact SQL
 * values, in the STRICT business display order.
 *
 * Extractor does not classify anyone: the rule lives once, in the
 * profile_calculation CTE of docs/snapshot/source/customers_list.sql, and
 * reaches the application through SEGMENTATION. A customer the rule leaves
 * unclassified (bill_number = 0, or no billing history) has a NULL
 * SEGMENTATION: it belongs to no profile and is counted apart ("Non classé").
 *
 * Mirrored by POSTPAID_SEGMENTATIONS in public/assets/js/dashboard.js (kept
 * in sync by a test).
 */
final class PostpaidSegmentations
{
    /** @var list<string> */
    public const VALUES = [
        '1 PERFECT',
        '2 RELIABLE',
        '3 Occasionnal',
        '4 At-Risk',
        '5 Delinquant',
        '6 Défaillant',
    ];

    public const DEFAILLANT = '6 Défaillant';

    /**
     * Former SEGMENTATION values => what they are now ('' = unclassified).
     * Snapshots produced before the rule keep them in their CSV, which is
     * never rewritten: every read of a snapshot goes through this table, so
     * no screen, filter or export can show them. A snapshot produced by the
     * rule holds none of them and is left as it is.
     */
    public const LEGACY = [
        '6 Always Late'   => '6 Défaillant',
        '7 sometime Paid' => '6 Défaillant',
        '8 Never Paid'    => '6 Défaillant',
        '10 Other'        => '6 Défaillant',
        '8 Autre'         => '',
    ];

    /** Mixed into the dashboard cache keys: figures cached before LEGACY are not served. */
    public const CACHE_TAG = 'seg6p6';

    /** Every former SEGMENTATION value, both universes. */
    public const ALL_LEGACY = self::LEGACY + PrepaidSegmentations::LEGACY;

    /**
     * Applies ALL_LEGACY to a filter-options payload: former values merged into
     * their profile, unclassified ones no longer offered.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function foldOptions(array $options): array
    {
        $counts = [];
        foreach ($options['segmentations'] ?? [] as $row) {
            $value = (string) $row['value'];
            $value = self::ALL_LEGACY[$value] ?? $value;
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + (int) $row['count'];
            }
        }

        $options['segmentations'] = [];
        foreach ($counts as $value => $count) {
            $options['segmentations'][] = ['value' => (string) $value, 'count' => $count];
        }

        return $options;
    }

    /**
     * Appends the profiles missing from a filter-options payload's
     * segmentation list, at count 0 (existing entries untouched): each one
     * can always be selected and validated, even when no customer has it.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function completeOptions(array $options): array
    {
        $list    = $options['segmentations'] ?? [];
        $present = array_map(static fn (array $row): string => (string) $row['value'], $list);

        foreach (self::VALUES as $value) {
            if (! in_array($value, $present, true)) {
                $list[] = ['value' => $value, 'count' => 0];
            }
        }
        $options['segmentations'] = $list;

        return $options;
    }
}
