<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure file-based unit tests, no database or Oracle connection required.
 * Guards app/Models/Extractor.sql against silent truncation/corruption.
 *
 * The loading mechanism is a plain trim() of the whole file (see the
 * SyncCustomersListQuery migration) — the query was simplified from a
 * multi-CTE segmentation query to a flat SELECT against the
 * pre-materialized CMS_RFC.TB_CUSTOMERS_LIST table, so there is no longer
 * a non-SQL label to strip out. These tests do not (and must not) modify a
 * single token of the query itself.
 *
 * @internal
 */
final class ExtractorSqlIntegrityTest extends CIUnitTestCase
{
    public function testSqlFileExists(): void
    {
        $this->assertFileExists(APPPATH . 'Models/Extractor.sql');
    }

    public function testFileContainsAValidSelectStatement(): void
    {
        $sql = trim(file_get_contents(APPPATH . 'Models/Extractor.sql'));

        $this->assertSame('SELECT', strtoupper(substr($sql, 0, 6)));
    }

    public function testFileIsNotEmptyOrTruncated(): void
    {
        $sql = trim(file_get_contents(APPPATH . 'Models/Extractor.sql'));

        // A truncated/corrupted source file would trip this length guard.
        // Not a fixed value: just enough to catch "file got wiped/cut off",
        // adjusted to the current (much shorter, single-table) query.
        $this->assertGreaterThan(100, strlen($sql));
    }

    public function testLoadingIsDeterministic(): void
    {
        $sqlOnce  = trim(file_get_contents(APPPATH . 'Models/Extractor.sql'));
        $sqlAgain = trim(file_get_contents(APPPATH . 'Models/Extractor.sql'));

        $this->assertSame(md5($sqlOnce), md5($sqlAgain), 'Loading must be deterministic.');
    }
}
