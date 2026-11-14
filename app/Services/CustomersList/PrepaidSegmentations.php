<?php

namespace App\Services\CustomersList;

/**
 * The 6 official PREPAID segments — exact SQL values, in the STRICT business
 * display order.
 *
 * Extractor does not classify anyone: the rule lives once, in the prepaid_rfm
 * CTE and the SEGMENTATION column of docs/snapshot/source/customers_list.sql,
 * and reaches the application through SEGMENTATION and SEGMENT_RFM_2.
 *
 * A segment may be absent from a snapshot, so the filter options are
 * completed with the missing ones (count 0): every segment can always be
 * selected and validated, and keeps its slot in the chart.
 *
 * Mirrored by PREPAID_SEGMENTATIONS in public/assets/js/dashboard.js (kept in
 * sync by a test).
 */
final class PrepaidSegmentations
{
    /** @var list<string> */
    public const VALUES = [
        '1-Stable',
        '2-InStable',
        '3-At Risk',
        '4-Suspect Dormant',
        '5-Dormant',
        '6-Never Vending',
    ];

    /**
     * Former values => what they are now ('' = unclassified), applied on
     * every read: a snapshot produced before the rule keeps them in its CSV,
     * which is never rewritten. It holds no RECENSE, so its customers keep
     * their former segment under the new name until the next load.
     *
     * '6 Never Vending' (space, no hyphen) = the label the deployed upstream
     * SQL still gives a profile without purchase date (1 824 on 13/11, next to
     * 9 192 '7 Never Vending'): same business segment, folded into the
     * canonical '6-Never Vending' so the chart, filters and exports show one
     * category. Blank / unclassified values are never folded into it.
     */
    public const LEGACY = [
        '6 Old_Dormant'   => '5-Dormant',
        '6 Never Vending' => '6-Never Vending',
        '7 Never Vending' => '6-Never Vending',
        'Other'           => '',
    ];

    public const METER = 'PREPAID';

    /**
     * Segment given on read to a PREPAID customer whose SEGMENTATION is blank
     * (after LEGACY): never "Non renseigné" in the PREPAID universe.
     *
     * Validated 2026-11-13: the 30 005 blank ones of the 13/11 load are
     * customers with an RFM profile but a NULL upstream segment, all with a
     * last purchase 60+ days old, i.e. 5-Dormant by the official rule
     * (RECENSE >= 60) — exactly where the rule puts them once deployed
     * (Dormant 315 317 in the simulation). Other universes are not touched:
     * a blank POSTPAID / Compteurs Communicants profile stays blank.
     */
    public const BLANK_TO = '5-Dormant';

    /** @return list<string> */
    public static function values(): array
    {
        return self::VALUES;
    }

    /**
     * Appends the PREPAID segments missing from a filter-options payload's
     * segmentation list, at count 0 (existing entries untouched).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function completeOptions(array $options): array
    {
        $list    = $options['segmentations'] ?? [];
        $present = array_map(static fn (array $row): string => (string) $row['value'], $list);

        foreach (self::values() as $value) {
            if (! in_array($value, $present, true)) {
                $list[] = ['value' => $value, 'count' => 0];
            }
        }
        $options['segmentations'] = $list;

        return $options;
    }

    /**
     * True when the meter filter is PREPAID only and every PREPAID segment
     * is selected: the segmentation filter then restricts nothing and must
     * behave exactly like no selection (an IN list would also drop the
     * PREPAID rows whose SEGMENTATION is blank).
     *
     * @param list<string> $segmentations
     * @param list<string> $meters
     */
    public static function coversPrepaidScope(array $segmentations, array $meters): bool
    {
        if ($meters === []) {
            return false;
        }
        foreach ($meters as $meter) {
            if (strtoupper(trim($meter)) !== self::METER) {
                return false;
            }
        }

        return array_diff(self::values(), $segmentations) === [];
    }
}
