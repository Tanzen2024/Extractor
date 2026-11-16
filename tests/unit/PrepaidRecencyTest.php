<?php

use App\Services\CustomersList\PrepaidRecency;
use App\Services\Snapshot\DuckDb;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * "Ancienneté du dernier achat": the class of a (PREPAID_RECENCY_STATUS,
 * PREPAID_RECENCY_DAYS) pair — inclusive bounds without gap or overlap,
 * the three situations without days kept apart, anything breaking the
 * source contract never put in a band — and the PHP rule == the DuckDB
 * CASE used by the dashboard engine.
 *
 * @internal
 */
final class PrepaidRecencyTest extends CIUnitTestCase
{
    /** @return array<string, array{0:?string, 1:?string, 2:string}> status, days, expected class */
    public static function pairs(): array
    {
        return [
            '0 jour'               => ['KNOWN', '0', 'D0_29'],
            '29 jours'             => ['KNOWN', '29', 'D0_29'],
            '30 jours'             => ['KNOWN', '30', 'D30_59'],
            '59 jours'             => ['KNOWN', '59', 'D30_59'],
            '60 jours'             => ['KNOWN', '60', 'D60_PLUS'],
            '89 jours'             => ['KNOWN', '89', 'D60_PLUS'],
            '360 jours'            => ['KNOWN', '360', 'D60_PLUS'],
            '1 095 jours (fin de fenêtre du profil)' => ['KNOWN', '1095', 'D60_PLUS'],
            '1 096 jours (ORDER_MASTER, hors fenêtre)' => ['KNOWN', '1096', 'D60_PLUS'],
            '12 000 jours (jamais plafonné)' => ['KNOWN', '12000', 'D60_PLUS'],
            'zéros en tête'        => ['KNOWN', '007', 'D0_29'],
            'espaces / casse'      => [' known ', ' 45 ', 'D30_59'],
            'aucun achat'          => ['NO_PURCHASE', '', 'NO_PURCHASE'],
            'achat postérieur à la référence' => ['POST_REFERENCE', '', 'POST_REFERENCE'],
            'date inconnue'        => ['UNKNOWN', '', 'UNKNOWN'],
            'date inconnue, NULL'  => ['UNKNOWN', null, 'UNKNOWN'],
            // Breaks the source contract: never a band, never a silent class.
            'KNOWN sans jours (NULL)'      => ['KNOWN', null, 'INCONSISTENT'],
            'KNOWN sans jours (vide)'      => ['KNOWN', '', 'INCONSISTENT'],
            'KNOWN négatif (date future)'  => ['KNOWN', '-3', 'INCONSISTENT'],
            'KNOWN décimal'                => ['KNOWN', '12.5', 'INCONSISTENT'],
            'KNOWN non numérique'          => ['KNOWN', 'abc', 'INCONSISTENT'],
            'KNOWN démesuré'               => ['KNOWN', '1234567890', 'INCONSISTENT'],
            'NO_PURCHASE avec des jours'   => ['NO_PURCHASE', '12', 'INCONSISTENT'],
            'POST_REFERENCE avec des jours' => ['POST_REFERENCE', '0', 'INCONSISTENT'],
            'statut inconnu'               => ['FUTURE', '', 'INCONSISTENT'],
            'statut vide'                  => ['', '', 'INCONSISTENT'],
            'statut NULL, jours seuls'     => [null, '10', 'INCONSISTENT'],
        ];
    }

    public function testEveryPairFallsInExactlyTheExpectedClass(): void
    {
        foreach (self::pairs() as $name => [$status, $days, $expected]) {
            $this->assertSame($expected, PrepaidRecency::classify($status, $days), $name);
        }
    }

