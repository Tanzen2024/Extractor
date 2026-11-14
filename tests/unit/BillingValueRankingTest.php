<?php

use App\Services\CustomersList\BillingValue;
use App\Services\Snapshot\DuckDb;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The amount billed over 12 months and the VALUE ranking AS WRITTEN in
 * docs/snapshot/source/customers_list.sql (CTE billing_12m and the
 * VALUE_RANK_BEGIN / VALUE_RANK_END expression), run on DuckDB over invoice
 * and customer fixtures built to hit every rule. Only the Oracle date
 * functions are mapped (TRUNC(SYSDATE,'MM') -> a fixed first day of month,
 * ADD_MONTHS -> a macro); the rules themselves are the deployed text.
 *
 * Real-data figures (Oracle, read-only, 13/11/2026) are in the README; this
 * test proves the rules on the boundaries.
 *
 * @internal
 */
final class BillingValueRankingTest extends CIUnitTestCase
{
    private const MONTH_START = '2026-11-01'; // TRUNC(SYSDATE, 'MM') of the run

    private DuckDb $duck;
    private string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->duck = new DuckDb();
        if ($this->duck->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }
        $this->dbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_value_' . bin2hex(random_bytes(4)) . '.duckdb';
    }

    protected function tearDown(): void
    {
        if (isset($this->dbFile)) {
            @unlink($this->dbFile);
            @unlink($this->dbFile . '.wal');
        }
        parent::tearDown();
    }

    private static function source(): string
    {
        return (string) file_get_contents(ROOTPATH . 'docs/snapshot/source/customers_list.sql');
    }

    /** The billing_12m CTE body, verbatim, with the Oracle date functions mapped. */
    private static function billingCte(): string
    {
        $src = self::source();
        $m   = strpos($src, "\nbilling_12m AS (");
        self::assertNotFalse($m, 'billing_12m CTE present');
        $i     = $m + strlen("\nbilling_12m AS (");
        $depth = 1;
        while ($depth > 0) {
            $c = $src[$i++];
            $depth += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
        }
        $body = substr($src, $m + strlen("\nbilling_12m AS ("), $i - 1 - ($m + strlen("\nbilling_12m AS (")));

        return str_replace("TRUNC(SYSDATE, 'MM')", "DATE '" . self::MONTH_START . "'", $body);
    }

    /** The VALUE_SEGMENT expression, verbatim. */
    private static function rankExpression(): string
    {
        $src   = self::source();
        $begin = strpos($src, '/* VALUE_RANK_BEGIN */');
        $end   = strpos($src, '/* VALUE_RANK_END */');
        self::assertNotFalse($begin);
        self::assertNotFalse($end);

        return substr($src, $begin + strlen('/* VALUE_RANK_BEGIN */'), $end - $begin - strlen('/* VALUE_RANK_BEGIN */'));
    }

    /** @return list<array<string, mixed>> */
    private function sql(string $sql): array
    {
        return $this->duck->query($this->dbFile, "CREATE OR REPLACE MACRO ADD_MONTHS(d, n) AS CAST(CAST(d AS DATE) + to_months(CAST(n AS INTEGER)) AS DATE);\n" . $sql, false);
    }

    // ── AMOUNT_BILLED_12M ───────────────────────────────────────────

    public function testTheAmountSumsTheRetainedBillsOfTheTwelveClosedMonths(): void
    {
        // NUM_REC, NIS_RAD, F_FACT, IMP_TOT_REC, EST_ACT, TIP_REC
        $bills = [
            [1, 100, '2025-11-01', 1000, 'ER310', 'TR010'],   // first day of the window: in
            [2, 100, '2026-10-31', 2000, 'ER020', 'TR010'],   // last day: in
            [3, 100, '2025-10-31', 9000, 'ER310', 'TR010'],   // day before: out
            [4, 100, '2026-11-01', 9000, 'ER310', 'TR010'],   // current month (not closed): out
            [5, 100, '2026-03-15', 5000, 'ER600', 'TR010'],   // CANCELLED: out
            [6, 100, '2026-03-16', 4800, 'ER310', 'TR010'],   // its re-bill: in (once)
            [7, 100, '2026-04-01', 7777, 'ER900', 'TR010'],   // cancelled (duplicate receivable): out
            [8, 100, '2026-05-01', 300, 'ER310', 'TR020'],    // connection fees: in
            [9, 100, '2026-05-02', 200, 'ER200', 'TR030'],    // reconnection: in (status SUSPENDED still billed)
            [10, 100, '2026-05-03', 100, 'ER310', 'TR040'],   // misc charges: in
            [11, 100, '2026-05-04', 50, 'ER310', 'TR050'],    // fraud invoice: in
            [12, 100, '2026-05-05', 99999, 'ER310', 'TR022'], // security deposit: out
            [13, 100, '2026-05-06', 99999, 'ER310', 'TR060'], // installment quota (already billed debt): out
            [14, 100, '2026-05-07', 99999, 'ER310', 'TR071'], // advance payment: out
            [15, 100, '2026-05-08', 99999, 'ER310', 'TR072'], // advance payment (account): out
            [16, 100, '2026-05-09', -150, 'ER010', 'TR010'],  // a negative bill: kept, the amount is a net
            [17, 200, '2026-06-01', 10.4, 'ER310', 'TR010'],  // rounded to whole FCFA
            [18, 200, '2026-06-02', 10.4, 'ER310', 'TR010'],
            [19, 300, '2026-06-01', 5000, 'ER600', 'TR010'],  // only cancelled bills: no row (=> 0 upstream)
        ];
        $values = implode(', ', array_map(static fn (array $b): string => sprintf("(%d, %d, DATE '%s', %s, '%s', '%s')", ...$b), $bills));

        $rows = $this->sql("CREATE SCHEMA IF NOT EXISTS cmsadmin;
            CREATE OR REPLACE TABLE cmsadmin.recibos AS SELECT * FROM (VALUES {$values}) t(NUM_REC, NIS_RAD, F_FACT, IMP_TOT_REC, EST_ACT, TIP_REC);
            SELECT NIS_RAD, AMOUNT_BILLED_12M FROM (" . self::billingCte() . ') ORDER BY NIS_RAD');

        $amounts = array_column($rows, 'AMOUNT_BILLED_12M', 'NIS_RAD');
        $this->assertSame([100, 200], array_map('intval', array_keys($amounts)), 'one row per contract, no row without a retained bill');
        $this->assertEquals(1000 + 2000 + 4800 + 300 + 200 + 100 + 50 - 150, $amounts[100]);
        $this->assertEquals(21, $amounts[200], '20.8 FCFA rounded to whole FCFA');
    }

    public function testTheRetainedTypesAndStatusesAreTheDocumentedOnes(): void
    {
        $cte = self::billingCte();
        $this->assertStringContainsString("r.EST_ACT NOT IN ('ER600', 'ER900')", $cte);
        $this->assertStringContainsString("r.TIP_REC IN ('TR010', 'TR020', 'TR030', 'TR040', 'TR050')", $cte);
        $this->assertStringContainsString('GROUP BY r.NIS_RAD', $cte);
        $this->assertStringContainsString('FROM cmsadmin.recibos r', $cte);
        $this->assertStringContainsString("LEFT JOIN billing_12m b\n    ON b.NIS_RAD = l.CONTRACT", self::source(), 'contract key, one row per contract');
        $this->assertStringContainsString('COALESCE(b.AMOUNT_BILLED_12M, 0) AS AMOUNT_BILLED_12M', self::source(), '0 without a bill, never NULL');
    }

    // ── VALUE_SEGMENT ───────────────────────────────────────────────

    /**
     * @param list<array{0:int, 1:string, 2:?string, 3:int|float|null}> $customers contract, status, METER_PREP, amount
     *
     * @return array<int, ?string> contract => VALUE_SEGMENT
     */
    private function rank(array $customers): array
    {
        $values = implode(', ', array_map(static fn (array $c): string => sprintf(
            "(%d, '%s', %s, %s)",
            $c[0],
            $c[1],
            $c[2] === null ? 'NULL' : "'" . $c[2] . "'",
            $c[3] === null ? 'NULL' : (string) $c[3],
        ), $customers));

        $rows = $this->sql("CREATE OR REPLACE TABLE t AS SELECT * FROM (VALUES {$values}) v(CONTRACT, STATUS, METER_PREP, AMOUNT);
            SELECT l.CONTRACT, " . self::rankExpression() . " AS VALUE_SEGMENT
            FROM (SELECT CONTRACT, STATUS FROM t) l
            JOIN (SELECT CONTRACT, METER_PREP FROM t) p ON p.CONTRACT = l.CONTRACT
            LEFT JOIN (SELECT CONTRACT, AMOUNT AS AMOUNT_BILLED_12M FROM t WHERE AMOUNT IS NOT NULL) b ON b.CONTRACT = l.CONTRACT
            ORDER BY l.CONTRACT");

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['CONTRACT']] = $r['VALUE_SEGMENT'] === null ? null : (string) $r['VALUE_SEGMENT'];
        }

        return $out;
    }

    public function testTenDistinctAmountsGiveTwoFiveThree(): void
    {
        $customers = [];
        foreach (range(1, 10) as $n) {
            $customers[] = [$n, 'ACTIVE', null, $n * 1000]; // contract 10 bills the most
        }
        $v = $this->rank($customers);

        $this->assertSame([BillingValue::TOP, BillingValue::TOP], [$v[10], $v[9]]);
        $this->assertSame(array_fill(0, 5, BillingValue::MEDIUM), [$v[8], $v[7], $v[6], $v[5], $v[4]]);
        $this->assertSame(array_fill(0, 3, BillingValue::BOTTOM), [$v[3], $v[2], $v[1]]);
    }

    public function testEqualAmountsAtAThresholdStayTogetherWhateverTheContractNumber(): void
    {
        // 10 eligible: ranks 1, 2, 2, 2 ... the tie group starts inside Top (rank 2 <= 2): all Top.
        $customers = [[1, 'ACTIVE', null, 900], [2, 'ACTIVE', null, 500], [3, 'ACTIVE', null, 500], [4, 'ACTIVE', null, 500]];
        foreach (range(5, 10) as $n) {
            $customers[] = [$n, 'ACTIVE', null, 100 - $n];
        }
        $v = $this->rank($customers);
        $this->assertSame([BillingValue::TOP, BillingValue::TOP, BillingValue::TOP, BillingValue::TOP], [$v[1], $v[2], $v[3], $v[4]]);

        // Same amounts, contract numbers reversed: same categories.
        $reversed = array_map(static fn (array $c): array => [100 - $c[0], $c[1], $c[2], $c[3]], $customers);
        $w        = $this->rank($reversed);
        foreach ($customers as $c) {
            $this->assertSame($v[$c[0]], $w[100 - $c[0]], 'contract order never decides');
        }

        // A tie group starting just after the Top threshold (rank 3 > 2) is entirely Medium.
        $customers = [[1, 'ACTIVE', null, 900], [2, 'ACTIVE', null, 800], [3, 'ACTIVE', null, 500], [4, 'ACTIVE', null, 500]];
        foreach (range(5, 10) as $n) {
            $customers[] = [$n, 'ACTIVE', null, 100 - $n];
        }
        $v = $this->rank($customers);
        $this->assertSame([BillingValue::TOP, BillingValue::TOP, BillingValue::MEDIUM, BillingValue::MEDIUM], [$v[1], $v[2], $v[3], $v[4]]);
    }

    public function testOnlyActiveBilledCustomersWithAPositiveAmountAreRanked(): void
    {
        $customers = [
            [1, 'ACTIVE', null, 5000],
            [2, 'ACTIVE (PENDING BILLING)', null, 4000],
            [3, 'SUSPENDED (DELINQUENT ACCOUNT)', null, 3000],
            [4, 'INACTIVATION IN PROCESS.', null, 2000],
            [5, 'ACTIVE', null, 1000],
            [6, 'INACTIVE.', null, 999999],            // inactive: not ranked, never pushes anyone down
            [7, 'ACTIVE', 'PREPAID-METER', 999999],    // PREPAID: not ranked
            [8, 'ACTIVE', null, 0],                    // no billing over 12 months
            [9, 'ACTIVE', null, -500],                 // negative net
            [10, 'ACTIVE', null, null],                // no bill at all (no billing_12m row)
        ];
        $v = $this->rank($customers);

        foreach ([6, 7, 8, 9, 10] as $n) {
            $this->assertNull($v[$n], "contract {$n} is not ranked");
        }
        // 5 eligible: Top rank <= 1, Medium rank <= 3.5, Bottom the rest.
        $this->assertSame([BillingValue::TOP, BillingValue::MEDIUM, BillingValue::MEDIUM, BillingValue::BOTTOM, BillingValue::BOTTOM], [$v[1], $v[2], $v[3], $v[4], $v[5]]);
    }

    public function testTheThreeCategoriesCoverTheEligiblePopulationExactlyAndAreOrdered(): void
    {
        mt_srand(20261113);
        $customers = [];
        foreach (range(1, 1000) as $n) {
            // Many ties on purpose: amounts rounded to 100 FCFA.
            $customers[] = [$n, $n % 7 === 0 ? 'INACTIVE.' : 'ACTIVE', $n % 11 === 0 ? 'M' : null, $n % 13 === 0 ? 0 : 100 * mt_rand(1, 400)];
        }
        $v = $this->rank($customers);

        $eligible = array_filter($customers, static fn (array $c): bool => $c[1] === 'ACTIVE' && $c[2] === null && $c[3] > 0);
        $ranked   = array_filter($v, static fn (?string $s): bool => $s !== null);
        $this->assertSame(count($eligible), count($ranked), 'no omission, no extra');
        $this->assertSame([], array_diff(array_unique($ranked), BillingValue::VALUES));

        // Ordered: every Top amount >= every Medium amount >= every Bottom amount; no amount in two categories.
        $byCat = [];
        foreach ($eligible as $c) {
            $byCat[$v[$c[0]]][] = $c[3];
        }
        $this->assertGreaterThanOrEqual(max($byCat[BillingValue::MEDIUM]), min($byCat[BillingValue::TOP]));
        $this->assertGreaterThanOrEqual(max($byCat[BillingValue::BOTTOM]), min($byCat[BillingValue::MEDIUM]));
        $this->assertSame([], array_intersect($byCat[BillingValue::TOP], $byCat[BillingValue::MEDIUM]));
        $this->assertSame([], array_intersect($byCat[BillingValue::MEDIUM], $byCat[BillingValue::BOTTOM]));

        // Shares close to 20 / 50 / 30 % (only the tie groups on a threshold move them).
        $n = count($eligible);
        $this->assertEqualsWithDelta(0.2, count($byCat[BillingValue::TOP]) / $n, 0.02);
        $this->assertEqualsWithDelta(0.5, count($byCat[BillingValue::MEDIUM]) / $n, 0.02);
        $this->assertEqualsWithDelta(0.3, count($byCat[BillingValue::BOTTOM]) / $n, 0.02);
    }
}
