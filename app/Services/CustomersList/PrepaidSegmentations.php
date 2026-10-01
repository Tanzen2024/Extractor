<?php

namespace App\Services\CustomersList;

/**
 * The 8 PREPAID segmentation categories produced upstream by
 * prepaid_profile_p.sql — exact SQL values (never rewritten here), their
 * display label, in the STRICT business display order.
 *
 * Only some of them exist in TB_CUSTOMERS_LIST at a given time (2026-09-30:
 * 5-Dormant, 6 Old_Dormant, 7 Never Vending, Other), so the filter options
 * are completed with the missing ones (count 0): every category can always
 * be selected and validated, and keeps its slot in the chart.
 *
 * Mirrored by PREPAID_SEGMENTATIONS / SEGMENTATION_LABELS in
 * public/assets/js/dashboard.js (kept in sync by a test).
 */
final class PrepaidSegmentations
{
    /** SQL value => display label, in display order. */
    public const LABELS = [
        '1-Stable'          => 'Stable',
        '2-InStable'        => 'Instable',
        '3-At Risk'         => 'À risque',
        '4-Suspect Dormant' => 'Suspect Dormant',
        '5-Dormant'         => 'Dormant',
        '6 Old_Dormant'     => 'Old Dormant',
        '7 Never Vending'   => 'Never Vending',
        'Other'             => 'Other',
    ];

    public const METER = 'PREPAID';

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Appends the PREPAID categories missing from a filter-options payload's
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
     * True when the meter filter is PREPAID only and every PREPAID category
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
