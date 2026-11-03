<?php

use App\Services\CustomersList\ActiveCustomers;
use App\Services\CustomersList\AllowedValues;
use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\InvalidFilterException;
use App\Services\CustomersList\QueryBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The application's perimeter: only the 4 active contract statuses exist for
 * Extractor (App\Services\CustomersList\ActiveCustomers), and no filter can
 * bring any other customer back.
 *
 * @internal
 */
final class ActiveCustomersTest extends CIUnitTestCase
{
    /** Every STATUS value of the 2026-10-03 snapshot, with the expected verdict. */
    public static function realStatuses(): array
    {
        return [
            'ACTIVE'                          => ['ACTIVE', true],
            'ACTIVE (PENDING BILLING)'        => ['ACTIVE (PENDING BILLING)', true],
            'SUSPENDED (DELINQUENT ACCOUNT)'  => ['SUSPENDED (DELINQUENT ACCOUNT)', true],
            'INACTIVATION IN PROCESS.'        => ['INACTIVATION IN PROCESS.', true],
            'INACTIVE WITH BALANCE.'          => ['INACTIVE WITH BALANCE.', false],
            'INACTIVE.'                       => ['INACTIVE.', false],
            'IN PROCESS (PENDING CONNECTION)' => ['IN PROCESS (PENDING CONNECTION)', false],
            'IN PROCESS (PENDING READING)'    => ['IN PROCESS (PENDING READING)', false],
        ];
    }

    /** Null, blank, unknown, near-misses: never active. */
    public static function outOfPerimeter(): array
    {
        return [
            'null'                    => [null],
            'vide'                    => [''],
            'espaces'                 => ['   '],
            'inconnu'                 => ['UNKNOWN'],
            'casse différente'        => ['active'],
            'préfixe ACTIVE'          => ['ACTIVE (SOMETHING ELSE)'],
            'sans le point final'     => ['INACTIVATION IN PROCESS'],
            'orthographe DELINQUANT'  => ['SUSPENDED (DELINQUANT ACCOUNT)'],
            'SUSPENDED seul'          => ['SUSPENDED'],
        ];
    }

    /** @dataProvider realStatuses */
    public function testTheBusinessTable(string $status, bool $active): void
    {
        $this->assertSame($active, ActiveCustomers::isActive($status));
    }

    /** @dataProvider outOfPerimeter */
    public function testNullBlankAndUnknownStatusesAreNeverActive(?string $status): void
    {
        $this->assertFalse(ActiveCustomers::isActive($status));
    }

    public function testSurroundingBlanksOfTheSourceAreIgnoredButNothingElse(): void
    {
        $this->assertTrue(ActiveCustomers::isActive('  ACTIVE  '));
        $this->assertFalse(ActiveCustomers::isActive('ACTIVE  X'));
    }

    public function testExactlyFourStatuses(): void
    {
        $this->assertCount(4, ActiveCustomers::STATUSES);
        $this->assertSame(['ACTIVE', 'INACTIVATION IN PROCESS.'], ActiveCustomers::restrict(['INACTIVE.', 'ACTIVE', '', 'ACTIVE', 'INACTIVATION IN PROCESS.']));
    }

    public function testNoUserFilterMeansTheFourActiveStatuses(): void
    {
        $none = FilterCriteria::none();

        $this->assertSame([], $none->statuses);
        $this->assertSame(ActiveCustomers::STATUSES, $none->effectiveStatuses());
        $this->assertTrue($none->isEmpty(), 'no user filter');
    }

    public function testTickingTheFourIsTheSameAsNothing(): void
    {
        $all = FilterCriteria::fromArray(['statuses' => array_reverse(ActiveCustomers::STATUSES)]);

        $this->assertSame([], $all->statuses);
        $this->assertSame(FilterCriteria::none()->cacheKey(), $all->cacheKey());
    }

    public function testASelectionNarrowsInsideThePerimeter(): void
    {
        $c = FilterCriteria::fromArray(['statuses' => ['SUSPENDED (DELINQUENT ACCOUNT)']]);

        $this->assertSame(['SUSPENDED (DELINQUENT ACCOUNT)'], $c->effectiveStatuses());
    }

    public function testAStoredSelectionLosesItsExcludedStatuses(): void
    {
        // e.g. an export job queued before the perimeter: excluded statuses dropped, never widened.
        $c = FilterCriteria::fromArray(['statuses' => ['ACTIVE', 'INACTIVE.']]);

        $this->assertSame(['ACTIVE'], $c->effectiveStatuses());
    }

    public function testAStoredSelectionOfExcludedStatusesOnlyIsRefusedNeverWidened(): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterCriteria::fromArray(['statuses' => ['INACTIVE.', 'INACTIVE WITH BALANCE.']]);
    }

    public function testARequestForAnExcludedStatusIsRejectedEvenIfTheOptionsListIt(): void
    {
        $allowed = AllowedValues::fromFilterOptions(['statuses' => [
            ['value' => 'ACTIVE', 'count' => 3], ['value' => 'INACTIVE.', 'count' => 2], ['value' => 'IN PROCESS (PENDING READING)', 'count' => 1],
        ]]);
        $this->assertSame(['ACTIVE'], $allowed->statuses);

        foreach (['INACTIVE.', 'IN PROCESS (PENDING READING)', '', 'active'] as $status) {
            try {
                $c = FilterCriteria::fromRequest(['status' => [$status]], $allowed);
                // '' is dropped like any blank form value: no filter = the perimeter.
                $this->assertSame('', $status);
                $this->assertSame(ActiveCustomers::STATUSES, $c->effectiveStatuses());
            } catch (InvalidFilterException) {
                $this->assertNotSame('', $status);
            }
        }
    }

    public function testOtherFiltersCannotBringExcludedCustomersBack(): void
    {
        // Region, segmentation, meter, dates…: STATUS stays restricted.
        $c = FilterCriteria::fromArray(['regions' => ['DRC'], 'segmentations' => ['5-Dormant'], 'meters' => ['PREPAID'], 'dateFrom' => '1990-01-01']);
        $this->assertSame(ActiveCustomers::STATUSES, $c->effectiveStatuses());
        $this->assertSame(ActiveCustomers::STATUSES, $c->withoutSegmentations()->effectiveStatuses());
    }

    public function testTheOracleToolsQueriesCarryThePerimeterToo(): void
    {
        $qb = new QueryBuilder();

        foreach ([$qb->where(FilterCriteria::none()), $qb->referenceStatement(), $qb->exportStatement(FilterCriteria::none())] as $stmt) {
            $this->assertStringContainsString('STATUS IN (', $stmt['sql']);
            foreach (ActiveCustomers::STATUSES as $status) {
                $this->assertContains($status, $stmt['binds']);
            }
            $this->assertNotContains('INACTIVE.', $stmt['binds']);
        }
    }
}
