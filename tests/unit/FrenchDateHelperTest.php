<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * French date parsing / conversion — the reference contract mirrored in
 * public/assets/js/frdatepicker.js (parseFr / toIso).
 *
 *   display  dd/mm/yyyy   <->   transport  yyyy-mm-dd
 *
 * @internal
 */
final class FrenchDateHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('bscd');
    }

    /**
     * @dataProvider validDates
     */
    public function testFrenchDateToIso(string $fr, string $iso): void
    {
        $this->assertSame($iso, fr_date_to_iso($fr));
    }

    public static function validDates(): array
    {
        return [
            '14 janvier'      => ['14/01/2026', '2026-01-14'],
            '18 février'      => ['18/02/2026', '2026-02-18'],
            '1er août'        => ['01/08/2026', '2026-08-01'],
            '8 janvier'       => ['08/01/2026', '2026-01-08'],
            '31 août'         => ['31/08/2026', '2026-08-31'],
            '1er janvier'     => ['01/01/2026', '2026-01-01'],
            'non zero-padded' => ['1/8/2026', '2026-08-01'],
            '29 fév bissext.' => ['29/02/2028', '2028-02-29'],
        ];
    }

    /** 01/08 vs 08/01 — the day/month inversion trap. */
    public function testDayAndMonthAreNeverSwapped(): void
    {
        $this->assertSame('2026-08-01', fr_date_to_iso('01/08/2026'), '01/08/2026 = 1 août');
        $this->assertSame('2026-01-08', fr_date_to_iso('08/01/2026'), '08/01/2026 = 8 janvier');
        $this->assertSame(['y' => 2026, 'm' => 8, 'd' => 1], parse_fr_date('01/08/2026'));
        $this->assertSame(['y' => 2026, 'm' => 1, 'd' => 8], parse_fr_date('08/01/2026'));
    }

    /**
     * @dataProvider invalidDates
     */
    public function testInvalidFrenchDateReturnsNull(string $bad): void
    {
        $this->assertNull(fr_date_to_iso($bad));
        $this->assertNull(parse_fr_date($bad));
    }

    public static function invalidDates(): array
    {
        return [
            '31 février'      => ['31/02/2026'],
            'jour 32'         => ['32/01/2026'],
            'jour 00'         => ['00/01/2026'],
            'mois 13'         => ['01/13/2026'],
            'mois 00'         => ['01/00/2026'],
            '29 fév non-bis.' => ['29/02/2027'],
            '31 avril'        => ['31/04/2026'],
            'format ISO'      => ['2026-01-14'],
            'format US tiret' => ['01-14-2026'],
            'texte'           => ['pas une date'],
            'vide'            => [''],
            'année courte'    => ['14/01/26'],
        ];
    }

    public function testNullIsNull(): void
    {
        $this->assertNull(fr_date_to_iso(null));
        $this->assertNull(parse_fr_date(null));
    }

    /** Round-trip with the display helper from the earlier task. */
    public function testRoundTripWithFormatDateFr(): void
    {
        $this->assertSame('14/01/2026', format_date_fr(fr_date_to_iso('14/01/2026')));
        $this->assertSame('2026-08-01', fr_date_to_iso(format_date_fr('2026-08-01')));
    }
}