    public function testBandsAreInclusiveContiguousAndUncapped(): void
    {
        $previousMax = -1;
        foreach (PrepaidRecency::BANDS as $band => [$min, $max]) {
            $this->assertSame($previousMax + 1, $min, "{$band} starts right after the previous band");
            $previousMax = $max ?? PHP_INT_MAX;
        }
        $bands = PrepaidRecency::BANDS;
        $this->assertNull(end($bands)[1], '60 days and more has no upper bound');

        // Every day from 0 to 2 000 in exactly one band.
        for ($d = 0; $d <= 2000; $d++) {
            $hits = array_filter(PrepaidRecency::BANDS, static fn (array $b): bool => $d >= $b[0] && ($b[1] === null || $d <= $b[1]));
            $this->assertCount(1, $hits, "{$d} days");
        }
        $this->assertSame(['D0_29', 'D30_59', 'D60_PLUS', 'NO_PURCHASE', 'POST_REFERENCE', 'UNKNOWN'], PrepaidRecency::CLASSES);
    }

    public function testBreakdownReconcilesWithThePrepaidTotalAndStatesAnyGap(): void
    {
        $b = PrepaidRecency::breakdown(['D0_29' => 5, 'D60_PLUS' => 2, 'NO_PURCHASE' => 1, 'UNKNOWN' => 1, 'INCONSISTENT' => 1], 10);
        $this->assertTrue($b['available']);
        $this->assertSame(PrepaidRecency::CLASSES, array_column($b['classes'], 'value'), 'every class, zeros included, in order');
        $this->assertSame([5, 0, 2, 1, 0, 2], array_column($b['classes'], 'count'), 'inconsistent pairs shown as unknown date');
        $this->assertSame(10, $b['sum']);
        $this->assertSame(0, $b['difference']);
        $this->assertSame(1, $b['inconsistent']);

        $gap = PrepaidRecency::breakdown(['D0_29' => 5], 7);
        $this->assertSame(2, $gap['difference'], 'a lost customer is reported, never hidden');

        $none = PrepaidRecency::breakdown(null, 1234);
        $this->assertFalse($none['available'], 'no source columns: no figure at all');
        $this->assertSame([], $none['classes']);
        $this->assertSame(0, $none['difference']);
    }

    public function testAvailabilityNeedsBothColumns(): void
    {
        $this->assertTrue(PrepaidRecency::availableIn(['METER', 'PREPAID_RECENCY_DAYS', 'PREPAID_RECENCY_STATUS']));
        $this->assertFalse(PrepaidRecency::availableIn(['METER', 'PREPAID_RECENCY_DAYS']));
        $this->assertFalse(PrepaidRecency::availableIn(['METER', 'PREPAID_RECENCY_STATUS']));
        $this->assertFalse(PrepaidRecency::availableIn([]));
    }

    /** The DuckDB CASE of the dashboard engine gives classify()'s answer for every pair; non-PREPAID rows get ''. */
    public function testSqlCaseIsTheTwinOfClassify(): void
    {
        $duck = new DuckDb();
        if ($duck->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $values = [];
        $i      = 0;
        foreach (self::pairs() as [$status, $days]) {
            // The index stores blanks as '' (SnapshotIndex::load COALESCE).
            $values[] = '(' . $i++ . ", 'PREPAID', " . DuckDb::literal((string) $status) . ', ' . DuckDb::literal((string) $days) . ')';
        }
        $values[] = '(' . $i . ", 'POSTPAID', 'KNOWN', '10')";

        $db   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_recency_' . bin2hex(random_bytes(4)) . '.duckdb';
        try {
            $rows = $duck->query($db, 'SELECT i, ' . PrepaidRecency::sqlCase() . ' AS c FROM (VALUES ' . implode(', ', $values)
                . ') AS t(i, METER, ' . PrepaidRecency::STATUS_COLUMN . ', ' . PrepaidRecency::DAYS_COLUMN . ') ORDER BY i', false);
        } finally {
            @unlink($db);
            @unlink($db . '.wal');
        }

        $i = 0;
        foreach (self::pairs() as $name => [, , $expected]) {
            $this->assertSame($expected, (string) $rows[$i++]['c'], "SQL — {$name}");
        }
        $this->assertSame('', (string) $rows[$i]['c'], 'a POSTPAID row has no class');
    }
}
