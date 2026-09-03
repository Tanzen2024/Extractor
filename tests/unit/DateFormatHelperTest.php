<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * format_date_fr() / format_datetime_fr() — user-facing dd/mm/yyyy formatting.
 * Presentation only; must never emit 01/01/1970 or "Invalid Date".
 *
 * @internal
 */
final class DateFormatHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('bscd');
    }

    public function testIsoDateBecomesFrench(): void
    {
        $this->assertSame('03/09/2026', format_date_fr('2026-09-03'));
        $this->assertSame('15/01/2026', format_date_fr('2026-01-15'));
        $this->assertSame('31/12/2027', format_date_fr('2027-12-31'));
    }

    public function testDatetimeInputKeepsOnlyTheDate(): void
    {
        $this->assertSame('03/09/2026', format_date_fr('2026-09-03 14:30:05'));
    }

    public function testAlreadyFrenchIsUnchanged(): void
    {
        $this->assertSame('03/09/2026', format_date_fr('03/09/2026'));
    }

    public function testEmptyOrNullOrZeroDateGivesPlaceholder(): void
    {
        $this->assertSame('—', format_date_fr(null));
        $this->assertSame('—', format_date_fr(''));
        $this->assertSame('—', format_date_fr('   '));
        $this->assertSame('—', format_date_fr('0000-00-00'));
        $this->assertSame('—', format_date_fr('0000-00-00 00:00:00'));
        $this->assertSame('n/a', format_date_fr(null, 'n/a'));
    }

    public function testUnparseableGivesPlaceholderNotEpoch(): void
    {
        $this->assertSame('—', format_date_fr('not a date'));
        $this->assertNotSame('01/01/1970', format_date_fr(''));
    }

    public function testDatetimeFrKeepsHoursAndMinutesOnly(): void
    {
        $this->assertSame('03/09/2026 14:30', format_datetime_fr('2026-09-03 14:30:05'));
        $this->assertSame('31/12/2026 00:00', format_datetime_fr('2026-12-31'));
        $this->assertSame('—', format_datetime_fr(null));
        $this->assertSame('03/09/2026 14:30', format_datetime_fr('03/09/2026 14:30'));
    }

    public function testLeapAndBoundaryDates(): void
    {
        $this->assertSame('29/02/2028', format_date_fr('2028-02-29'));
        $this->assertSame('01/01/2026', format_date_fr('2026-01-01'));
    }
}
