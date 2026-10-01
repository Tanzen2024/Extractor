<?php

use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\PrepaidSegmentations;
use PHPUnit\Framework\TestCase;

/**
 * The 8 PREPAID segmentation categories: always offered and accepted (even
 * the ones absent from the data), filtered on their exact SQL values, and
 * "all 8 ticked under PREPAID" = no segmentation restriction.
 *
 * @internal
 */
final class PrepaidSegmentationsTest extends TestCase
{
    private const ALL = ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'];

    /** filter-options as the data gives it today: 4 of the 8 PREPAID values. */
    private function options(): array
    {
        return [
            'segmentations' => [
                ['value' => '5-Dormant', 'count' => 1091001], ['value' => '8 Autre', 'count' => 817910],
                ['value' => '6 Old_Dormant', 'count' => 131112], ['value' => '1 PERFECT', 'count' => 30043],
                ['value' => '7 Never Vending', 'count' => 9888], ['value' => 'Other', 'count' => 3890],
            ],
            'meters' => [['value' => 'PREPAID', 'count' => 2], ['value' => 'POSTPAID', 'count' => 2]],
        ];
    }

    public function testTheEightSqlValuesInBusinessOrder(): void
    {
        $this->assertSame(self::ALL, PrepaidSegmentations::values());
        $this->assertSame(
            ['Stable', 'Instable', 'À risque', 'Suspect Dormant', 'Dormant', 'Old Dormant', 'Never Vending', 'Other'],
            array_values(PrepaidSegmentations::LABELS),
        );
    }

    public function testCompleteOptionsAddsOnlyTheMissingCategoriesAtZero(): void
    {
        $out    = PrepaidSegmentations::completeOptions($this->options());
        $counts = array_column($out['segmentations'], 'count', 'value');

        foreach (self::ALL as $value) {
            $this->assertArrayHasKey($value, $counts, $value);
        }
        $this->assertSame(0, $counts['1-Stable']);
        $this->assertSame(0, $counts['4-Suspect Dormant']);
        $this->assertSame(1091001, $counts['5-Dormant']);
        $this->assertCount(10, $out['segmentations']); // 6 existing + 4 added, no duplicate
        $this->assertSame($this->options()['meters'], $out['meters']);
    }

    public function testACategoryAbsentFromTheDataIsAccepted(): void
    {
        $c = FilterCriteria::fromRequest(['segmentation' => ['1-Stable']], AllowedValues::fromFilterOptions($this->options()));

        $this->assertSame(['1-Stable'], $c->segmentations);
    }

    public function testPartialSelectionFiltersOnTheExactSqlValues(): void
    {
        $c = FilterCriteria::fromRequest(
            ['meter' => ['PREPAID'], 'segmentation' => ['5-Dormant', '6 Old_Dormant']],
            AllowedValues::fromFilterOptions($this->options()),
        );

        $this->assertSame(['5-Dormant', '6 Old_Dormant'], $c->segmentations);
    }

    public function testAllEightUnderPrepaidEqualsNoSelection(): void
    {
        $allowed = AllowedValues::fromFilterOptions($this->options());
        $all     = FilterCriteria::fromRequest(['meter' => ['PREPAID'], 'segmentation' => array_reverse(self::ALL)], $allowed);
        $none    = FilterCriteria::fromRequest(['meter' => ['PREPAID']], $allowed);

        $this->assertSame([], $all->segmentations);
        $this->assertSame($none->toArray(), $all->toArray());
    }

    public function testAllEightWithoutAPrepaidOnlyScopeStillRestricts(): void
    {
        // Type compteur = Toutes: the 8 PREPAID values exclude the POSTPAID rows.
        $allowed = AllowedValues::fromFilterOptions($this->options());
        $c       = FilterCriteria::fromRequest(['segmentation' => self::ALL], $allowed);

        $this->assertSame(self::ALL, $c->segmentations);
    }
}
