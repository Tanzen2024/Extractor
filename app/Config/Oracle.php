<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Dedicated configuration for the Oracle business database (read-only source
 * for extractions). Credentials are read exclusively from environment
 * variables (.env) and must never be hardcoded or logged.
 */
class Oracle extends BaseConfig
{
    /**
     * TNS alias (or full connect descriptor) resolved via tnsnames.ora / TNS_ADMIN.
     */
    public string $dsn = '';

    public string $username = '';

    public string $password = '';

    public string $charset = 'AL32UTF8';

    public int $port = 1521;

    /**
     * Maximum rows fetched for on-screen preview.
     */
    public int $maxDisplayRows = 10000;

    /**
     * Maximum rows allowed in a single export.
     */
    public int $maxExportRows = 100000;

    public int $queryTimeoutSeconds = 60;

    /**
     * Rows fetched per network round-trip to Oracle (OCI8 prefetch). The
     * OCI8 extension's own default is 100, which is fine for small result
     * sets but turns a 10,000-row fetch into ~100 separate round-trips —
     * measured to dominate total extraction time far more than the SQL
     * execution itself on a remote Oracle server. Raising this is a fetch
     * transport tuning, not a change to what data is returned.
     */
    public int $fetchBatchSize = 1000;

    /**
     * OCI8 prefetch used specifically by the bulk CSV/XLSX export stream
     * (CustomerListExportService). The full CUSTOMERS_LIST export walks
     * ~3.28M rows, where the per-round-trip default above would mean
     * thousands of network round-trips to the remote Oracle host; a larger
     * value cuts that count. Bounded — OCI8 buffers this many rows client
     * side per fetch, not the whole result set.
     */
    public int $exportPrefetchRows = 5000;

    /**
     * Upper bound (seconds) on PHP's own execution time for one synchronous
     * export request. Deliberately finite — not 0 — so a genuinely stuck
     * export still fails instead of hanging a PHP worker forever. It is a
     * ceiling for the PHP timer only; a front web server / reverse proxy
     * keeps its own independent request timeout (Apache's default is 300s),
     * which is the real limit for very large synchronous exports and the
     * reason the export pipeline stays decoupled from HTTP for a future
     * background-job runner.
     */
    public int $exportTimeLimitSeconds = 1800;

    /**
     * Maximum data rows per XLSX sheet. Excel itself refuses more than
     * 1,048,576 rows (including the header) on one sheet; CustomerListExportService
     * splits into CustomerList_1, CustomerList_2, ... once this is reached.
     * Kept one below Excel's limit so the header row still fits.
     */
    public int $xlsxMaxRowsPerSheet = 1_048_575;

    /**
     * TTL (seconds) for the dashboard's cached stats/count responses, keyed
     * by the active filter set. The CUSTOMERS_LIST source table is refreshed
     * in bulk on a slow cadence (LAST_VC_DATE is a single frozen value), so a
     * few minutes of staleness is invisible to users while sparing Oracle a
     * full table scan on every repeated view. 0 disables caching.
     */
    public int $dashboardCacheTtl = 600;

    /**
     * TTL (seconds) for the cached filter-options payload (the region ->
     * division -> agence tree plus every distinct STATUS / SEGMENTATION /
     * ... value). These change only when the source table is rebuilt, so
     * this is deliberately long.
     */
    public int $filterOptionsCacheTtl = 3600;

    /**
     * Row-count ceiling for a *synchronous* CUSTOMERS_LIST export. At or
     * below it the file is generated in-request and streamed straight back;
     * above it the request only enqueues an export job (see ExportJobModel /
     * `spark export:process`) because the generation would outlast the front
     * web server's request timeout.
     */
    public int $exportSyncMaxRows = 150_000;

    /**
     * The exact STATUS values that define an "active" customer for the
     * dashboard's "Clients actifs" KPI — the business rule, not a pattern:
     * a customer counts if ANY of their rows carries one of these statuses.
     * Everything else (INACTIVE*, IN PROCESS (PENDING CONNECTION/READING))
     * does not. Verified against the real distinct STATUS values in
     * CMS_RFC.TB_CUSTOMERS_LIST (exact spelling, including the trailing
     * period on "INACTIVATION IN PROCESS.").
     *
     * @var list<string>
     */
    public array $activeStatuses = [
        'ACTIVE',
        'ACTIVE (PENDING BILLING)',
        'INACTIVATION IN PROCESS.',
        'SUSPENDED (DELINQUENT ACCOUNT)',
    ];

    /**
     * METER values that count as "client avec compteur" for that KPI: a
     * meter the customer operates directly (prepaid or communicating),
     * as opposed to a billed POSTPAID account.
     *
     * @var list<string>
     */
    public array $meteredMeterValues = ['PREPAID', 'Compteurs Communicants'];

    /**
     * Hard ceiling on OFFSET for the dashboard data table: past this depth a
     * page request is refused with a "narrow your filters" message rather
     * than making Oracle walk millions of rows to skip them.
     */
    public int $tableMaxOffset = 500_000;

    public function __construct()
    {
        parent::__construct();

        $this->dsn                 = (string) env('database.oracle.dsn', '');
        $this->username            = (string) env('database.oracle.username', '');
        $this->password            = (string) env('database.oracle.password', '');
        $this->charset             = (string) env('database.oracle.charset', 'AL32UTF8');
        $this->maxDisplayRows      = (int) env('extraction.maxDisplayRows', 10000);
        $this->maxExportRows       = (int) env('extraction.maxExportRows', 100000);
        $this->queryTimeoutSeconds = (int) env('extraction.queryTimeoutSeconds', 60);
        $this->fetchBatchSize      = (int) env('extraction.fetchBatchSize', 1000);
        $this->exportPrefetchRows  = (int) env('extraction.exportPrefetchRows', 5000);
        $this->exportTimeLimitSeconds = (int) env('extraction.exportTimeLimitSeconds', 1800);
        $this->xlsxMaxRowsPerSheet = (int) env('extraction.xlsxMaxRowsPerSheet', 1_048_575);
        $this->dashboardCacheTtl      = (int) env('extraction.dashboardCacheTtl', 600);
        $this->filterOptionsCacheTtl  = (int) env('extraction.filterOptionsCacheTtl', 3600);
        $this->exportSyncMaxRows      = (int) env('extraction.exportSyncMaxRows', 150_000);
        $this->tableMaxOffset         = (int) env('extraction.tableMaxOffset', 500_000);
    }

    /**
     * Whether the minimum credentials required to attempt a connection are present.
     */
    public function isConfigured(): bool
    {
        return $this->dsn !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * Builds the connection array expected by CodeIgniter's OCI8 driver.
     *
     * @return array<string, mixed>
     */
    public function connectionArray(): array
    {
        return [
            'DSN'        => $this->dsn,
            'hostname'   => '',
            'username'   => $this->username,
            'password'   => $this->password,
            'database'   => '',
            'DBDriver'   => 'OCI8',
            'DBPrefix'   => '',
            'pConnect'   => false,
            'DBDebug'    => (ENVIRONMENT !== 'production'),
            'charset'    => $this->charset,
            'swapPre'    => '',
            'failover'   => [],
            'port'       => $this->port,
            'dateFormat' => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
        ];
    }
}
