<?php

use App\Services\OracleExtractionService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Oracle as OracleConfig;

/**
 * Pure unit tests for the PHP-vs-Oracle execution-budget rules, no Oracle
 * connection required.
 *
 * Invariant under test (the one whose earlier violation produced "Maximum
 * execution time of 330 seconds exceeded" mid-XLSX-export): a long-running
 * business operation may grant itself a large PHP time budget, and no
 * lower-level step — including the Oracle connect/stream path — may silently
 * reduce it. The shared primitive is
 * OracleExtractionService::ensurePhpTimeLimitAtLeast(), which is raise-only
 * and never overrides an already-unlimited budget.
 *
 * @internal
 */
final class OracleExtractionServiceTimeoutTest extends CIUnitTestCase
{
    private string|false $originalLimit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLimit = ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        if ($this->originalLimit !== false) {
            @set_time_limit((int) $this->originalLimit);
        }
        parent::tearDown();
    }

    public function testPhpTimeLimitFormulaAlwaysExceedsTheOracleTimeout(): void
    {
        $oracleTimeout = (new OracleConfig())->queryTimeoutSeconds;

        $this->assertGreaterThan(
            $oracleTimeout,
            OracleExtractionService::phpTimeLimitFor($oracleTimeout)
        );
    }

    /**
     * @dataProvider oracleTimeoutProvider
     */
    public function testPhpTimeLimitFormulaIsStable(int $oracleTimeoutSeconds): void
    {
        $expected = $oracleTimeoutSeconds + OracleExtractionService::TIMEOUT_MARGIN_SECONDS;

        $this->assertSame($expected, OracleExtractionService::phpTimeLimitFor($oracleTimeoutSeconds));
    }

    public static function oracleTimeoutProvider(): iterable
    {
        yield 'short timeout' => [60];
        yield 'configured default' => [300];
        yield 'very long timeout' => [3600];
    }

    public function testEnsurePhpTimeLimitAtLeastRaisesALowerLimit(): void
    {
        set_time_limit(60);

        OracleExtractionService::ensurePhpTimeLimitAtLeast(400);

        $this->assertSame(400, (int) ini_get('max_execution_time'));
    }

    public function testEnsurePhpTimeLimitAtLeastNeverLowersAHigherLimit(): void
    {
        // A bulk export granting itself a generous budget...
        set_time_limit(1800);

        // ...must survive a lower-level step asking only for the Oracle floor.
        OracleExtractionService::ensurePhpTimeLimitAtLeast(
            OracleExtractionService::phpTimeLimitFor(300) // 330
        );

        $this->assertSame(1800, (int) ini_get('max_execution_time'));
    }

    public function testEnsurePhpTimeLimitAtLeastTreatsZeroAsUnlimitedAndLeavesItAlone(): void
    {
        set_time_limit(0); // 0 == no limit (the CLI default)

        OracleExtractionService::ensurePhpTimeLimitAtLeast(330);

        $this->assertSame(0, (int) ini_get('max_execution_time'));
    }

    public function testEnsurePhpTimeLimitAtLeastIgnoresNonPositiveRequests(): void
    {
        set_time_limit(120);

        OracleExtractionService::ensurePhpTimeLimitAtLeast(0);
        OracleExtractionService::ensurePhpTimeLimitAtLeast(-5);

        $this->assertSame(120, (int) ini_get('max_execution_time'));
    }

    public function testStreamAndRunExposeTheRaiseOnlyPrimitiveTheyRelyOn(): void
    {
        // connect() no longer touches PHP's execution budget at all; run()
        // and stream() instead call this raise-only primitive. Guard that it
        // still exists and is public (removing/renaming it would quietly
        // reopen the door to the 330s regression).
        $this->assertTrue(
            (new ReflectionMethod(OracleExtractionService::class, 'ensurePhpTimeLimitAtLeast'))->isPublic()
        );
    }
}
