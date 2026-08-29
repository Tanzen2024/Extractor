<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration test against the real local app database (database.default,
 * MySQL) — deliberately not using DatabaseTestTrait/the isolated `tests`
 * DB group, since this specific check is about the actual persisted state
 * the running app reads, not a throwaway fixture.
 *
 * Guards exactly the failure mode this phase-2 audit uncovered: someone
 * edits app/Models/Extractor.sql without re-running a sync migration, so
 * tools.query_definition silently keeps executing a stale query. Neither
 * ExtractorSqlIntegrityTest (file-only) nor the migration itself (runs once,
 * never re-checked) catches that — this test does, on every test run.
 *
 * @internal
 */
final class CustomersListQuerySyncTest extends CIUnitTestCase
{
    public function testStoredQueryMatchesSqlFile(): void
    {
        $fileSql = trim(file_get_contents(APPPATH . 'Models/Extractor.sql'));

        // Explicitly named 'default' group (real app MySQL DB), bypassing
        // Config\Database's ENVIRONMENT==='testing' override that would
        // otherwise silently redirect an unqualified connection/Model to
        // the isolated SQLite `tests` group — this check is only meaningful
        // against the database the running app actually reads.
        $tool = db_connect('default')->table('tools')->where('code', 'CUSTOMERS_LIST')->get()->getRowArray();

        $this->assertNotNull($tool, 'CUSTOMERS_LIST tool must exist in the tools table.');
        $this->assertSame(
            md5($fileSql),
            md5((string) $tool['query_definition']),
            'tools.query_definition has drifted from app/Models/Extractor.sql — run a sync migration ' .
            '(see SyncCustomersListQuery) instead of letting the app execute a stale query.'
        );
    }
}
